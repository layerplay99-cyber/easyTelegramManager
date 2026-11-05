<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 清掉 3 个老商户命令里遗留的中文默认模板
 *
 * bd / ye / trsq 首次同步时把 commands()['reply_template'] 写进了
 * feature_commands.reply_template。运行期读的一直是 features.config.reply_template，
 * 这一列只是后台命令表单里的默认值；留着中文会让人以为模板还能配，
 * 而这 3 个功能现在默认按群语种出文案（要固定文案请改 features.config）。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('feature_commands')
            || ! Schema::hasColumn('feature_commands', 'reply_template')) {
            return;
        }

        DB::table('feature_commands')
            ->whereIn('command', ['bd', 'ye', 'trsq'])
            ->update(['reply_template' => null]);
    }

    public function down(): void
    {
        // 不回滚：原值是代码里旧的中文默认模板，恢复没有意义。
    }
};
