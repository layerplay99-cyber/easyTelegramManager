<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 功能绑定改成「机器人 > 群」两级
 *
 * 业务规则：
 *   机器人「配置功能」= 给这个机器人分配可用功能（白名单）
 *   群「配置功能」    = 从这个机器人已分配的功能里挑本群能用哪些
 *   上游不再按绑定逐个选，统一用机器人自己的上游（bots.third_config_id）
 *
 * 数据上要求「(机器人, 群, 功能)」唯一，才能同时存在：
 *   (bot=3, chat=0,     feature=36)  机器人级：白名单，对名下所有群生效
 *   (bot=3, chat=-100…, feature=36)  群级：这个群要不要用
 *
 * 原来用 chat_id IS NULL 表示「实体级默认」，但 MySQL 唯一索引对 NULL 不生效，
 * 只要包含可空列，(NULL, 36) 就能插无数条 —— 所以这里改成 0 表示「未指定群」：
 *   · chat_id = 0  机器人级（等价于原来的 NULL）
 *   · bot_id  = 0  历史脏数据兜底（见下）
 *
 * 顺带处理历史数据：
 *   1) 群级绑定（chat_id 有值）以前 bot_id 是空的，按 bot_groups 回填；
 *      回填不上的说明群已经删了，执行期本来也取不到，直接清掉。
 *      也正因如此，以前「群里配的功能」在机器人链路里根本不生效。
 *   2) 去掉旧的 (chat_id, feature_id) / (bot_id, feature_id) 两个唯一索引：
 *      前者把所有机器人混在一起（两个机器人各自配同一个群会互相顶），
 *      后者直接禁止「一个机器人的同一个功能配给两个群」。
 */
return new class () extends Migration {
    /** 绑定里表示「不针对具体群」的 chat_id */
    private const ENTITY_WIDE_CHAT_ID = 0;

    public function up(): void
    {
        if (! Schema::hasTable('features_binds')) {
            return;
        }

        $table = DB::getTablePrefix() . 'features_binds';
        $groups = DB::getTablePrefix() . 'bot_groups';

        // 1) 先拆旧索引，再动数据
        //
        // 顺序不能反：原来的 feature_bot_unique(bot_id, feature_id) 会直接挡住回填 ——
        // 机器人级已经有 (3, 36)，把群级的 bot_id 补成 3 就撞这条唯一键了。
        // 这条索引本身就是错的：它禁止「一个机器人的同一个功能配给两个群」。
        $this->safeDropUnique('features_binds', 'chat_feature_unique');
        $this->safeDropUnique('features_binds', 'feature_bot_unique');
        $this->safeDropIndex('features_binds', 'idx_features_binds_entity');

        // 2) 群级绑定按 bot_groups 回填 bot_id
        if (Schema::hasTable('bot_groups')) {
            DB::statement(
                "UPDATE {$table} fb
                 JOIN {$groups} bg ON fb.chat_id = bg.chat_id
                 SET fb.bot_id = bg.bot_id
                 WHERE (fb.bot_id IS NULL OR fb.bot_id = 0)
                   AND fb.chat_id <> " . self::ENTITY_WIDE_CHAT_ID
            );
        }

        // 3) NULL 归零
        DB::statement("UPDATE {$table} SET bot_id = 0 WHERE bot_id IS NULL");
        DB::statement("UPDATE {$table} SET chat_id = " . self::ENTITY_WIDE_CHAT_ID . ' WHERE chat_id IS NULL');

        // 4) 清掉归属不上的死数据（原 bot_id 为空且群已不存在）
        DB::statement("DELETE FROM {$table} WHERE bot_id = 0");

        // 5) 去重：同一 (bot, chat, feature) 只留 id 最小那条
        DB::statement(
            "DELETE fb FROM {$table} fb
             JOIN (
                 SELECT bot_id, chat_id, feature_id, MIN(id) AS keep_id
                 FROM {$table}
                 GROUP BY bot_id, chat_id, feature_id
             ) k
               ON fb.bot_id = k.bot_id
              AND fb.chat_id = k.chat_id
              AND fb.feature_id = k.feature_id
             WHERE fb.id > k.keep_id"
        );

        // 6) 新索引：三列唯一，机器人级与群级才能共存
        Schema::table('features_binds', function (Blueprint $table) {
            $table->unique(['bot_id', 'chat_id', 'feature_id'], 'uk_bind_bot_chat_feature');
        });

        // 7) 收口：两列都不再允许 NULL，默认值 0
        DB::statement("ALTER TABLE {$table} MODIFY bot_id BIGINT NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE {$table} MODIFY chat_id BIGINT NOT NULL DEFAULT " . self::ENTITY_WIDE_CHAT_ID);
    }

    public function down(): void
    {
        if (! Schema::hasTable('features_binds')) {
            return;
        }

        $table = DB::getTablePrefix() . 'features_binds';

        $this->safeDropUnique('features_binds', 'uk_bind_bot_chat_feature');

        Schema::table('features_binds', function (Blueprint $table) {
            $table->unique(['chat_id', 'feature_id'], 'chat_feature_unique');
            $table->unique(['bot_id', 'feature_id'], 'feature_bot_unique');
        });

        DB::statement("UPDATE {$table} SET chat_id = NULL WHERE chat_id = " . self::ENTITY_WIDE_CHAT_ID);
        DB::statement("ALTER TABLE {$table} MODIFY bot_id BIGINT NULL DEFAULT NULL");
        DB::statement("ALTER TABLE {$table} MODIFY chat_id BIGINT NULL DEFAULT NULL");
    }

    private function safeDropUnique(string $table, string $index): void
    {
        $this->safeDropIndex($table, $index);
    }

    private function safeDropIndex(string $table, string $index): void
    {
        $prefixed = DB::getTablePrefix() . $table;

        $exists = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$prefixed, $index]
        );

        if ((int) ($exists[0]->c ?? 0) === 0) {
            return;
        }

        DB::statement("ALTER TABLE {$prefixed} DROP INDEX {$index}");
    }
};
