<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|BotGroups[] $botGroups
 */
class GroupGroups extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';



    protected $table = 'group_groups';

    protected $fillable = [ 'id', 'name', 'description', 'creator_id', 'created_at', 'updated_at', 'deleted_at' ];

    /**
     * @var array
     */
    protected array $fields = ['id','name','description','created_at','updated_at'];

    /**
     * @var array
     */
    protected array $form = ['name','description'];

    /**
     * @var array
     */
    public array $searchable = [
        'name' => 'like',

    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function botGroups(): HasMany
    {
        return $this->hasMany(BotGroups::class, 'group_id', 'id');
    }
}
