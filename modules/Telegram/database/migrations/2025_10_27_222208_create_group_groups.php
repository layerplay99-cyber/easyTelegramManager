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
        Schema::create('group_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('分组名称');
            $table->text('description')->nullable()->comment('分组描述');

            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();
            $table->engine = 'InnoDB';
            $table->comment('群分组表');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('group_groups');
    }
};
