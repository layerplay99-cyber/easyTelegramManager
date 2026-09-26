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

        Schema::table('scanlogs', function (Blueprint $table) {
            if (Schema::hasColumn('scanlogs', 'status')) {
                return;
            }
            //添加枚举类型
            $table->enum('status', ['unregister', 'privacy', 'success', 'failed'])->default('success')->comment('状态');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('scanlogs', function (Blueprint $table) {
            if (Schema::hasColumn('scanlogs', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
