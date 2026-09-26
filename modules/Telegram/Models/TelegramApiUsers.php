<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $app_id
 * @property string $app_hash
 * @property string $phone_number
 * @property string|null $nickname
 * @property int $login_status
 * @property string|null $session_file
 * @property int $status
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|ServicePeoples[] $servicePeoples
 * @property-read \Illuminate\Database\Eloquent\Collection|FeaturesBinds[] $featuresBinds
 * @property-read \Illuminate\Database\Eloquent\Collection|BotGroups[] $botGroups
 */
class TelegramApiUsers extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'telegram_api_users';

    protected $fillable = [
        'id',
        'app_id',
        'app_hash',
        'phone_number',
        'nickname',
        'login_status',
        'session_file',
        'status',
        'creator_id',
        'scan_count',
        'scan_date',
        'code',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'id',
        'app_id',
        'app_hash',
        'phone_number',
        'nickname',
        'login_status',
        'session_file',
        'status',
        'scan_count',
        'scan_date',
        'code',
        'created_at',
        'updated_at'
    ];

    /**
     * @var array
     */
    protected array $form = [
        'app_id',
        'app_hash',
        'phone_number',
        'nickname',
        'status'
    ];

    /**
     * @var array
     */
    public array $searchable = [
        'app_id' => 'like',
        'phone_number' => 'like',
        'nickname' => 'like',
        'login_status' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    //
    // service_peoples.app_id 与 bot_groups.app_id 存的都是本表的 app_id 字符串
    // （见 SyncUserGroupService::syncBotGroups()、BotGroupsController::index() 的过滤），
    // 原来这里写成了外键 'app_id' → 'id'（主键），导致关联永远匹配不上。
    public function servicePeoples(): HasMany
    {
        return $this->hasMany(ServicePeoples::class, 'app_id', 'app_id');
    }

    public function featuresBinds(): HasMany
    {
        return $this->hasMany(FeaturesBinds::class, 'bot_id', 'app_id');
    }

    public function botGroups(): HasMany
    {
        return $this->hasMany(BotGroups::class, 'app_id', 'app_id');
    }
}
