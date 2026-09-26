<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $group_id
 * @property int $app_id
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read BotGroups $botGroup
 * @property-read TelegramApiUsers $telegramApiUser
 */
class ServicePeoples extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'service_peoples';

    protected $fillable = [
        'id',
        'group_id',
        'app_id',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected array $fields = [
        'id',
        'group_id',
        'app_id',
        'creator_id',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'group_id',
        'app_id',
    ];

    public array $searchable = [
        'group_id' => '=',
        'app_id' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'group_id', 'id');
    }

    public function telegramApiUser(): BelongsTo
    {
        // app_id 存的是 telegram_api_users.app_id 字符串，不是主键 id
        return $this->belongsTo(TelegramApiUsers::class, 'app_id', 'app_id');
    }
}
