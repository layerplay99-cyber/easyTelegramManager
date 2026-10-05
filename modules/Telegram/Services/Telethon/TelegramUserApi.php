<?php

namespace Modules\Telegram\Services\Telethon;

class TelegramUserApi
{
    protected TelethonClient $client;

    public function __construct(string $session, ?int $appId = null, ?string $appHash = null)
    {
        $this->client = new TelethonClient($session, $appId, $appHash);
    }

    public function client(): TelethonClient
    {
        return $this->client;
    }

    /* ---------------------------------------------------------- 登录态 */

    /** 与原历史魔数一致：3=已登录 / 2=等待2FA / 其它=未登录。 */
    public function getAuthorization(): int
    {
        return (int) ($this->client->post('/auth/status')['authorization'] ?? 0);
    }

    public function isLoggedIn(): bool
    {
        return $this->getAuthorization() === 3;
    }

    /** 生成登录二维码（SVG），对齐原 qrLogin()->getQRSvg()。 */
    public function getQrSvg(): string
    {
        return (string) ($this->client->post('/auth/qr')['svg'] ?? '');
    }

    /** 等待扫码：登录成功 / 需要2FA / 换新码。对齐原 waitForLoginOrQrCodeExpiration()。 */
    public function waitAuthorization(int $timeout = 25): array
    {
        return (array) $this->client->post('/auth/wait', ['timeout' => $timeout]);
    }

    public function completeLogin(string $code): void
    {
        $this->client->post('/auth/code', ['phone' => $this->phone(), 'code' => $code]);
    }

    public function complete2faLogin(string $password): void
    {
        $this->client->post('/auth/2fa', ['password' => $password]);
    }

    public function logout(): bool
    {
        $this->client->post('/auth/logout');
        return true;
    }

    /** 从 session 文件名取手机号（session_+8613800138000.madeline）。 */
    public function phone(): string
    {
        if (preg_match_all('/\d+/', $this->client->getSession(), $m) && !empty($m[0])) {
            return (string) end($m[0]);
        }
        return '';
    }

    /* ---------------------------------------------------------- 发送 */

    public function sendText(string $chatId, string $text, array $buttons = [], array $entities = [], ?array $replyTo = null): array
    {
        return (array) $this->client->post('/msg/sendText', [
            'chat_id'  => $chatId,
            'text'     => $text,
            'buttons'  => $buttons,
            'entities' => $entities,
            'reply_to' => $replyTo,
        ]);
    }

    public function sendMedia(string $chatId, string $imagePath, string $text = '', array $buttons = [], array $entities = []): array
    {
        $payload = [
            'chat_id'  => $chatId,
            'text'     => $text,
            'buttons'  => $buttons,
            'filename' => basename($imagePath) ?: 'upload.jpg',
        ];
        if (is_file($imagePath) && is_readable($imagePath)) {
            $payload['file_b64'] = base64_encode((string) file_get_contents($imagePath));
        } elseif (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            $payload['file_url'] = $imagePath;
        } else {
            throw new \RuntimeException('sendMedia 找不到可读文件: ' . $imagePath);
        }
        return (array) $this->client->post('/msg/sendMedia', $payload);
    }

    public function kickUser(string $chatId, $userId): array
    {
        return (array) $this->client->post('/msg/kick', [
            'chat_id' => $chatId,
            'user_id' => (int) $userId,
        ]);
    }

    /* ---------------------------------------------------------- 群组 */

    public function getGroups(): array
    {
        return (array) ($this->client->post('/groups/dialogs')['groups'] ?? []);
    }

    public function getGroupMembers($chatId)
    {
        return (array) ($this->client->post('/groups/members', ['chat_id' => (string) $chatId])['members'] ?? []);
    }

    /* ---------------------------------------------------------- 联系人 */

    public function resolvePhone(string $phone): ?array
    {
        $res = (array) $this->client->post('/contacts/resolvePhone', ['phone' => $phone]);
        return $res['user'] ?? null;
    }

    public function getUserInfo(string $phone): array
    {
        return (array) ($this->resolvePhone($phone) ?? []);
    }

    /** 复刻原 resolveMentions：返回 [前缀文本, entities]，实体为 messageEntityMentionName。 */
    public function resolveMentions(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return ['', []];
        }
        $users = (array) ($this->client->post('/contacts/resolveUsers', ['user_ids' => $userIds])['users'] ?? []);

        $text = '';
        $entities = [];
        foreach ($users as $u) {
            $name = '@' . ($u['display_name'] ?? ('用户' . ($u['id'] ?? '')));
            $offset = mb_strlen($text, 'UTF-16LE') >> 1;
            $text .= $name . ' ';
            $entities[] = [
                '_'       => 'messageEntityMentionName',
                'offset'  => $offset,
                'length'  => mb_strlen($name, 'UTF-16LE') >> 1,
                'user_id' => (int) ($u['id'] ?? 0),
            ];
        }
        return [$text, $entities];
    }

    /* ---------------------------------------------------------- 媒体 */

    /** 下载头像到共享媒体目录，返回绝对路径。 */
    public function downloadUserAvatar(int $userId, ?string $filename = null): string
    {
        $res = (array) $this->client->post('/media/avatar', [
            'user_id'  => $userId,
            'filename' => $filename ?: ('avatars/' . $userId . '.jpg'),
        ]);
        return (string) ($res['path'] ?? '');
    }

    /* ---------------------------------------------------------- 表情（复用原纯 PHP 逻辑） */

    public function parseEffects(string $text): array
    {
        return app(\Modules\Telegram\Services\Madeline\EmojiService::class)->parseEffects($text);
    }
}
