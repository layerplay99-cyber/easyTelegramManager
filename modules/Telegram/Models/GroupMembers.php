<?php
declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $chat_id
 * @property int $user_id
 * @property string|null $username
 * @property string $status
 * @property bool $is_bot
 * @property int|null $joined_at  Unix 时间戳
 * @property int|null $left_at  Unix 时间戳
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read BotGroups|null $botGroup
 */
class GroupMembers extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'group_members';

    protected $fillable = [
        'id',
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
        'joined_at',
        'left_at',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected array $fields = [
        'id',
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
        'joined_at',
        'left_at',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'group_id',
        'chat_id',
        'user_id',
        'username',
        'status',
        'is_bot',
    ];

    public array $searchable = [
        'chat_id' => '=',
        'user_id' => '=',
        'username' => 'like',
        'status' => '=',
    ];

    protected bool $isPaginate = true;

    protected $casts = [
        'is_bot' => 'boolean',
    ];

    /**
     * 把 joined_at / left_at 也登记为日期列
     *
     * Laravel 12 的 getDates() 硬编码只返回 created_at/updated_at（$dates 属性已失效），
     * 自定义时间列必须覆写此方法登记。否则 fill()/setAttribute() 不会调用
     * fromDateTime()，Carbon 被原样交给 PDO 转成 'Y-m-d H:i:s' 字符串，
     * 存入 unsigned int 列时报 1265 Data truncated。
     * 登记后由基类 $dateFormat='U' 统一格式化为 Unix 整数。
     *
     * 注意：不能用 'datetime' / 'timestamp' cast——它们同样会输出字符串。
     */
    public function getDates(): array
    {
        return array_merge(parent::getDates(), ['joined_at', 'left_at']);
    }

    // 关联关系
    public function botGroup(): BelongsTo
    {
        return $this->belongsTo(BotGroups::class, 'group_id', 'id');
    }
}
