<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * log_operate 补 updated_at
 *
 * 建表迁移里只有 createdAt() 没有 updatedAt()，而 Modules\User\Models\LogOperate
 * 未关闭 $timestamps（Eloquent 默认开启），于是 save() / storeBy() 会尝试写
 * updated_at，抛「Unknown column 'updated_at' in 'field list'」。
 *
 * 操作日志是每个写操作都会走的路径，属于必然报错，补齐即可。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('log_operate')) {
            return;
        }

        if (! Schema::hasColumn('log_operate', 'updated_at')) {
            Schema::table('log_operate', function (Blueprint $table) {
                $table->unsignedInteger('updated_at')->default(0)->comment('Unix 时间戳');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('log_operate') && Schema::hasColumn('log_operate', 'updated_at')) {
            Schema::table('log_operate', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
};