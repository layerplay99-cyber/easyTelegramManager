<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\GroupMembers;
use Modules\Telegram\Models\Member;
use Modules\Telegram\Models\RechargeOrder;
use Modules\Telegram\Models\Wallet;
use Modules\Telegram\Models\WithdrawOrder;

/**
 * 回填历史数据的归属人（creator_id）
 *
 * 背景：部门数据隔离是按 creator_id 过滤的，而 catch.data_scope_include_unowned=true
 * 意味着 creator_id = 0 的「无主数据」对所有后台用户可见。历史上：
 *   - bot_groups 由机器人事件同步落库时不写 creator_id → 0；
 *   - members / wallets 由机器人指令链路创建时同样不写 → 0；
 *   - 早期直接插库的机器人（如 smoke 数据）也没有归属人。
 * 结果是部门管理员能看到超管机器人的群、以及全平台的会员/钱包。
 *
 * 本命令把能确定关系的无主数据补上归属人，让新上的数据范围对存量数据同样生效：
 *   1. bot_groups.creator_id ← 所属机器人的 creator_id（确定关系）
 *   2. members.creator_id   ← 该会员出现过的群 → 群所属机器人的 creator_id（启发式）
 *   3. wallets.creator_id   ← 所属会员的 creator_id
 *   4. bots.creator_id      ← 无法自动推断，只列出；需要时用 --owner 指定归属人
 *
 * 只处理 creator_id = 0 的行，已归属的不会被改写，可重复执行。
 *
 * 说明：这里刻意不用 DB::table('a as x')->join(...) 的写法 —— 项目的表前缀
 * 会被 Laravel 一并加到别名上（'members as m' → `bmmembers` as `bmm`），
 * 手写的别名 SQL 会直接报 Unknown column。改用模型自带的表名分段查询。
 */
class BackfillDataOwnership extends Command
{
    protected $signature = 'telegram:backfill-ownership
        {--dry-run : 只统计将要改写的行数，不落库}
        {--groups-only : 只回填 bot_groups}
        {--owner= : 把无主机器人（bots.creator_id=0）指派给指定用户 ID}';

    protected $description = '回填 bot_groups / members / wallets / bots 的 creator_id（修复部门数据隔离对存量数据不生效）';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $groupsOnly = (bool) $this->option('groups-only');

        if ($dry) {
            $this->warn('dry-run 模式：只统计，不写库');
        }

        $groups = $this->backfillGroups($dry);
        $this->info(sprintf('bot_groups：%d 行%s', $groups, $dry ? '待回填' : '已回填'));

        if (! $groupsOnly) {
            $members = $this->backfillMembers($dry);
            $this->info(sprintf('members：%d 行%s', $members, $dry ? '待回填' : '已回填'));

            $wallets = $this->backfillWallets($dry);
            $this->info(sprintf('wallets：%d 行%s', $wallets, $dry ? '待回填' : '已回填'));

            $orders = $this->backfillOrders($dry);
            $this->info(sprintf('recharge_orders + withdraw_orders：%d 行%s', $orders, $dry ? '待回填' : '已回填'));
        }

        $this->backfillBots($dry, $this->option('owner'));

        // 群配置缓存是按 chat_id 建的，归属改了不影响内容，但顺手刷新保持一致
        if (! $dry && $groups > 0) {
            BotGroups::refreshCache();
            $this->line('已刷新 group_configs 缓存');
        }

