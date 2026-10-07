<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 把「原生 TIMESTAMP 列」统一成项目约定的 int Unix 时间戳
 *
 * 背景：本项目 CatchModel 设了 `$dateFormat = 'U'`，且建表宏
 * createdAt()/updatedAt()/deletedAt() 生成的都是 unsigned int。
 * 因此任何 `now()` / Carbon 写入这些模型时，Eloquent 会先经 fromDateTime()
 * 转成 **int Unix 时间戳**再入库。
 *
 * 但下面几列在建表时用的是原生 `$table->timestamp()`（真 TIMESTAMP），
 * 于是写入 int（如 1791372325）会被 MySQL 当成日期字符串解析 →
 * 严格模式下报 1292 Incorrect datetime value，非严格模式下存成 0000-00-00。
 * 这类异常发生在队列任务里，会被当成「发送失败」触发重试，导致重复发送。
 *
 * 统一改为 unsigned int 后：
 *   - 写入：now() → int，类型匹配；
 *   - 读取：模型上已有 'datetime' cast，asDateTime() 能把 int 正确还原成 Carbon。
 *
 * 涉及：
 *   - message_send_logs.sent_at  （群发回执，队列任务高频写入）
 *   - group_members.joined_at / left_at （群成员进退群，队列链路写入）
 */
return new class () extends Migration {
    /**
     * 需要修正的表 => 列
     *
     * @var array<string, array<int, string>>
     */
    private array $columns = [
        'message_send_logs' => ['sent_at'],
        'group_members' => ['joined_at', 'left_at'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $needChange = false;

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // 已经是整型就不用动（保证迁移可重复执行）
                $type = strtolower((string) Schema::getColumnType($table, $column));
                if (in_array($type, ['integer', 'bigint', 'smallint', 'tinyint'], true)) {
                    continue;
                }

                $needChange = true;
            }

            if (! $needChange) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->unsignedInteger($column)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    $blueprint->timestamp($column)->nullable()->change();
                }
            });
        }
    }
};