<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * message_send_logs 补 deleted_at
 *
 * MessageSendLog 继承 CatchModel（带 SoftDeletes 全局作用域），所有查询/更新都会自动
 * 附加 `deleted_at = 0` 条件。但建表迁移里没有这一列，于是回执行/更新回执时必然抛
 * 「Unknown column 'deleted_at' in 'where clause'」。
 *
 * 连带后果：BotSendMsgToGroup 里「发消息 → 写回执」写在同一个 try 中，回执抛异常会被
 * 当成发送失败并 throw 重试，导致同一条消息被连发 3 次（tries=3）。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('message_send_logs', 'deleted_at')) {
            Schema::table('message_send_logs', function (Blueprint $table) {
                $table->unsignedInteger('deleted_at')->default(0)->comment('0=未删除');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_send_logs', 'deleted_at')) {
            Schema::table('message_send_logs', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};