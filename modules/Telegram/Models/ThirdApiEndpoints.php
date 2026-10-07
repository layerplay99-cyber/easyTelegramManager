<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 三方 API 接口
 *
 * 从 thirdapi_config 拆出来的「接口」层：同一个上游通常有多个接口
 * （查余额 / 查订单），拆开后接口可被多个功能复用，功能只引用 endpoint_id，
 * 不必为每个接口重复建一条上游配置、也不必在功能里硬编码路径。
 *
 * @property int $id
 * @property int $third_config_id
 * @property string $name
 * @property string $method
 * @property string $path_template
 * @property array|null $headers
 * @property array|null $query
 * @property int $timeout
 * @property bool $enabled
 * @property string|null $remark
 */
class ThirdApiEndpoints extends Model
{
    protected $table = 'third_api_endpoints';

    protected $fillable = ['id','third_config_id','name','method','path_template','headers','query','timeout','enabled','remark'];

    protected $casts = [
        'headers' => 'array',
        'query' => 'array',
        'enabled' => 'boolean',
    ];

    protected array $fields = ['id','third_config_id','name','method','path_template','timeout','enabled','remark','created_at'];

    protected array $form = ['third_config_id','name','method','path_template','headers','query','timeout','enabled','remark'];

    public array $searchable = ['name' => 'like','third_config_id' => '='];

    protected bool $isPaginate = true;

    public function thirdConfig(): BelongsTo
    {
        return $this->belongsTo(ThirdApiConfig::class, 'third_config_id', 'id');
    }
}