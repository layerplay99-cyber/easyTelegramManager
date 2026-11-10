<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\Merchant;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Modules\Telegram\Services\NotificationService;
use Modules\User\Models\User;

/**
 * 服务到期提醒（计划任务，每日 09:00）
 *
 * 在到期前 7 / 3 / 1 天各提醒一次（expire_noticed_at 防止重复推送）：
 *   - 商户级：merchants.expired_at → 该商户名下（tenant 用户）所有启用机器人的启用群
 *   - 用户级：users.expired_at → 该用户名下所有启用机器人的启用群
 *     （商户也设置了有效期的只发商户级，避免双重提醒）
 *
 * 到期当天的拦截由中间件 / UpdateRouter 负责，这里只做提前提醒。
 */
class MerchantExpireRemind extends Command
{
    protected $signature = 'merchant:expire-remind
                            {--dry-run : 只统计将要提醒的对象，不真正发送}';

    protected $description = '服务到期提醒：到期前 7/3/1 天向商户名下机器人群推送续期提醒';

    private const REMIND_DAYS = [7, 3, 1];

    public function handle(BotApiFactory $factory, NotificationService $notice): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $merchants = Merchant::query()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', now())
            ->get()
            ->filter(fn (Merchant $m) => $this->shouldRemind($m->expired_at, $m->expire_noticed_at));

        $users = User::query()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', now())
            ->get()
            // 商户级有效期已覆盖的账号不再单独提醒，避免双重推送
            ->filter(function (User $u) use ($merchants) {
                if ((int) $u->tenant_id !== 0 && $merchants->contains('id', $u->tenant_id)) {
                    return false;
                }

                return $this->shouldRemind($u->expired_at, $u->expire_noticed_at);
            });

        $this->info(sprintf(
            '待提醒：商户 %d 个，用户 %d 个%s',
            $merchants->count(),
            $users->count(),
            $dryRun ? '（dry-run，不发送）' : ''
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($merchants as $merchant) {
            // 商户的机器人 = 该商户（tenant）下所有用户名下的机器人
            $userIds = User::query()->where('tenant_id', $merchant->id)->pluck('id')->all();

            $sent += $this->notifyTargets(
                $factory,
                $notice,
                Bots::query()->whereIn('creator_id', $userIds)->where('enabled', 1)->get(),
                $this->message($merchant->name, $merchant->expired_at)
            );

            $merchant->forceFill(['expire_noticed_at' => now()->toDateString()])->save();
        }

        foreach ($users as $user) {
            $sent += $this->notifyTargets(
                $factory,
                $notice,
                Bots::query()->where('creator_id', $user->id)->where('enabled', 1)->get(),
                $this->message($user->username, $user->expired_at)
            );

            $user->forceFill(['expire_noticed_at' => now()->toDateString()])->save();
        }

        $this->info("提醒发送完成，共 {$sent} 条");

        return self::SUCCESS;
    }

    /**
     * 向一批机器人名下的启用群发送消息，返回实际尝试发送的条数
     */
    private function notifyTargets(BotApiFactory $factory, NotificationService $notice, $bots, string $text): int
    {
        $sent = 0;

        foreach ($bots as $bot) {
            foreach ($bot->botGroups()->where('enabled', 1)->get() as $group) {
                $sent++;
                $notice->sendToTelegram($factory->forBot($bot), (int) $group->chat_id, $text);
            }
        }

        return $sent;
    }

    /**
     * 距到期天数是否在提醒窗口，且今天还没发过
     */
    private function shouldRemind(Carbon $expiredAt, ?Carbon $noticedAt): bool
    {
        if ($noticedAt !== null && $noticedAt->isSameDay(now())) {
            return false;
        }

        return in_array($this->daysLeft($expiredAt), self::REMIND_DAYS, true);
    }

    private function daysLeft(Carbon $expiredAt): int
    {
        return (int) round(now()->startOfDay()->diffInDays($expiredAt->copy()->startOfDay(), false));
    }

    private function message(string $name, Carbon $expiredAt): string
    {
        return sprintf(
            '[服务到期提醒] %s：账号服务将于 %s 到期（剩余 %d 天），到期后机器人功能将暂停使用，请及时联系管理员续期。',
            $name,
            $expiredAt->format('Y-m-d H:i'),
            $this->daysLeft($expiredAt)
        );
    }
}
