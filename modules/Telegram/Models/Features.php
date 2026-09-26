<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $category
 * @property string $type
 * @property string $requestType
 * @property string $location
 * @property string $feature
 * @property string|null $description
 * @property string $handler
 * @property string|null $config
 * @property bool $enabled
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|FeaturesBinds[] $featuresBinds
 * @property-read \Illuminate\Database\Eloquent\Collection|FeaturesLogs[] $featuresLogs
 */
class Features extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'features';

    protected $fillable = [
        'id',
        'name',
        'category',
        'type',
        'requestType',
        'location',
        'feature',
        'description',
        'handler',
        'config',
        'enabled',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'id',
        'name',
        'category',
        'type',
        'requestType',
        'location',
        'feature',
        'description',
        'handler',
        'config',
        'enabled',
        'created_at',
        'updated_at'
    ];

    /**
     * @var array
     */
    protected array $form = [
        'name',
        'category',
        'type',
        'requestType',
        'location',
        'feature',
        'description',
        'handler',
        'config',
        'enabled'
    ];

    /**
     * @var array
     */
    public array $searchable = [
        'name' => 'like',
        'category' => '=',
        'enabled' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function featuresBinds(): HasMany
    {
        return $this->hasMany(FeaturesBinds::class, 'feature_id', 'id');
    }

    public function featuresLogs(): HasMany
    {
        return $this->hasMany(FeaturesLogs::class, 'features_id', 'id');
    }
}
