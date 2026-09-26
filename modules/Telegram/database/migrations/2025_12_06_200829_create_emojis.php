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

        Schema::create('emojis', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->comment('Emoji Name');
            $table->string('unicode')->nullable()->comment('Emoji Unicode');
            $table->string('image_path')->nullable()->comment('Emoji Image Path');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->comment('Emojis Table');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('emojis');
    }
};
