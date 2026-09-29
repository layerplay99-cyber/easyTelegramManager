<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 群发回执：现在只有 log 文件，后台看不到「哪个群发成功了、哪个失败了」
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_send_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('send_id');
            $table->string('chat_id', 64);
            $table->string('status', 20)->default('pending')->comment('success|failed');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->createdAt();
            $table->updatedAt();

            $table->index(['send_id', 'status'], 'idx_log_send_status');
            $table->engine = 'InnoDB';
            $table->comment('群发回执表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_send_logs');
    }
};
