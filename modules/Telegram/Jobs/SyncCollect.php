<?php

namespace Modules\Telegram\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Telegram\Events\ScanCountUpdatedEvent;
use Modules\Telegram\Models\Phones;
use Modules\Telegram\Models\Scanlogs;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Madeline\MadelineService;
use Modules\Telegram\Services\User\UserApiFactory;

class SyncCollect implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected TelegramApiUsers $tUsers;
    protected int $days;

    /**
     * Create a new job instance.
     *
     * 注意：原来把 MadelineService 实例直接传进来 —— 它内部持有 MadelineProto
     * 的连接/会话对象，无法被队列（Redis）序列化，job 一 dispatch 就抛
     * "Serialization of 'MadelineProto...' is not allowed"，永远进不了队列。
     * 这里只传可序列化的 TelegramApiUsers + days，MadelineService 在 handle() 里现建。
     */
    public function __construct(TelegramApiUsers $tUsers, int $days)
    {
        $this->tUsers = $tUsers;
        $this->days = $days;
    }

    /**
     * Execute the job.
     */
    public function handle(LogMessageService $logMessageService): void
    {
        // 每日配额上限（与 tUsers 的扫描计数语义一致）
        $dailyLimit = 50;

        $phone = null;

        try {
            if (! $this->tUsers->session_file) {
                return;
            }

            $madelineService = app(UserApiFactory::class)->forTelegramUser($this->tUsers);

            $protoAPI = $madelineService->getApi();

            if ($this->tUsers->scan_date != today()->toDateString()) {
                $this->tUsers->scan_count = 0;
                $this->tUsers->scan_date = today()->toDateString();
                $this->tUsers->save();
            }

            // 真正按剩余配额限制本次扫描条数：原来只做了「remaining<=0 直接 return」，
            // 但循环内用 limit(50) 把待处理号码全扫了，配额的 50/天 形同虚设。
            $remaining = max(0, $dailyLimit - $this->tUsers->scan_count);
            if ($remaining <= 0) {
                return;
            }

            $phones = Phones::where('status', 'pending')->limit($remaining)->get();

            foreach ($phones as $phone) {
                try {
                    $phone->status = 'processing';
                    $phone->save();

                    $user = $protoAPI->contacts->resolvePhone($phone->phone);

                    if (! empty($user['user'])) {
                        $u = $user['user'];

                        $avatarPath = null;
                        if (! empty($u['photo'])) {
                            try {
                                $photo = $u['photo'];
                                $filePath = 'telegram/avatars/' . $u['id'] . '.jpg';
                                $protoAPI->downloadToFile($photo, $filePath);
                                $avatarPath = $filePath;
                            } catch (\Throwable $e) {
                                $avatarPath = null;
                            }
                        }

                        Scanlogs::query()->updateOrCreate(
                            ['tuser_id' => $u['id']],
                            [
                                'phone' => $phone->phone,
                                'days' => $this->days,
                                'first_name' => $u['first_name'] ?? null,
                                'last_name' => $u['last_name'] ?? null,
                                'username' => $u['username'] ?? null,
                                'bio' => $u['about'] ?? null,
                                'avatar_path' => $avatarPath,
                                'is_active' => ($u['status']['was_online'] ?? false) ? true : false,
                            ]
                        );

                        // 更新扫描状态和用户的扫描计数（受 dailyLimit 约束）
                        $this->tUsers->scan_count += 1;
                        $this->tUsers->save();
                        $phone->status = 'scanned';
                        $phone->save();

                        broadcast(new ScanCountUpdatedEvent($this->tUsers));
                    } else {
                        $phone->status = 'failed';
                        $phone->save();
                    }
                } catch (\Throwable $e) {
                    $phone->status = 'failed';
                    $phone->save();

                    $logMessageService->createLaravelLog(
                        'sync_collect',
                        ['phone' => $phone->phone, 'error' => $e->getMessage()],
                        'SyncCollect 单条采集失败',
                        'warning'
                    );
                }
            }
        } catch (\Throwable $e) {
            $logMessageService->createLaravelLog(
                'sync_collect',
                ['tuser_id' => $this->tUsers->id ?? null, 'error' => $e->getMessage()],
                'SyncCollect 任务失败',
                'error'
            );

            // 不让任务因不可序列化/连接失败而静默丢弃，交还给队列重试
            throw $e;
        }
    }
}
