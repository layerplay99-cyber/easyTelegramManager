<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 bot_groups 补 app_id 列 + 高频查询索引。
 *
 * 背景：create_bot_groups 迁移里没有 app_id 列，但以下代码都在用它：
 *   - BotGroups::$fillable / $searchable / $fields / $form（含 'app_id'）
 *   - BotGroups::telegramApiUser() 关联（app_id → telegram_api_users.id）
 *   - BotGroupsController::index() 的 where('app_id', ...)
 *   - MadelineService::syncForGroups() 的 updateOrCreate(['app_id' => ...])
 *   - 前端 botGroups/index.vue 的 appId 列表列
 * 线上库大概率是手工 ALTER 出来的，导致迁移与实际结构不同步，
 * 重新部署到新环境时这些查询会直接报 Unknown column。
 *
 * 本迁移幂等：列/索引已存在则跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_groups')) {
            return;
        }

        Schema::table('bot_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('bot_groups', 'app_id')) {
                // 放在 bot_id 之后（MySQL 支持 AFTER）
                $table->string('app_id')->nullable()->comment('所属 Telegram API 用户 ID')->after('bot_id');
            }
        });

        Schema::table('bot_groups', function (Blueprint $table) {
            $candidates = [
                ['chat_id', 'idx_bot_groups_chat_id'],
                ['bot_id', 'idx_bot_groups_bot_id'],
                ['app_id', 'idx_bot_groups_app_id'],
                ['group_id', 'idx_bot_groups_group_id'],
                [['bot_id', 'group_id'], 'idx_bot_groups_bot_group'],
            ];

            foreach ($candidates as [$columns, $indexName]) {
                $columns = (array) $columns;

                foreach ($columns as $column) {
                    if (! Schema::hasColumn('bot_groups', $column)) {
                        continue 2;
                    }
                }

                if ($this->indexExists('bot_groups', $indexName)) {
                    continue;
                }

                $table->index($columns, $indexName);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_groups')) {
            return;
        }

        Schema::table('bot_groups', function (Blueprint $table) {
            foreach (['idx_bot_groups_chat_id', 'idx_bot_groups_bot_id', 'idx_bot_groups_app_id', 'idx_bot_groups_group_id', 'idx_bot_groups_bot_group'] as $indexName) {
                if ($this->indexExists('bot_groups', $indexName)) {
                    $table->dropIndex($indexName);
                }
            }

            if (Schema::hasColumn('bot_groups', 'app_id')) {
                $table->dropColumn('app_id');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
