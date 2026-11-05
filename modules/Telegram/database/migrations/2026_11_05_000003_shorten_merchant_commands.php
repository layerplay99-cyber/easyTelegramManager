<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3 个商户命令改短名，同步修正存量 feature_commands
 *
 * CustomFeatureRegistry::syncCommand() 是 firstOrCreate(['command' => ...])，
 * 只增不改：代码里改名后直接跑 sync，结果会是「旧 3 条还在 + 新 3 条」，
 * 后台命令列表多出一半，旧命令也还能触发。这里把旧行直接改名，
 * 保留它的 id / feature_id / enabled / 后台改过的配置，不删不重建。
 *
 * 若已经跑过 sync 生成了新名字的行，先删掉那条再改旧名，避免撞唯一索引。
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('feature_commands')) {
            return;
        }

        $renames = [
            'yujing' => 'yj',
            'xiane' => 'xe',
            'chaxiane' => 'xq',
        ];

        foreach ($renames as $old => $new) {
            $row = DB::table('feature_commands')->where('command', $old)->first();

            if (! $row) {
                continue;
            }

            DB::table('feature_commands')
                ->where('command', $new)
                ->where('id', '!=', $row->id)
                ->delete();

            $update = ['command' => $new];

            if (Schema::hasColumn('feature_commands', 'usage')) {
                $update['usage'] = str_replace(
                    '/' . $old,
                    '/' . $new,
                    (string) ($row->usage ?? '')
                );
            }

            DB::table('feature_commands')->where('id', $row->id)->update($update);
        }
    }

    public function down(): void
    {
        // 不回滚：短名是代码里 commands() 的当前声明，改回长名会与新代码不一致。
    }
};
