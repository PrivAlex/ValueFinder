<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ParseSteamSkins extends Command
{
    /**
     * Название и сигнатура команды.
     *
     * @var string
     */
    protected $signature = 'app:parse-steam-skins {--item=}';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Парсинг цен предметов из Steam Market';

    /**
     * Выполнение команды.
     */
    public function handle()
    {
        // Берём предмет из опции или задаём дефолт
        $item = $this->option('item') ?? 'AK-47 | Redline (Field-Tested)';

        // Пока просто выводим
        $this->info("Запускаем парсер для предмета: $item");

        // TODO: тут позже сделаем реальный парсинг Steam Market API
        // Пример запроса будет через Guzzle или curl
    }
}
