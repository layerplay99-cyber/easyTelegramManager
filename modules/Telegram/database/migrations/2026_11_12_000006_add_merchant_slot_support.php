<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 固定命令槽位：命令不再全局唯一，实现按商户隔离
 *
 * 商户各自上传插件实现同一批固定命令（/ye /cx …），每商户每命令一个实现、
 * 重复上传覆盖。需要两处结构性改动：
 *
 * 1) features.merchant_id：空 = 全局默认实现（官方插件，全局唯一）；
 *    非空 = 该商户的定制实现。运行时按群的商户号路由。
 * 2) feature_commands 的 uniq_feature_command(command) 唯一索引必须松开：
 *    100 个商户就有 100 条 /ye 命令行，唯一索引会直接挡掉第 2 条。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->string('merchant_id', 64)->nullable()->after('feature');
        });

        Schema::table('feature_commands', function (Blueprint $table) {
            $table->dropUnique('uniq_feature_command');
            $table->index('command', 'idx_fc_command');
        });

        // 命令名统一存无斜杠格式：FeatureCommandDispatcher 按 parseName()（去斜杠小写）
        // 匹配，存量基础功能的行带 '/' 存（如 '/fy'），在分发里永远命中不了；
        // 插件声明的 'ye' 是无斜杠 —— 两种格式并存还会让「命令占用检查」互相看不见。
        // 用 builder 而不是原生 SQL：表名前缀（bm）只有 builder 会自动拼
        DB::table('feature_commands')->update([
            'command' => DB::raw("TRIM(LEADING '/' FROM command)"),
        ]);

        // 规范化可能让同一功能的命令出现新旧两行（'/fy' 与 'fy'），按
        // (command, feature_id) 去重，保留每组里 id 最小的一行
        $duplicateIds = DB::table('feature_commands as a')
            ->join('feature_commands as b', function ($join) {
                $join->on('a.command', '=', 'b.command')
                    ->on('a.feature_id', '=', 'b.feature_id')
                    ->whereColumn('a.id', '>', 'b.id');
            })
            ->pluck('a.id')
            ->all();

        if ($duplicateIds !== []) {
            DB::table('feature_commands')->whereIn('id', $duplicateIds)->delete();
        }
    }

    public function down(): void
    {
        // 回滚前先把同命令的多余行清掉（只留 id 最小的），否则唯一索引建不回去
        DB::statement(
            'DELETE fc1 FROM feature_commands fc1 '
            . 'JOIN feature_commands fc2 ON fc1.command = fc2.command AND fc1.id > fc2.id'
        );

        Schema::table('feature_commands', function (Blueprint $table) {
            $table->dropIndex('idx_fc_command');
            $table->unique('command', 'uniq_feature_command');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('merchant_id');
        });
    }
};
