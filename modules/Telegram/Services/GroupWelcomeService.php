<?php

declare(strict_types=1);

namespace Modules\Telegram\Services;

use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\Feature\TemplateRenderer;
use Modules\Telegram\Services\Translate\ReplyTranslator;
use Telegram\Bot\Api;

/**
 * 进群欢迎语
 *
 * 后台「群配置 - 欢迎语」里录入中文，新成员进群时按该群的语种配置
 * 再补一段对应语种（走 ReplyTranslator，和群回复用的是同一套：
 * 先找模板，没有就 AWS Translate，翻译挂了只发中文）。
 *
 * 可用占位（{{username}} / {full_name} 都行）：
 *   {{username}} {{first_name}} {{last_name}} {{full_name}} {{user_id}}
 */
class GroupWelcomeService
{
    public function __construct(
        protected TemplateRenderer $renderer,
        protected ReplyTranslator $translator,
        protected LogMessageService $logMessageService,
    ) {
    }

    /**
     * 发欢迎语（群没配就什么都不做）
     *
     * @param mixed $member 新成员对象（Telegram\Bot\Objects\User），可为空
     */
    public function send(Api $telegram, int|string $chatId, mixed $member = null): bool
    {
        $welcome = GroupConfigs::welcomeOf($chatId);

        if ($welcome === '') {
            return false;
        }

        $data = $this->memberData($member);

        $text = trim($this->renderer->render($welcome, [], $data));

        if ($text === '') {
            return false;
        }

        // 双语：默认语种（中文）+ 群标语种的译文
        $text = $this->translator->bilingual($text, BotGroups::languageOf($chatId), [], $data);

        try {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logMessageService->createLaravelLog(
                'telegram_errors',
                [
                    'chat_id' => $chatId,
                    'trace' => $e->getTraceAsString(),
                ],
                "发送进群欢迎语失败: {$e->getMessage()}",
                'error'
            );

            return false;
        }
    }

    /**
     * 新成员信息（给欢迎语模板渲染用，同时作为翻译模板的数据）
     *
     * @return array<string, mixed>
     */
    protected function memberData(mixed $member): array
    {
        $id = null;
        $username = '';
        $firstName = '';
        $lastName = '';

        if (is_object($member)) {
            $id = $member->id ?? null;
            $username = (string) ($member->username ?? '');
            $firstName = (string) ($member->first_name ?? '');
            $lastName = (string) ($member->last_name ?? '');
        } elseif (is_array($member)) {
            $id = $member['id'] ?? null;
            $username = (string) ($member['username'] ?? '');
            $firstName = (string) ($member['first_name'] ?? '');
            $lastName = (string) ($member['last_name'] ?? '');
        }

        $fullName = trim($firstName . ' ' . $lastName);
        $name = $fullName !== '' ? $fullName : $username;

        return [
            'user_id' => $id,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'name' => $name,
            'mention' => $username !== '' ? '@' . $username : $name,
        ];
    }
}
