<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {

        Schema::table('phones', function (Blueprint $table) {
            if (Schema::hasColumn('phones', 'scantime')) {
                return;
            }
            $table->integer('scantime')->nullable()->comment('扫描时间');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('phones', function (Blueprint $table) {
            if (Schema::hasColumn('phones', 'scantime')) {
                $table->dropColumn('scantime');
            }
        });
    }
};
