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
            if (Schema::hasColumn('phones', 'department_id')) {
                return; // 如果列已存在，则不执行任何操作
            }
            $table->integer('department_id')->nullable()->comment('部门ID');
            $table->index('department_id');
            $table->index(['creator_id', 'department_id']);
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
            if (Schema::hasColumn('phones', 'department_id')) {
                $table->dropIndex(['department_id']);
                $table->dropIndex(['creator_id', 'department_id']);
                $table->dropColumn('department_id');
            }
        });
    }
};
