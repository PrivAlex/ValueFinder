<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание таблицы keychains
     */
    public function up(): void
    {
        Schema::create('keychains', function (Blueprint $table) {

            $table->id();// Первичный ключ (автоинкремент)
            $table->string('name')->unique();// Название брелка (уникальное)
            $table->decimal('average_price', 10, 2);// Средняя цена брелка в долларах
            $table->string('currency', 3)->default('USD');// Валюта (по умолчанию USD)
            $table->string('source')->nullable();// Источник цены (откуда взяли данные)
            $table->timestamps();// Временные метки (created_at, updated_at)
            $table->index('name');// Индекс для быстрого поиска по названию
        });
    }

    /**
     * Удаление таблицы (rollback)
     */
    public function down(): void
    {
        Schema::dropIfExists('keychains');
    }
};
