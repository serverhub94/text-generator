<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();

            // Публичный идентификатор: ссылка на прогон отдаётся в браузер,
            // инкрементный id перебирается — uuid нет.
            $table->uuid('uuid')->unique();

            $table->string('status')->default('queued')->index();
            $table->string('mode', 8);

            // Текущая стадия конвейера — её показываем в интерфейсе, пока идёт
            // генерация.
            $table->string('stage')->nullable();

            $table->json('input');

            // Выход каждой стадии: ресёрч, ТЗ, статья, аудит. Держим все,
            // а не только финал — отчёт и ТЗ сами по себе деливеринги.
            $table->json('stages')->nullable();

            $table->longText('article')->nullable();
            $table->longText('error')->nullable();

            $table->json('usage')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);

            // Для лимитов по IP и разбора «кто сжёг бюджет».
            $table->string('ip', 45)->nullable()->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
