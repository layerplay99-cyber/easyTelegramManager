<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * emojis 表升级为「统一素材库」
 *
 * 原来只存 name / unicode / image_path，采集时把 Telegram 的 document_id 直接塞进主键 id，
 * 且代码里写的 png_path 字段根本不存在，导致采集一条都落不了库。
 * 现在：id 回归自增业务主键，document_id 存 telegram_id，并按 (type, telegram_id) 去重。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emojis', function (Blueprint $table) {
            $table->string('type', 20)->default('custom_emoji')
                ->after('id')
                ->comment('unicode|custom_emoji|sticker|message_effect');

            // emoji_id / effect_id / sticker 的 file_unique_id
            $table->string('telegram_id', 64)->nullable()->after('name');

            // 仅 sticker 需要：file_id 只对抓到它的那个 bot / 账号有效
            $table->text('file_id')->nullable()->after('telegram_id');
            $table->string('owner_type', 20)->nullable()->after('file_id')->comment('bot|user');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');

            $table->string('set_name')->nullable()->after('owner_id')->comment('所属表情包');
            $table->json('tags')->nullable()->after('set_name');
            $table->string('source', 20)->default('manual')->after('tags')->comment('collected|uploaded|manual');
            $table->unsignedInteger('usage_count')->default(0)->after('source');

            // 采集去重（MySQL 唯一索引允许多个 NULL，unicode 类型不受影响）
            $table->unique(['type', 'telegram_id'], 'uk_emoji_asset');
            $table->index('type', 'idx_emoji_type');
        });
    }

    public function down(): void
    {
        Schema::table('emojis', function (Blueprint $table) {
            $table->dropUnique('uk_emoji_asset');
            $table->dropIndex('idx_emoji_type');
            $table->dropColumn([
                'type', 'telegram_id', 'file_id', 'owner_type', 'owner_id',
                'set_name', 'tags', 'source', 'usage_count',
            ]);
        });
    }
};
