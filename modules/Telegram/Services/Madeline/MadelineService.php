<?php

namespace Modules\Telegram\Services\Madeline;

use danog\MadelineProto\API as ProtoAPI;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Logger;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Logger as LoggerSettings;
use Modules\Telegram\Services\LogMessageService;
use Throwable;

class MadelineService
{
    protected string $session;
    protected ProtoAPI $protoApi;

    protected LogMessageService $logMessageService;

    /**
     * @throws Exception|Throwable
     */
    public function __construct(string $session, ?int $appId = null, ?string $appHash = null)
    {
        $this->init($session, $appId, $appHash);

        $this->logMessageService = app(LogMessageService::class);
    }

    /**
     * 初始化 MadelineProto 会话
     * @throws Exception
     * @throws Throwable
     */
    private function init($session, ?int $appId = null, ?string $appHash = null): void
    {
        if (str_starts_with($session, '/') || (strlen($session) > 1 && $session[1] === ':')) {
            $fullSessionPath = $session;
        } else {
            if (str_starts_with($session, 'storage/')) {
                $fullSessionPath = base_path($session);
            } else {
                $fullSessionPath = storage_path($session);
            }
        }

        $sessionDir = dirname($fullSessionPath);
        if (!is_dir($sessionDir)) {
            if (!@mkdir($sessionDir, 0755, true) && !is_dir($sessionDir)) {
                throw new Exception("创建会话目录失败: {$sessionDir} ，请检查目录权限");
            }
        }
        if (!is_writable($sessionDir)) {
            throw new Exception("会话目录不可写: {$sessionDir} ，请检查目录权限");
        }
        $logsDir = storage_path('logs');
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        chdir($logsDir);

        $settings = new Settings;
        $logger = (new LoggerSettings)
            ->setType(Logger::LOGGER_FILE)
            ->setLevel(Logger::LEVEL_WARNING)
            ->setExtra(storage_path('logs/MadelineProto.log'));
        $settings->setLogger($logger);
        $settings->getSerialization()
            ->setInterval(30);
        $settings->getConnection()
            ->setTimeout(60)
            ->setRetry(true);

        if ($appId && $appHash) {
            $appInfo = (new AppInfo)
                ->setApiId($appId)
                ->setApiHash($appHash);
            $settings->setAppInfo($appInfo);
        }

        $this->session = $fullSessionPath;

        try {
            $this->protoApi = new ProtoAPI($this->session, $settings);
        } catch (Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['session' => $session, 'error' => $e->getMessage()],
                'MadelineProto 初始化失败，正在重试...'
            );
            sleep(2);
            try {
                $this->protoApi = new ProtoAPI($this->session, $settings);
            } catch (Throwable $e2) {
                $this->logMessageService->createLaravelLog(
                    'madeline_error',
                    ['session' => $session, 'error' => $e2->getMessage()],
                    'MadelineProto 重试初始化失败'
                );
                throw new Exception('MadelineProto 初始化失败: ' . $e2->getMessage());
            }
        }
    }

    public function getApi(): ProtoAPI
    {
        return $this->protoApi;
    }

    /**
     * 启动 MadelineProto 会话
     */
    public function start(): void
    {
        $this->protoApi->start();
    }

    /**
     * QR Code 登录
     */
    public function qrLogin(): ?\danog\MadelineProto\TL\Types\LoginQrCode
    {
        return $this->protoApi->qrLogin();
    }

    public function login(string $phone): array
    {
        return $this->protoApi->phoneLogin($phone);
    }

    /**
     * 检查登录状态
     */
    public function isLoggedIn(): bool
    {
        try {
            $authorization = $this->protoApi->getAuthorization();
            return $authorization === \danog\MadelineProto\API::LOGGED_IN;
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                [],
                '检查登录状态失败: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * 获取 QR Code
     * @throws \Exception
     */
    public function getQrCode(): array
    {
        try {
            $qrCode = $this->protoApi->qrLogin();
            return [
                'logged_in' => false,
                'svg' => $qrCode->getQRSvg(400)
            ];
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                [],
                '获取 QR Code 失败: ' . $e->getMessage()
            );
            throw new Exception('获取 QR Code 失败: ' . $e->getMessage());
        }
    }

    /**
     * 等待 QR Code 登录或返回新的 QR Code
     * @throws Exception
     */
    public function waitQrCodeOrLogin(): array
    {
        try {
            if ($this->isLoggedIn()) {
                return ['logged_in' => true];
            }
            $qrCode = $this->protoApi->qrLogin();
            $result = $qrCode->waitForLoginOrQrCodeExpiration();
            if ($result instanceof \danog\MadelineProto\TL\Types\LoginQrCode) {
                return [
                    'logged_in' => false,
                    'svg' => $result->getQRSvg(400)
                ];
            } else {
                return ['logged_in' => true];
            }
        } catch (\Exception $e) {
            if ($this->isLoggedIn()) {
                return ['logged_in' => true];
            }

            $this->logMessageService->createLaravelLog(
                'madeline_error',
                [],
                '等待 QR Code 登录失败: ' . $e->getMessage()
            );
            throw new Exception('等待 QR Code 登录失败: ' . $e->getMessage());
        }
    }

    /**
     * 完成两步验证（2FA）
     */
    public function complete2faLogin(string $password): void
    {
        $this->protoApi->complete2faLogin($password);
    }

    public function completeLogin(string $code): void
    {
        $this->protoApi->completePhoneLogin($code);
    }

    /**
     * 注销登录
     * @throws Exception
     */
    public function logout(): bool
    {
        try {
            $this->protoApi->logout();
            return true;
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                [],
                '注销登录失败: ' . $e->getMessage()
            );
            throw new Exception('注销登录失败: ' . $e->getMessage());
        }
    }

    public function getUserInfo(string $phone): array
    {
        return $this->protoApi->contacts->resolvePhone($phone);
    }

    /**
     * @throws Exception
     */
    public function sendText(string $chatId, string $text, array $buttons = [], array $entities = [], ?array $replyTo = null): array
    {
        // 检查登录状态，如果未登录则记录警告但继续尝试
        if (!$this->isLoggedIn()) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['chat_id' => $chatId, 'session' => $this->session],
                '警告：账号未登录或会话已过期，消息发送可能失败'
            );
        }

        $this->ensurePeerInDb($chatId);

        return $this->protoApi->messages->sendMessage(
            peer: $chatId,
            message: $text,
            reply_markup: $this->buildReplyMarkup($buttons),
            entities: $entities,
            reply_to: $replyTo,
        );
    }

    /**
     * @throws Exception
     */
    public function sendMedia(string $chatId, string $imagePath, string $text = '', array $buttons = [], array $entities = []): array
    {
        if (!$this->isLoggedIn()) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['chat_id' => $chatId, 'session' => $this->session],
                '警告：账号未登录或会话已过期，消息发送可能失败'
            );
        }

        $this->ensurePeerInDb($chatId);

        return $this->protoApi->messages->sendMedia(
            peer: $chatId,
            media: [
                '_' => 'inputMediaUploadedPhoto',
                'file' => $imagePath,
            ],
            message: $text,
            reply_markup: $this->buildReplyMarkup($buttons),
            entities: $entities
        );
    }

    /**
     * 把用户踢出群组 / 频道
     *
     * @param string $chatId  群组/频道 chat_id（负数）
     * @param mixed $userId   要踢出的用户（user_id 或 InputPeer）
     * @return array
     * @throws \Throwable
     */
    public function kickUser(string $chatId, $userId): array
    {
        if (!$this->isLoggedIn()) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['chat_id' => $chatId, 'session' => $this->session],
                '警告：账号未登录或会话已过期，踢人操作可能失败'
            );
        }

        $this->ensurePeerInDb($chatId);

        return $this->protoApi->channels->editBanned(
            banned_rights: [
                '_' => 'chatBannedRights',
                'view_messages' => true,
                'send_messages' => true,
                'send_media' => true,
                'send_stickers' => true,
                'send_gifs' => true,
                'send_games' => true,
                'send_inline' => true,
                'embed_links' => true,
                'send_polls' => true,
                'change_info' => true,
                'invite_users' => true,
                'pin_messages' => true,
                'until_date' => 0,
            ],
            channel: $chatId,
            participant: $userId,
        );
    }

    /**
     * 确保对等体在内部数据库中
     * @throws Exception
     */
    protected function ensurePeerInDb(string $chatId): void
    {
        try {
            $this->protoApi->getPwrChat($chatId);
            $this->logMessageService->createLaravelLog(
                'madeline_info',
                ['chat_id' => $chatId],
                '对等体已存在于数据库中'
            );
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_warning',
                ['chat_id' => $chatId, 'error' => $e->getMessage()],
                '对等体不在数据库中，尝试同步'
            );

            try {
                $this->logMessageService->createLaravelLog(
                    'madeline_info',
                    ['chat_id' => $chatId],
                    '开始同步所有对话...'
                );

                $dialogs = $this->protoApi->getFullDialogs();

                $dialogCount = 0;
                if (is_array($dialogs)) {
                    $dialogCount = count($dialogs);
                } elseif ($dialogs instanceof \Traversable) {
                    $dialogCount = iterator_count($dialogs);
                }

                $this->logMessageService->createLaravelLog(
                    'madeline_info',
                    ['chat_id' => $chatId, 'dialogs_count' => $dialogCount],
                    '对话同步完成'
                );

                $this->protoApi->getPwrChat($chatId);
                $this->logMessageService->createLaravelLog(
                    'madeline_info',
                    ['chat_id' => $chatId],
                    '对等体已存在于数据库中（同步后）'
                );

            } catch (\Throwable $e2) {
                $this->logMessageService->createLaravelLog(
                    'madeline_warning',
                    ['chat_id' => $chatId, 'error' => $e2->getMessage()],
                    'getFullDialogs 后仍未找到对等体，尝试直接添加'
                );

                try {
                    $numericId = is_numeric($chatId) ? (int)$chatId : $chatId;
                    if ($numericId < 0) {
                        $absId = abs($numericId);
                        try {
                            $result = $this->protoApi->messages->getChats(['id' => [$absId]]);

                            $this->logMessageService->createLaravelLog(
                                'madeline_info',
                                ['chat_id' => $chatId, 'result' => json_encode($result)],
                                '通过 messages.getChats 获取群组信息成功'
                            );

                            if (isset($result['chats']) && !empty($result['chats'])) {
                                // getChats 会自动更新对等体数据库
                                $this->logMessageService->createLaravelLog(
                                    'madeline_info',
                                    ['chat_id' => $chatId, 'chats_count' => count($result['chats'])],
                                    '成功获取群组信息，对等体已更新'
                                );
                            }
                        } catch (\Throwable $e3) {
                            $this->logMessageService->createLaravelLog(
                                'madeline_warning',
                                ['chat_id' => $chatId, 'error' => $e3->getMessage()],
                                'messages.getChats 失败，可能是频道/超级群'
                            );

                            try {
                                $this->logMessageService->createLaravelLog(
                                    'madeline_info',
                                    ['chat_id' => $chatId],
                                    '尝试通过直接发送来触发对等体同步（将在实际发送时处理）'
                                );

                                return;
                            } catch (\Throwable $e4) {
                                $this->logMessageService->createLaravelLog(
                                    'madeline_error',
                                    ['chat_id' => $chatId, 'error' => $e4->getMessage()],
                                    '所有尝试均失败'
                                );
                            }
                        }

                        try {
                            $this->protoApi->getPwrChat($chatId);
                            $this->logMessageService->createLaravelLog(
                                'madeline_info',
                                ['chat_id' => $chatId],
                                '对等体已成功添加到数据库'
                            );
                        } catch (\Throwable $e5) {
                            $this->logMessageService->createLaravelLog(
                                'madeline_warning',
                                ['chat_id' => $chatId, 'error' => $e5->getMessage()],
                                '对等体仍未找到，将尝试直接发送'
                            );
                            return;
                        }
                    } else {
                        throw new Exception("无效的群组 ID: {$chatId}");
                    }

                } catch (\Throwable $e4) {
                    $this->logMessageService->createLaravelLog(
                        'madeline_error',
                        ['chat_id' => $chatId, 'error' => $e4->getMessage()],
                        '所有尝试均失败，将尝试直接发送消息'
                    );
                    return;
                }
            }
        }
    }

    protected function buildReplyMarkup(array $buttons): array
    {
        $keyboard = [];
        foreach (array_chunk($buttons, 2) as $row) {
            $keyboard[] = $row;
        }
        return ['inline_keyboard' => $keyboard];
    }

    /**
     * 获取所有群（普通群 + 超级群）
     *
     * 注意：getDialogs 的 limit 是「每页条数」而不是「总数」，
     * 只拉第一页会漏掉 100 条之后的群，必须翻页拉完。
     */
    public function getGroups(): array
    {
        $groups = [];
        $limit = 100;
        $maxPages = 100; // 上限保护，最多 10000 个对话
        $offsetDate = 0;
        $offsetId = 0;
        $offsetPeer = ['_' => 'inputPeerEmpty'];

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $dialogs = $this->protoApi->messages->getDialogs(
                    offset_date: $offsetDate,
                    offset_id: $offsetId,
                    offset_peer: $offsetPeer,
                    limit: $limit,
                    hash: []
                );

                if (!is_array($dialogs)) {
                    $this->logMessageService->createLaravelLog(
                        'madeline_error',
                        ['dialogs_type' => gettype($dialogs)],
                        'getDialogs 返回的不是数组'
                    );
                    break;
                }

                if (!isset($dialogs['dialogs']) || !isset($dialogs['chats'])) {
                    $this->logMessageService->createLaravelLog(
                        'madeline_warning',
                        ['available_keys' => array_keys($dialogs)],
                        'dialogs 或 chats 键不存在'
                    );
                    break;
                }

                if (empty($dialogs['dialogs'])) {
                    break;
                }

                // 本页 chat 索引
                $chatsById = [];
                foreach ($dialogs['chats'] as $chat) {
                    if (is_array($chat) && isset($chat['id'])) {
                        $chatsById[abs((int) $chat['id'])] = $chat;
                    }
                }

                // 本页 message 索引（翻页需要 offset_date）
                $messagesById = [];
                foreach ($dialogs['messages'] ?? [] as $message) {
                    if (is_array($message) && isset($message['id'])) {
                        $messagesById[(int) $message['id']] = $message;
                    }
                }

                $lastDialog = null;

                foreach ($dialogs['dialogs'] as $dialog) {
                    if (!is_array($dialog) || !isset($dialog['peer'])) {
                        continue;
                    }

                    $lastDialog = $dialog;

                    $peerId = $this->extractPeerId($dialog['peer']);

                    // 负数才是群/频道，正数是私聊
                    if ($peerId === null || $peerId >= 0) {
                        continue;
                    }

                    $chatId = abs($peerId);
                    $chat = $chatsById[$chatId] ?? null;

                    if (!$chat || !isset($chat['_'])) {
                        continue;
                    }

                    if (in_array($chat['_'], ['chat', 'channel'], true)) {
                        $groups[$chatId] = [
                            'id' => $chat['id'],
                            'title' => $chat['title'] ?? '未知群组',
                            'type' => $chat['_'] === 'channel' ? 'supergroup' : 'group',
                        ];
                    }
                }

                // 不满一页说明已经是最后一页
                if (count($dialogs['dialogs']) < $limit || $lastDialog === null) {
                    break;
                }

                // 计算下一页游标
                $nextOffsetId = (int) ($lastDialog['top_message'] ?? 0);
                if ($nextOffsetId <= 0) {
                    break;
                }

                $offsetId = $nextOffsetId;
                $offsetDate = (int) ($messagesById[$nextOffsetId]['date'] ?? 0);
                $offsetPeer = $lastDialog['peer'];
            }

            $groups = array_values($groups);

            $this->logMessageService->createLaravelLog(
                'madeline_info',
                ['result_count' => count($groups)],
                '获取群组列表成功，数量: ' . count($groups)
            );

            return $groups;
        } catch (\Exception $e) {
            $this->logMessageService->createLaravelLog(
                'madeline_error',
                ['error' => $e->getMessage(), 'line' => $e->getLine()],
                '获取群组列表失败: ' . $e->getMessage() . ' at line ' . $e->getLine()
            );
            return [];
        }
    }

    /**
     * 从 peer 中提取会话 ID（兼容标量 ID 和 peerChannel / peerChat / peerUser 结构）
     * 私聊返回正数，群/频道返回负数。
     */
    private function extractPeerId($peer): ?int
    {
        if (is_numeric($peer)) {
            return (int) $peer;
        }

        if (!is_array($peer)) {
            return null;
        }

        if (isset($peer['channel_id'])) {
            return -1 * abs((int) $peer['channel_id']);
        }

        if (isset($peer['chat_id'])) {
            return -1 * abs((int) $peer['chat_id']);
        }

        if (isset($peer['user_id'])) {
            return (int) $peer['user_id'];
        }

        return null;
    }

    /**
     * 获取群详情
     */
    public function getGroupInfo($chatId): array
    {
        return $this->protoApi->getFullInfo($chatId);
    }

    /**
     * 获取群成员
     */
    public function getGroupMembers($chatId)
    {
        return $this->protoApi->getPwrChat($chatId)['participants'] ?? [];
    }

    /**
     * 把一组 user_id 解析成「@ 提及前缀 + messageEntityMentionName 实体」
     *
     * 返回 [前缀文本, 实体数组]；前缀放在消息最前面，实体偏移量相对前缀，
     * 由调用方在 parseEffects 之前拼接，确保不会被表情标记解析破坏。
     * 解析失败的成员回退为「用户{id}」，不影响整体发送。
     *
     * @param array $userIds
     * @return array{0:string,1:array}
     */
    public function resolveMentions(array $userIds): array
    {
        $prefix = '';
        $entities = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0) {
                continue;
            }

            $display = $this->resolveDisplayName($userId);

            if ($prefix !== '') {
                $prefix .= ' ';
            }
            $offset = mb_strlen($prefix);
            $prefix .= $display;

            $entities[] = [
                '_' => 'messageEntityMentionName',
                'offset' => $offset,
                'length' => mb_strlen($display),
                'user_id' => $userId,
            ];
        }

        return [$prefix, $entities];
    }

    /**
     * 尽力解析成员展示名（优先 first_name，其次 username，失败回退 用户{id}）
     */
    protected function resolveDisplayName(int $userId): string
    {
        try {
            $info = $this->protoApi->getInfo($userId);
            if (is_array($info) && isset($info['User'])) {
                $info = $info['User'];
            }
            if (is_array($info)) {
                $firstName = $info['first_name'] ?? null;
                $username = $info['username'] ?? null;
                if (!empty($firstName)) {
                    return $firstName;
                }
                if (!empty($username)) {
                    return $username;
                }
            }
        } catch (\Throwable $e) {
            // 解析失败不影响发送，回退默认展示名
        }

        return '用户' . $userId;
    }

    /**
     * 解析带有文本特效的消息内容
     * 委托给 EmojiService
     *
     * @param string $text 包含 {effect_id:数字} 标记的文本
     * @return array{0:string,1:array} [纯文本, 消息实体数组]
     */
    public function parseEffects(string $text): array
    {
        return $this->emojiService()->parseEffects($text);
    }

    /**
     * 自动更新消息中的自定义表情包
     * 委托给 EmojiService
     */
    public function autoUpdateEmojis(Message $msg): int
    {
        return $this->emojiService()->autoUpdateEmojis($msg);
    }

    /**
     * 下载表情包并转换为 PNG
     * 委托给 EmojiService
     */
    public function downloadEmojiToPng(int $emojiId): ?string
    {
        return $this->emojiService()->downloadEmojiToPng($emojiId);
    }

    /**
     * 获取 EmojiService 实例（懒加载，避免循环依赖）
     */
    private function emojiService(): EmojiService
    {
        return new EmojiService($this, $this->logMessageService);
    }
}
