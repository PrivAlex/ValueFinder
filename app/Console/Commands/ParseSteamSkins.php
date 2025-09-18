<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ParseSteamSkins extends Command
{
    /**
     * Название и сигнатура команды.
     *
     * --item="..."    : market_hash_name предмета (можно с пробелами)
     * --notify        : флаг — послать уведомление в Telegram даже если порог не достигнут (для теста)
     */
    protected $signature = 'app:parse-steam-skins {--item=} {--notify}';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Парсинг цены предмета из Steam Market и отправка оповещения в Telegram при выполнении условия';

    public function handle()
    {
        // 1) читаем опции
        $item = $this->option('item') ?? 'AK-47 | Redline (Field-Tested)'; // дефолт
        $forceNotify = $this->option('notify') ?? false;

        $this->info("Запускаем парсер для предмета: {$item}");

        // 2) формируем url priceoverview — быстрый способ узнать цену
        // currency=1 -> USD, appid=730 -> CS:GO/CS2 (в зависимости от маркета)
        $encoded = rawurlencode($item);
        $url = "https://steamcommunity.com/market/priceoverview/?currency=1&appid=730&market_hash_name={$encoded}";

        $this->info("Запрос: {$url}");

        try {
            // 3) делаем запрос с retry, таймаутом и User-Agent (чтобы снизить шанс блокировки)
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
            ])
                ->timeout(10)           // сек
                ->retry(3, 1000)        // retry 3 раза с паузой 1000ms
                ->get($url);

            // 4) проверяем статус
            if (! $response->successful()) {
                $this->error("HTTP ошибка: " . $response->status());
                Log::warning('Steam priceoverview returned non-2xx', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Если 429 (rate limit), можно логировать и рекомендовать паузу
                if ($response->status() === 429) {
                    $this->warn('Получен 429 — возможно rate-limit. Подумай о backoff/увеличении интервала запросов.');
                }

                return Command::FAILURE;
            }

            // 5) декодируем JSON и смотрим поля
            $data = $response->json();

            // Поля в priceoverview: lowest_price, median_price, volume (в зависимости от локали они могут отсутствовать)
            $rawLowest = $data['lowest_price'] ?? null;
            $rawMedian = $data['median_price'] ?? null;

            if (! $rawLowest) {
                $this->warn("Цена (lowest_price) не возвращена для предмета — body: " . $response->body());
                Log::info('priceoverview missing lowest_price', ['url' => $url, 'body' => $response->body()]);
                return Command::SUCCESS;
            }

            // 6) нормализуем цену в float (должно работать для форматов типа "$12.34", "US$12.34", "12,34$" и т.д.)
            $price = $this->normalizePriceString($rawLowest);

            $this->info("Нормализованная цена (USD): {$price}");

            // 7) условие уведомления:
            //    - используем порог из .env (NOTIFY_PRICE_THRESHOLD в USD)
            //    - если --notify задан, то отправляем без проверки (полезно для теста)
            $threshold = (float) env('NOTIFY_PRICE_THRESHOLD', 5); // по умолчанию $5, можно изменить в .env

            if ($forceNotify || $price <= $threshold) {
                $this->info("Условие выполнено (price={$price} <= threshold={$threshold}) или принудительное уведомление.");

                // формируем сообщение:
                $marketUrl = $this->buildMarketUrl($item);
                $message = "💥 <b>Найден интересный лот!</b>\n";
                $message .= "<b>Предмет:</b> {$item}\n";
                $message .= "<b>Цена (lowest):</b> {$rawLowest} ({$price} USD)\n";
                $message .= "<b>Ссылка:</b> {$marketUrl}\n";

                $sent = $this->sendTelegram($message);

                if ($sent) {
                    $this->info("Уведомление отправлено в Telegram.");
                } else {
                    $this->error("Не удалось отправить уведомление в Telegram. Проверь TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID в .env");
                }
            } else {
                $this->info("Условие не выполнено (price={$price} > threshold={$threshold}). Нотификация не отправлена.");
            }

        } catch (\Throwable $e) {
            // 8) общий catch — логируем исключение
            $this->error("Исключение при парсинге: " . $e->getMessage());
            Log::error('ParseSteamSkins exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Нормализует строку с ценой в float (USD).
     *
     * Примеры входа:
     *  - "$12.34"
     *  - "US$ 1,234.56"
     *  - "1 234,56 ₽"
     *  - "12,34"
     */
    protected function normalizePriceString(string $raw): float
    {
        // 1) удаляем нечисловые символы кроме . и , и -
        // оставим цифры, точку, запятую и минус
        $clean = preg_replace('/[^\d\.,-]/u', '', $raw);

        if ($clean === '' || $clean === '-' || $clean === null) {
            return 0.0;
        }

        // 2) если есть и точка, и запятая — решаем, что запятая это тысячный разделитель, удаляем её
        if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
            // например "1,234.56" -> "1234.56"
            $clean = str_replace(',', '', $clean);
            // теперь safe to float
            return floatval($clean);
        }

        // 3) если есть только запятая — заменим её на точку (европейский формат)
        if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
            $clean = str_replace(',', '.', $clean);
            return floatval($clean);
        }

        // 4) если есть только точка — OK
        return floatval($clean);
    }

    /**
     * Формирует ссылку на листинг товара в Steam Market.
     */
    protected function buildMarketUrl(string $marketHashName): string
    {
        $encoded = rawurlencode($marketHashName);
        return "https://steamcommunity.com/market/listings/730/{$encoded}";
    }

    /**
     * Отправляет сообщение в Telegram через Bot API.
     * Возвращает true если успешно (код 200 + ok == true).
     */
    protected function sendTelegram(string $text): bool
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (! $token || ! $chatId) {
            Log::warning('Telegram token or chat id missing', ['token' => (bool)$token, 'chat' => (bool)$chatId]);
            return false;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($response->successful()) {
            $body = $response->json();
            return isset($body['ok']) && $body['ok'] === true;
        }

        Log::error('Telegram send failed', ['status' => $response->status(), 'body' => $response->body()]);
        return false;
    }
}
