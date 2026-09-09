<?php

// database/migrations/xxxx_xx_xx_add_model_to_runs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddModelToRunsTable extends Migration
{
    public function up()
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('model')->nullable()->index()->after('status');
        });
    }

    public function down()
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
}
