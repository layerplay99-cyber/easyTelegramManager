<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 群级基础能力开关（基础功能不参与功能分配，群编辑里直接开/关）
 *
 * - translate_enabled：开启后群内纯文本消息自动翻译成群语种（/fy start|stop 也能切）
 * - ocr_enabled：开启后群内图片自动走识图（OCR），识别文本拼串提交上游
 * - notify_upstream：开启后接收上游业务通知；要求群已绑定商户号，且只收该商户的
 * - service_keywords：客服关键词，命中关键词的消息 @ 已设置的客服
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('group_configs', function (Blueprint $table) {
            $table->boolean('translate_enabled')->default(false)->comment('群内文本自动翻译开关');
            $table->boolean('ocr_enabled')->default(false)->comment('群内图片自动识图开关');
            $table->boolean('notify_upstream')->default(false)->comment('上游业务通知开关（需绑定商户号）');
            $table->json('service_keywords')->nullable()->comment('客服关键词，命中后 @ 客服');
        });

        // 兼容：升级前已配好的群继续收业务通知（新开关默认关闭会中断现网投递），
        // 新群默认关闭，需在群编辑里显式打开
        DB::table('group_configs')->update(['notify_upstream' => true]);
    }

    public function down(): void
    {
        Schema::table('group_configs', function (Blueprint $table) {
            $table->dropColumn(['translate_enabled', 'ocr_enabled', 'notify_upstream', 'service_keywords']);
        });
    }
};
