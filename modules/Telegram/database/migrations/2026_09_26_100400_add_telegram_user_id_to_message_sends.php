<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 群发任务支持客服账号（MadelineProto）通道
 *
 * bot 通道发不了自定义/动态 emoji 时，改用 telegram 客服账号发。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_sends', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_user_id')->nullable()
                ->after('bot_id')
                ->comment('channel=user 时的 telegram 客服账号 id');
        });
    }

    public function down(): void
    {
        Schema::table('message_sends', function (Blueprint $table) {
            $table->dropColumn('telegram_user_id');
        });
    }
};
