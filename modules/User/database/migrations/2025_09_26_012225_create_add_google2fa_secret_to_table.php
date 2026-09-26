<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_secret')->nullable()->comment('2FA密钥');
            $table->string('two_factor_secret_temp')->nullable()->comment('临时2FA密钥');
            $table->timestamp('two_factor_enabled_at')->nullable()->comment('2FA启用时间');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_secret_temp', 'two_factor_enabled_at']);
        });
    }
};
