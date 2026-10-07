<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 功能运行时数据
 *
 * 替代原「group_configs 宽表 + Cache::forever」：数据落库，
 * 按「功能 + 作用域」存 json，功能有几个参数就存几个，不需要加列。
 *
 * @property int $id
 * @property int $feature_id
 * @property string $scope_type
 * @property string $scope_id
 * @property array|null $data
 * @property bool $enabled
 */
class FeatureData extends Model
{
    protected $table = 'feature_data';

    protected $fillable = ['id','feature_id','scope_type','scope_id','data','enabled'];

    protected $casts = [
        'data' => 'array',
        'enabled' => 'boolean',
    ];

    protected array $fields = ['id','feature_id','scope_type','scope_id','created_at','updated_at'];

    protected array $form = ['feature_id','scope_type','scope_id','data','enabled'];

    public array $searchable = ['feature_id' => '=','scope_type' => '=','scope_id' => '='];

    protected bool $isPaginate = true;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Features::class, 'feature_id', 'id');
    }
}