<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * features.category 原本是 enum('system','custom')，写入 'base' 会
 * 报 1265 Data truncated。基础功能（category = base）需要新分类，
 * 改成 varchar 开放取值，避免以后每加一类功能就要改 enum。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('category', 32)->default('custom')->change();
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('category', 32)->default('custom')->change();
        });
    }
};
