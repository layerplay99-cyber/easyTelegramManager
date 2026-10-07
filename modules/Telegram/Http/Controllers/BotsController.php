<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Telegram\Http\Requests\BotsRequest;
use Modules\Telegram\Jobs\BotSendMsgToGroup;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\MessageSend;
use Modules\Telegram\Models\MessageSendLog;
use Modules\Telegram\Models\MessageTemplate;
use Modules\Telegram\Models\TelegramApiUsers;
use Modules\Telegram\Jobs\TelegramApiOperateFeatureJob;
use Modules\Telegram\Services\BaseService;
use Modules\Telegram\Services\LogMessageService;
use Modules\Telegram\Services\Message\MessageRenderer;


class BotsController extends Controller
{
    /**
     * 每秒最多投递多少个发送任务。
     * Telegram 官方限制约为 30 条/秒（同一 bot），这里保守取 20。
     */
    private const DISPATCH_PER_SECOND = 20;

    /**
     * 客服账号（真人账号）每秒最多发多少条：发太快会被 Telegram 限制甚至封号
     */
    private const USER_DISPATCH_PER_SECOND = 1;

    public function __construct(
        protected readonly Bots $model,
        protected readonly BotGroups $botGroupsModel,
        protected readonly BaseService $baseService,
    ) {}

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        // 数据范围由 Bots 模型的 DataRange trait 自动生效
        return $this->model->getList();
    }

    /**
     * @param BotsRequest $request
     * @return mixed
     */
    public function store(BotsRequest $request): mixed
    {
        return $this->model->storeBy($request->all());
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    /**
     * @param int|string $id
     * @param BotsRequest $request
     * @return mixed
     */
    public function update(int|string $id, BotsRequest $request): mixed
    {
        return $this->model->updateBy($id, $request->all());
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
    }

    /**
     * 群发消息到群
     *
     * 兼容两种内容形式：
     *  1. 原有形式：type=text|photo + text/photo/caption；
     *  2. 结构化 blocks（官方 emoji、自定义/动态 emoji、贴纸、消息特效），
     *     可直接传 blocks，也可传 template_id 用已保存的模板。
     */
    public function sendToGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'botId' => 'nullable|integer',
            'telegram_user_id' => 'nullable|integer',
            'channel' => 'nullable|in:bot,user',
            'chatIds' => ['present', function ($attribute, $value, $fail) {
                if (!is_array($value) && $value !== 'all') {
                    $fail('The chat ids must be an array or the string "all".');
                }
            }],
            'groupId' => 'nullable|integer',
            'type' => 'nullable|in:text,photo',
            'text' => 'nullable|string',
            'photo' => 'nullable|string',
            'caption' => 'nullable|string',
            'template_id' => 'nullable|integer',
            'blocks' => 'nullable|array',
        ]);

        $channel = $data['channel'] ?? 'bot';

        $bot = null;
        $apiUser = null;

        if ($channel === 'user') {
            // 客服账号通道：走 MadelineProto（自定义/动态 emoji 由此发送）
            $apiUser = TelegramApiUsers::query()->find((int) ($data['telegram_user_id'] ?? 0));

            if (! $apiUser || empty($apiUser->session_file)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Telegram 客服账号不存在或缺少 session_file',
                    'total' => 0,
                ], 404);
            }
        } else {
            // botId 传的是 bots 表主键 id（不是 Telegram 的 bot uid）
            $bot = $this->model->find((int) ($data['botId'] ?? 0));

            if (! $bot || empty($bot->api_token)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bot not found or api_token is empty',
                    'total' => 0,
                ], 404);
            }
        }

        // 使用 match 表达式处理 chatIds
        // user 通道按 app_id 找该客服账号名下的群（bot_groups.app_id 存的就是 telegram_api_users.app_id）
        $chatIds = match(true) {
            !is_array($data['chatIds']) && $data['chatIds'] === 'all'
                => $channel === 'user'
                    ? BotGroups::query()->where('app_id', $apiUser->app_id)->pluck('chat_id')->toArray()
                    : $bot->botGroups()->pluck('chat_id')->toArray(),
            !empty($data['groupId'])
                => $channel === 'user'
                    ? BotGroups::query()
                        ->where('group_id', $data['groupId'])
                        ->where('app_id', $apiUser->app_id)
                        ->pluck('chat_id')->toArray()
                    : $this->getChatIdsByGroupId(
                        chatIds: is_array($data['chatIds']) ? $data['chatIds'] : [],
                        groupId: $data['groupId'],
                        botId: $data['botId']
                    ),
            default => is_array($data['chatIds']) ? $data['chatIds'] : []
        };

        // 去重 + 过滤空值 + 统一转成字符串（chat_id 可能是 -100xxxxxxxxxx）
        $chatIds = array_values(array_unique(array_filter(
            array_map(static fn ($chatId) => (string) $chatId, $chatIds),
            static fn ($chatId) => $chatId !== ''
        )));

        if (empty($chatIds)) {
            return response()->json([
                'status' => 'success',
                'message' => 'No chat IDs to send messages',
                'total' => 0,
            ]);
        }

        // 结构化内容：优先 blocks，其次模板
        $blocks = $data['blocks'] ?? null;

        if (empty($blocks) && ! empty($data['template_id'])) {
            $blocks = MessageTemplate::query()->find($data['template_id'])?->blocks;
        }

        $rendered = empty($blocks)
            ? null
            : app(MessageRenderer::class)->render($blocks, [], $channel);

        // 规则：带自定义表情 / 贴纸 / 消息特效的内容只能由 telegram 客服账号发送
        if ($rendered && $this->hasRichContent($rendered) && $channel !== 'user') {
            return response()->json([
                'status' => 'error',
                'message' => '带自定义表情 / 贴纸 / 消息特效的内容只能通过 telegram 客服账号发送（channel=user 且传 telegram_user_id）',
            ], 422);
        }

        $photo = $data['photo'] ?? '';
        $type = $data['type'] ?? ($photo !== '' ? 'photo' : 'text');

        if ($type === 'photo') {
            // 图文：结构化文本放到 caption 上（实体用 caption_entities）
            $text = '';
            $caption = $rendered['text'] ?? ($data['caption'] ?? '');
        } else {
            $text = $rendered['text'] ?? ($data['text'] ?? '');
            $caption = '';
        }

        // 建群发任务 + 预建回执，后台能看到每个群的成败
        $send = MessageSend::query()->create([
            'template_id' => $data['template_id'] ?? null,
            'channel' => $channel,
            'bot_id' => $bot?->id,
            'telegram_user_id' => $apiUser?->id,
            'blocks' => $blocks ?? [],
            'chat_ids' => $chatIds,
            'total' => count($chatIds),
            'status' => 'running',
        ]);

        $send->createLogs($chatIds);

        $text = $rendered['text'] ?? ($data['text'] ?? '');

        // 为每个 chatId 创建独立的任务
        $result = $channel === 'user'
            ? $this->dispatchUserMessages(
                apiUser: $apiUser,
                chatIds: $chatIds,
                text: $text,
                entities: $rendered['entities'] ?? [],
                sendId: $send->id
            )
            : $this->dispatchBotMessages(
                chatIds: $chatIds,
                type: $type,
                token: $bot->api_token,
                text: $text,
                photo: $photo,
                caption: $caption,
                entities: $rendered['entities'] ?? [],
                stickers: $rendered['stickers'] ?? [],
                effectId: $rendered['effect_id'] ?? null,
                sendId: $send->id
            );

        return response()->json([
            'status' => $result['failed'] === 0 ? 'success' : 'partial',
            'message' => $result['failed'] === 0
                ? 'Messages queued successfully'
                : "{$result['queued']} queued, {$result['failed']} failed to queue",
            'total' => count($chatIds),
            'queued' => $result['queued'],
            'failed' => $result['failed'],
            'send_id' => $send->id,
        ]);
    }

    /**
     * 群发进度（按 send_id 查）
     */
    public function sendStatus(int $sendId): JsonResponse
    {
        $send = MessageSend::query()->find($sendId);

        if (! $send) {
            return response()->json([
                'status' => 'error',
                'message' => 'Send task not found',
            ], 404);
        }

        $pending = MessageSendLog::query()
            ->where('send_id', $send->id)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $send->id,
                'status' => $pending === 0 ? 'finished' : $send->status,
                'total' => $send->total,
                'success' => $send->success,
                'failed' => $send->failed,
                'pending' => $pending,
            ],
        ]);
    }

    /**
     * 内容里是否含有「只有客服账号能发」的东西：自定义表情 / 贴纸 / 消息特效
     */
    private function hasRichContent(array $rendered): bool
    {
        return ($rendered['entities'] ?? []) !== []
            || ($rendered['stickers'] ?? []) !== []
            || ! empty($rendered['effect_id']);
    }

    /**
     * 客服账号通道：复用已有的 TelegramApiOperateFeatureJob（MadelineProto 发送，支持实体）
     *
     * @return array{queued: int, failed: int, errors: array}
     */
    private function dispatchUserMessages(
        TelegramApiUsers $apiUser,
        array $chatIds,
        string $text,
        array $entities,
        int $sendId
    ): array {
        $queued = 0;
        $failed = 0;
        $errors = [];

        foreach ($chatIds as $index => $chatId) {
            // 真人账号比 bot 更容易被限流，这里放慢到 1 条/秒
            $delay = intdiv($index, self::USER_DISPATCH_PER_SECOND);

            try {
                TelegramApiOperateFeatureJob::dispatch(
                    $apiUser->session_file,
                    (int) $apiUser->app_id,
                    (string) $apiUser->app_hash,
                    [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'entities' => $entities,
                    ],
                    'text',
                    $sendId
                )->delay($delay);

                $queued++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['chat_id' => $chatId, 'error' => $e->getMessage()];

                app(LogMessageService::class)->createLaravelLog(
                    'sendGroupMsgDispatchFail',
                    ['chat_id' => $chatId, 'error' => $e->getMessage()],
                    'dispatch TelegramApiOperateFeatureJob failed',
                    'error'
                );
            }
        }

        return ['queued' => $queued, 'failed' => $failed, 'errors' => $errors];
    }

    private function getChatIdsByGroupId(array $chatIds, int $groupId, int $botId): array
    {
        $groupChatIds = $this->botGroupsModel
            ->where('group_id', $groupId)
            ->where('bot_id', $botId)
            ->pluck('chat_id')
            ->toArray();

        return array_unique([...$chatIds, ...$groupChatIds]);
    }

    /**
     * @return array{queued: int, failed: int, errors: array}
     */
    private function dispatchBotMessages(
        array $chatIds,
        string $type,
        string $token,
        string $text,
        string $photo,
        string $caption,
        array $entities = [],
        array $stickers = [],
        ?string $effectId = null,
        ?int $sendId = null
    ): array {
        $queued = 0;
        $failed = 0;
        $errors = [];

        foreach ($chatIds as $index => $chatId) {
            // 注意：delay 只能用「整秒」。Carbon 的毫秒在 Queue::availableAt()
            // 里会被 getTimestamp() 截断成秒，写 addMilliseconds(50) 等于没延迟，
            // 任务会在同一秒全部释放，直接触发 Telegram 429 限流。
            $delay = intdiv($index, self::DISPATCH_PER_SECOND);

            try {
                BotSendMsgToGroup::dispatch(
                    chatId: $chatId,
                    type: $type,
                    botToken: $token,
                    text: $text,
                    photo: $photo,
                    caption: $caption,
                    entities: $entities,
                    stickers: $stickers,
                    effectId: $effectId,
                    sendId: $sendId
                )->delay($delay);

                $queued++;
            } catch (\Throwable $e) {
                // 单个任务入队失败不能中断整批，否则后面的群就永远收不到了
                $failed++;
                $errors[] = ['chat_id' => $chatId, 'error' => $e->getMessage()];

                app(\Modules\Telegram\Services\LogMessageService::class)->createLaravelLog(
                    'sendGroupMsgDispatchFail',
                    ['chat_id' => $chatId, 'error' => $e->getMessage()],
                    'dispatch BotSendMsgToGroup failed',
                    'error'
                );
            }
        }

        return ['queued' => $queued, 'failed' => $failed, 'errors' => $errors];
    }
}
