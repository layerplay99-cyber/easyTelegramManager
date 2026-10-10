<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Feature\Drivers\Custom\Merchant;

use Modules\Telegram\Contracts\FeatureContext;
use Modules\Telegram\Contracts\FeatureResult;
use Modules\Telegram\Services\Feature\Drivers\BaseCustomFeature;
use Modules\Telegram\Services\OperatorPolicy;

/**
 * /trsq —— 设置「哪些人可以点交易按钮」
 *
 *   /trsq 123456 78910     指定这两个 Telegram 用户
 *   /trsq @alice @bob       按用户名（用户名可改，优先用 ID）
 *   /trsq clear            清空，回到默认（仅群管理员）
 *   /trsq                  查看当前设置
 *
 * 旧实现只有「管理员才能点」一种写死的判断，且判定松散。现在：
 *   · 名单按群存，配合会话里的权限快照，改了名单不影响已发出的按钮
 *   · 没设置过 = 默认群管理员
 */
class SetTradeOperator extends BaseCustomFeature
{
    public static function key(): string
    {
        return 'custom.set_trade_operator';
    }

    public static function label(): string
    {
        return '设置交易操作人';
    }

    public static function featureName(): string
    {
        return '设置交易操作人';
    }

    public static function featureDescription(): string
    {
        return '设置哪些人可以点击交易通知里的按钮（/trsq <用户ID|@用户名> … / clear）';
    }

    public static function category(): string
    {
        return 'bot';
    }

    public static function group(): string
    {
        return '商户';
    }

    public static function commands(): array
    {
        return [
            [
                'command' => 'trsq',
                'usage' => '/trsq <用户ID|@用户名> …',
                'description' => '设置可点击交易按钮的人，clear 恢复默认（群管理员）',
                'params' => [
                    ['name' => 'targets', 'required' => false, 'desc' => '用户ID 或 @用户名，空格分隔'],
                ],
                'reply_template' => '✅ 已设置操作人：{{summary}}',
            ],
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'require_admin' => true,
            'reply_template' => '✅ 已设置操作人：{{summary}}',
        ];
    }

    public static function configSchema(): array
    {
        return [
            [
                'key' => 'require_admin',
                'label' => '仅群管理员可设置',
                'type' => 'switch',
                'required' => false,
                'default' => true,
            ],
            [
                'key' => 'reply_template',
                'label' => '回复模板',
                'type' => 'template',
                'required' => false,
                'default' => '✅ 已设置操作人：{{summary}}',
            ],
        ];
    }

    public function handle(FeatureContext $context): FeatureResult
    {
        $chatId = (string) $context->chatId;
        $policy = app(OperatorPolicy::class);

        if ($context->config('require_admin', true) && ! $policy->isGroupAdmin($chatId, $context->userId)) {
            return $this->fail('只有群管理员可以设置操作人');
        }

        $tokens = array_values(array_filter(array_map(
            fn ($a) => trim((string) $a),
            $context->args
        ), fn ($a) => $a !== ''));

        // 查看
        if (! $tokens) {
            return $this->reply($this->describe($policy->settings($chatId)));
        }

        // 恢复默认
        if (count($tokens) === 1 && strtolower($tokens[0]) === 'clear') {
            $policy->clear($chatId);

            return $this->reply('已恢复默认：仅群管理员可点击');
        }

        $parsed = $policy->parseTargets($chatId, $tokens);

        if (! $parsed['ids'] && ! $parsed['names']) {
            return $this->fail('没有识别到有效的用户ID或用户名');
        }

        $policy->save($chatId, $parsed['ids'], $parsed['names']);

        $summary = implode('、', array_merge(
            array_map(fn ($id) => (string) $id, $parsed['ids']),
            array_map(fn ($n) => '@' . $n, $parsed['names'])
        ));

        $text = $this->render(
            (string) $context->config('reply_template', '✅ 已设置操作人：{{summary}}'),
            ['summary' => $summary],
            $context
        );

        if ($parsed['unknown']) {
            $text .= "\n（" . implode('、', $parsed['unknown']) . " 在群成员里没查到，将按用户名匹配）";
        }

        return $this->reply($text);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function describe(array $settings): string
    {
        if (($settings['mode'] ?? 'admin') !== 'whitelist') {
            return '当前未指定操作人，默认仅群管理员可点击。用 /trsq <用户ID|@用户名> 指定。';
        }

        $list = array_merge(
            array_map(fn ($id) => (string) $id, $settings['user_ids'] ?? []),
            array_map(fn ($n) => '@' . $n, $settings['usernames'] ?? [])
        );

        return $list
            ? '当前操作人：' . implode('、', $list) . '（群管理员同样可点击）'
            : '当前未指定操作人，默认仅群管理员可点击。';
    }
}
