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
    public function up()
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            // 不用 morphs()：它自动生成的索引名 = {表前缀}{表名}_{列1}_{列2}_index，
            // 表前缀较长时（如 bot_dayang_）会超过 MySQL 索引名 64 字符上限，
            // 报 SQLSTATE[42000] 1059 Identifier name ... is too long。
            // 改为显式列名 + 短索引名，避免拼上前缀后超限。
            $table->unsignedBigInteger('tokenable_id');
            $table->string('tokenable_type');
            $table->index(['tokenable_type', 'tokenable_id'], 'pat_tokenable_index');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
