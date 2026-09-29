<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

/**
 * 群发任务（发到群组）
 *
 * @property int $id
 * @property int|null $template_id
 * @property string $channel
 * @property int|null $bot_id
 * @property array $blocks
 * @property array $chat_ids
 * @property int $total
 * @property int $success
 * @property int $failed
 * @property string $status
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class MessageSend extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'message_sends';

    protected $fillable = [
        'id',
        'template_id',
        'channel',
        'bot_id',
        'telegram_user_id',
        'blocks',
        'chat_ids',
        'total',
        'success',
        'failed',
        'status',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'template_id',
        'channel',
        'bot_id',
        'telegram_user_id',
        'blocks',
        'chat_ids',
        'total',
        'success',
        'failed',
        'status',
        'created_at',
        'updated_at'
    ];

    protected array $form = [
        'template_id',
        'channel',
        'bot_id',
        'telegram_user_id',
        'blocks',
        'chat_ids',
        'status'
    ];

    protected $casts = [
        'blocks' => 'array',
        'chat_ids' => 'array',
    ];

    public array $searchable = [
        'status' => '=',
        'channel' => '=',
        'bot_id' => '=',
    ];

    protected bool $isPaginate = true;

    /**
     * 预建回执：每个群一条，后台能看到逐个群的成败
     */
    public function createLogs(array $chatIds): void
    {
        $now = now();

        $rows = array_map(fn ($chatId) => [
            'send_id' => $this->getKey(),
            'chat_id' => (string) $chatId,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ], $chatIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            MessageSendLog::query()->insert($chunk);
        }
    }

    /**
     * 记一次成功
     */
    public function incrementSuccess(): void
    {
        $this->newQuery()->where($this->getKeyName(), $this->getKey())->increment('success');
    }

    /**
     * 记一次失败
     */
    public function incrementFailed(): void
    {
        $this->newQuery()->where($this->getKeyName(), $this->getKey())->increment('failed');
    }
}
