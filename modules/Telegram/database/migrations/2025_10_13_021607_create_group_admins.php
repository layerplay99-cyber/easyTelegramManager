<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_admins', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->index()->comment('群ID');
            $table->string('user_id')->index()->comment('管理员用户ID');
            $table->string('username')->nullable()->comment('用户名');
            $table->string('status', 50)->comment('管理员状态，例如 administrator');
            $table->boolean('can_delete_messages')->default(false);
            $table->boolean('can_invite_users')->default(false);
            $table->boolean('can_promote_members')->default(false);

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();
            $table->unique(['chat_id','user_id'], 'chat_admin_unique');

            $table->engine = 'InnoDB';
            $table->comment('群管理员表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_admins');
    }
};
