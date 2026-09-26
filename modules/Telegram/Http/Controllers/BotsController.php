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
use Modules\Telegram\Services\BaseService;
use Modules\Telegram\Services\BotGroupSyncService;


class BotsController extends Controller
{
    /**
     * 每秒最多投递多少个发送任务。
     * Telegram 官方限制约为 30 条/秒（同一 bot），这里保守取 20。
     */
    private const DISPATCH_PER_SECOND = 20;

    public function __construct(
        protected readonly Bots $model,
        protected readonly BotGroups $botGroupsModel,
        protected readonly BaseService $baseService,
        protected readonly BotGroupSyncService $botGroupSyncService,
    ) {}

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        $user = $this->getLoginUser();
        return $this->model->setBeforeGetList(function ($query) use ($user) {
            if (! $user->isSuperAdmin()) {
                $query->where('creator_id', $this->getLoginUserId());
            }
            return $query;
        })->getList();
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
     * @param Request $request
     * @return mixed
     */
    public function update(int|string $id, Request $request): mixed
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

    public function sendToGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'botId' => 'required|integer',
            'chatIds' => ['present', function ($attribute, $value, $fail) {
                if (!is_array($value) && $value !== 'all') {
                    $fail('The chat ids must be an array or the string "all".');
                }
            }],
            'groupId' => 'nullable|integer',
            'type' => 'required|in:text,photo',
            'text' => 'required_if:type,text|nullable|string',
            'photo' => 'required_if:type,photo|nullable|string',
            'caption' => 'nullable|string',
        ]);

        // botId 传的是 bots 表主键 id（不是 Telegram 的 bot uid）
        $bot = $this->model->find($data['botId']);

        if (! $bot || empty($bot->api_token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bot not found or api_token is empty',
                'total' => 0,
            ], 404);
        }

        $token = $bot->api_token;

        // 使用 match 表达式处理 chatIds
        $chatIds = match(true) {
            !is_array($data['chatIds']) && $data['chatIds'] === 'all'
                => $bot->botGroups()->pluck('chat_id')->toArray(),
            !empty($data['groupId']) => $this->getChatIdsByGroupId(
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

        // 为每个 chatId 创建独立的任务
        $result = $this->dispatchBotMessages(
            chatIds: $chatIds,
            type: $data['type'],
            token: $token,
            text: $data['text'] ?? '',
            photo: $data['photo'] ?? '',
            caption: $data['caption'] ?? ''
        );

        return response()->json([
            'status' => $result['failed'] === 0 ? 'success' : 'partial',
            'message' => $result['failed'] === 0
                ? 'Messages queued successfully'
                : "{$result['queued']} queued, {$result['failed']} failed to queue",
            'total' => count($chatIds),
            'queued' => $result['queued'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * 同步机器人所在群
     *
     * Bot API 没有「列出机器人所在群」的接口，只能：
     * 1. 以库里已知的 chat_id 作为候选；
     * 2. 用 getChatMember 逐个确认该 bot 是否仍在群内；
     * 3. 在群内则补写/修正 bot_groups.bot_id，不在群内则清理失效记录。
     */
    public function syncGroups(Request $request, int|string $id): JsonResponse
    {
        $bot = $this->model->find($id);

        if (! $bot || empty($bot->api_token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bot not found or api_token is empty',
            ], 404);
        }

        $result = $this->botGroupSyncService->syncForBot($bot);

        return response()->json([
            'status' => 'success',
            'message' => 'sync finished',
            'data' => $result,
        ]);
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
        string $caption
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
                    caption: $caption
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
