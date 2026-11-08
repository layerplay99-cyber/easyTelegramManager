<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 清理 feature_hooks 的 body-sign 验签字段
 *
 * 入站验签已统一走 validate.upstream 头签名（三方配置下发的 access_key/secret_key），
 * hook 层不再按 secret/sign_field/sign_algo/check_timestamp 做 body-sign 验签，
 * 这几个列成了孤儿数据，直接删掉。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('feature_hooks')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                foreach (['secret', 'sign_field', 'sign_algo', 'check_timestamp'] as $col) {
                    if (Schema::hasColumn('feature_hooks', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('feature_hooks')) {
            Schema::table('feature_hooks', function (Blueprint $table) {
                if (! Schema::hasColumn('feature_hooks', 'secret')) {
                    $table->string('secret', 128)->nullable()->comment('回调验签密钥，留空=不验签');
                }

                if (! Schema::hasColumn('feature_hooks', 'sign_field')) {
                    $table->string('sign_field', 32)->nullable()->default('sign')->comment('签名字段名');
                }

                if (! Schema::hasColumn('feature_hooks', 'sign_algo')) {
                    $table->string('sign_algo', 16)->nullable()->default('hmac_sha256')->comment('hmac_sha256 / md5');
                }

                if (! Schema::hasColumn('feature_hooks', 'check_timestamp')) {
                    $table->boolean('check_timestamp')->default(false)->comment('是否校验 timestamp 防重放');
                }
            });
        }
    }
};
