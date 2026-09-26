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

        Schema::create('telegram_api_users', function (Blueprint $table) {
            $table->id()->unique()->comment('id');

            $table->string('app_id')->comment('APP ID');
            $table->string('app_hash')->comment('APP Hash');
            $table->string('phone_number')->nullable()->comment('Phone Number');
            $table->string('nickname')->nullable()->comment('Nickname');
            $table->integer('login_status')->default(0)->comment('Login Status');
            $table->string('session_file')->nullable()->comment('Session File Path');
            $table->integer('status')->default(1)->comment('Status');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('Telegram API Users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {

    }
};
