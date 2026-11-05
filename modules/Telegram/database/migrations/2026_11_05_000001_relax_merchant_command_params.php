<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 商户类 4 个命令的参数校验收回 Driver 内部
 *
 * FeatureExecutor::validateParams() 对 required / pattern 产生的
 * 「缺少参数：xxx」「参数格式错误：xxx」是写死中文，且会走双语追加，
 * 与新加的「按群语种单语回复」不一致。现在校验放在各自 handle() 里，
 * 错误文案取自 lang/{locale}/merchant.php。
 *
 * 但 CustomFeatureRegistry::syncCommand() 对已有命令是 firstOrCreate、
 * 不会覆盖后台（或旧版本）写进去的 params，所以这里把存量的
 * required / pattern 清掉，name / desc 保留。
 * 顺带清掉首次同步写进 feature_commands.reply_template 的中文模板：
 * 代码已不再读它，留着只会让后台以为模板还能配。
 * 新装环境本来就是干净的，这条迁移自然 no-op。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('feature_commands')) {
            return;
        }

        foreach (['cx', 'yujing', 'xiane', 'chaxiane'] as $command) {
            $row = DB::table('feature_commands')->where('command', $command)->first();

            if (! $row) {
                continue;
            }

            $params = is_string($row->params)
                ? json_decode($row->params, true)
                : (array) $row->params;

            if (! is_array($params)) {
                continue;
            }

            $update = [];

            foreach ($params as $i => $param) {
                if (! is_array($param)) {
                    continue;
                }

                if (array_key_exists('required', $param) || array_key_exists('pattern', $param)) {
                    unset($params[$i]['required'], $params[$i]['pattern']);
                    $update['params'] = json_encode(array_values($params), JSON_UNESCAPED_UNICODE);
                }
            }

            if (Schema::hasColumn('feature_commands', 'reply_template')
                && trim((string) ($row->reply_template ?? '')) !== '') {
                $update['reply_template'] = null;
            }

            if ($update !== []) {
                DB::table('feature_commands')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // 不回滚：required/pattern 由代码里的 commands() 声明决定，
        // 恢复旧值反而会让新装环境和存量环境行为不一致。
    }
};
