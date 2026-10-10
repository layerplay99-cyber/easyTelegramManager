<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 交互会话
 *
 * 按钮里只放 code（随机短句柄），群/机器人/商户/上游/回调原文/权限快照全在这张表里。
 * 这样：
 *   - callback_data 不会被 64 字节卡住
 *   - 业务数据不会被客户端看到或篡改
 *   - 点击时能逐条校验「是不是这个群、是不是这个机器人、是不是过期、是不是有权限」
 *
 * @property int $id
 * @property string $code
 * @property string $business_type
 * @property int $bot_id
 * @property string $chat_id
 * @property string|null $message_id
 * @property string|null $merchant_id
 * @property int|null $third_config_id
 * @property int $step
 * @property string|null $pending_act
 * @property string $status
 * @property array|null $payload
 * @property array|null $buttons
 * @property array|null $allowed
 * @property string|null $dedup_key
 * @property int $expires_at
 * @property int $attempts
 * @property int|null $operator_user_id
 */
class InteractiveSession extends Model
{
    protected $table = 'interactive_sessions';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id', 'code', 'business_type', 'feature_id', 'bot_id', 'chat_id', 'message_id',
        'merchant_id', 'third_config_id', 'step', 'pending_act', 'status', 'payload',
        'buttons', 'allowed', 'dedup_key', 'expires_at', 'attempts',
        'operator_user_id', 'operator_name', 'result',
        // 不列进来 CatchAdmin 不会写时间戳
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'buttons' => 'array',
        'allowed' => 'array',
        'result' => 'array',
        'step' => 'integer',
        'attempts' => 'integer',
        'expires_at' => 'integer',
    ];

    protected array $fields = [
        'id', 'code', 'business_type', 'bot_id', 'chat_id', 'message_id', 'merchant_id',
        'third_config_id', 'step', 'pending_act', 'status', 'dedup_key', 'expires_at',
        'attempts', 'operator_user_id', 'operator_name', 'created_at', 'updated_at',
    ];

    public array $searchable = [
        'merchant_id' => 'like',
        'chat_id' => '=',
        'bot_id' => '=',
        'status' => '=',
        'code' => '=',
    ];

    protected bool $isPaginate = true;

    /**
     * 是否还能被操作（未消费且未过期）
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at > 0 && $this->expires_at < time();
    }

    public static function statusTexts(): array
    {
        return [
            self::STATUS_PENDING => '待处理',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_DONE => '已完成',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_EXPIRED => '已过期',
        ];
    }

    public function getStatusText(): string
    {
        return self::statusTexts()[$this->status] ?? $this->status;
    }
}
