<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;

class ParseSteamSkins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:parse-steam-skins {item}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Парсит цены скинов из Steam и отправляет уведомления в Telegram';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Получаем название предмета из аргумента
        $itemName = $this->argument('item');

        $this->info("🚀 Запускаем парсер для предмета: $itemName");

        // Заглушка: пока берём случайную цену от 1 до 10
        $itemPrice = rand(1, 10);

        // Читаем порог из .env (если нет — по умолчанию 5)
        $threshold = env('NOTIFY_PRICE_THRESHOLD', 5);

        // Проверяем условие
        if ($itemPrice <= $threshold) {
            $this->sendTelegramMessage("Нашёл предмет: {$itemName} за {$itemPrice} USD!");
            $this->info("✅ Уведомление отправлено в Telegram");
        } else {
            $this->info("❌ Цена {$itemPrice} выше порога ({$threshold} USD). Сообщение не отправлено.");
        }
    }

    /**
     * Отправка сообщения в Telegram
     */
    private function sendTelegramMessage($text)
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (!$token || !$chatId) {
            $this->error("❌ Не найден TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID в .env");
            return;
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        $client = new Client();
        try {
            $client->post($url, [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text'    => $text,
                ],
            ]);
        } catch (\Exception $e) {
            $this->error("❌ Ошибка отправки в Telegram: " . $e->getMessage());
        }
    }
}
