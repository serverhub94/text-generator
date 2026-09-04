<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Статья теперь хранится как чистый HTML-фрагмент. Title, meta description,
     * URL и служебный блок для редактора в разметку не входят — им нужен свой
     * столбец, иначе они либо уедут в текст, либо потеряются.
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->json('article_meta')->nullable()->after('article');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('article_meta');
        });
    }
};
