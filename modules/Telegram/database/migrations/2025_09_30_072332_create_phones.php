<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('phones')) {
            return;
        }

        Schema::create('phones', function (Blueprint $table) {
            $table->id()->unique();
            $table->string('phone')->nullable()->unique()->comment('手机号');
            $table->string('status')->default('pending')->comment('状态');
            $table->creatorId();
            $table->createdAt();
            $table->updatedAt();
            $table->deletedAt();

            $table->engine = 'InnoDB';
            $table->index(['phone', 'status', 'id'], 'idx_phone_status_id');
            $table->comment('phones');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('phones');
    }
};