        return self::SUCCESS;
    }

    /**
     * bot_groups.creator_id ← bots.creator_id
     *
     * 确定关系：群是跟着机器人同步下来的，机器人归属谁，群就归属谁。
     */
    protected function backfillGroups(bool $dry): int
    {
        $affected = 0;

        BotGroups::query()->where('creator_id', 0)->whereNotNull('bot_id')
            ->chunkById(500, function ($groups) use ($dry, &$affected) {
                $owners = Bots::query()
                    ->whereIn('id', $groups->pluck('bot_id')->unique()->all())
                    ->pluck('creator_id', 'id');

                foreach ($groups as $group) {
                    $owner = (int) ($owners[$group->bot_id] ?? 0);

                    if ($owner <= 0) {
                        continue;
                    }

                    $affected++;

                    if (! $dry) {
                        $group->forceFill(['creator_id' => $owner])->save();
                    }
                }
            });

        return $affected;
    }

    /**
     * members.creator_id ← 该会员出现过的群所属机器人的 creator_id
     *
     * 会员表本身没有 bot_id，只能通过 group_members（user_id + chat_id）
     * 反查它是在哪个机器人的群里被发现的，取能查到的最新一条归属人。
     * 反查不到的保持 0（不动），避免瞎猜导致数据错配。
     */
    protected function backfillMembers(bool $dry): int
    {
        $affected = 0;

        Member::query()->where('creator_id', 0)
            ->chunkById(500, function ($members) use ($dry, &$affected) {
                $telegramIds = $members->pluck('telegram_user_id')->unique()->all();

                // telegram_user_id => [chat_id, ...]
                $chatIdsByUser = GroupMembers::query()
                    ->whereIn('user_id', $telegramIds)
                    ->get(['user_id', 'chat_id'])
                    ->groupBy(fn ($row) => (string) $row->user_id)
                    ->map(fn ($rows) => $rows->pluck('chat_id')->unique()->values()->all())
                    ->all();

                if (empty($chatIdsByUser)) {
                    return;
                }

                $chatIds = collect($chatIdsByUser)->flatten()->unique()->values()->all();

                // chat_id => bot_id
                $botIdByChat = BotGroups::query()
                    ->whereIn('chat_id', $chatIds)
                    ->pluck('bot_id', 'chat_id');

                // bot_id => creator_id
                $ownerByBot = Bots::query()
                    ->whereIn('id', $botIdByChat->unique()->filter()->all())
                    ->pluck('creator_id', 'id');

                foreach ($members as $member) {
                    $owner = 0;

                    foreach ($chatIdsByUser[(string) $member->telegram_user_id] ?? [] as $chatId) {
                        $botId = $botIdByChat[$chatId] ?? null;
                        $owner = (int) ($ownerByBot[$botId] ?? 0);

                        if ($owner > 0) {
                            break;
                        }
                    }

                    if ($owner <= 0) {
                        continue;
                    }

                    $affected++;

                    if (! $dry) {
                        $member->forceFill(['creator_id' => $owner])->save();
                    }
                }
            });

        return $affected;
    }

    /**
     * wallets.creator_id ← members.creator_id
     */
    protected function backfillWallets(bool $dry): int
    {
        $affected = 0;

        Wallet::query()->where('creator_id', 0)
            ->chunkById(500, function ($wallets) use ($dry, &$affected) {
                $owners = Member::query()
                    ->whereIn('id', $wallets->pluck('member_id')->unique()->all())
                    ->pluck('creator_id', 'id');

                foreach ($wallets as $wallet) {
                    $owner = (int) ($owners[$wallet->member_id] ?? 0);

                    if ($owner <= 0) {
                        continue;
                    }

                    $affected++;

                    if (! $dry) {
                        $wallet->forceFill(['creator_id' => $owner])->save();
                    }
                }
            });

        return $affected;
    }

    /**
     * 充值/提现订单的 creator_id ← members.creator_id
     *
     * 订单的 fillable 里没有 creator_id，历史上下单链路也没写，
     * 全是 creator_id=0 的无主数据（对所有后台管理员可见）。
     */
    protected function backfillOrders(bool $dry): int
    {
        $affected = 0;

        foreach ([RechargeOrder::class, WithdrawOrder::class] as $orderModel) {
            $orderModel::query()->where('creator_id', 0)
                ->chunkById(500, function ($orders) use ($dry, &$affected) {
                    $owners = Member::query()
                        ->whereIn('id', $orders->pluck('member_id')->unique()->all())
                        ->pluck('creator_id', 'id');

                    foreach ($orders as $order) {
                        $owner = (int) ($owners[$order->member_id] ?? 0);

                        if ($owner <= 0) {
                            continue;
                        }

                        $affected++;

                        if (! $dry) {
                            $order->forceFill(['creator_id' => $owner])->save();
                        }
                    }
                });
        }

        return $affected;
    }

    /**
     * 无主机器人：没有可推断的来源，只能人工指派。
     *
     * 不传 --owner 时只列出来提醒；传了就把它们指派给该用户。
     */
    protected function backfillBots(bool $dry, mixed $ownerOption): void
    {
        $unowned = Bots::query()->where('creator_id', 0)->get(['id', 'username']);

        if ($unowned->isEmpty()) {
            return;
        }

        $ownerId = (int) $ownerOption;

        if ($ownerId > 0) {
            $affected = 0;

            foreach ($unowned as $bot) {
                if ($dry) {
                    $affected++;
                    continue;
                }

                $bot->forceFill(['creator_id' => $ownerId])->save();
                $affected++;
            }

            $this->info(sprintf(
                'bots：%d 个无主机器人%s指派给用户 #%d',
                $affected,
                $dry ? '待' : '已',
                $ownerId
            ));

            return;
        }

        $this->warn(sprintf(
            'bots：%d 个无主机器人无法自动推断归属（会对所有后台用户可见），请用 --owner=<用户ID> 指派：%s',
            $unowned->count(),
            $unowned->pluck('username')->implode(', ')
        ));
    }
}
