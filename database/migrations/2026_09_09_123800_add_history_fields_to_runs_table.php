<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGES (migration):
 * - Добавлены столбцы target_query (varchar 200) и session_id (string 64), оба индексированы.
 * - Добавлен индекс на created_at для ускорения сортировки/очистки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            if (!Schema::hasColumn('runs', 'target_query')) {
                $table->string('target_query', 200)->nullable()->index()->after('model');
            }
            if (!Schema::hasColumn('runs', 'session_id')) {
                $table->string('session_id', 64)->nullable()->index()->after('target_query');
            }
            // Индекс на created_at
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            if (Schema::hasColumn('runs', 'target_query')) {
                $table->dropIndex(['target_query']);
                $table->dropColumn('target_query');
            }
            if (Schema::hasColumn('runs', 'session_id')) {
                $table->dropIndex(['session_id']);
                $table->dropColumn('session_id');
            }
            $table->dropIndex(['created_at']);
        });
    }
};
