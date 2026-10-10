<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 上游 × 平台接口 的专属实现
 *
 * 同一个平台接口（如 merchant.balance 获取余额）在不同上游的路径可能不同，
 * 由本表为每个上游单独配置，功能代码只引用平台 code，不硬编码 URL。
 *
 * @property int $id
 * @property int $third_config_id
 * @property string $endpoint_code
 * @property string|null $path 该上游专用路径；http(s) 开头视为绝对地址
 * @property string|null $method
 * @property array|null $headers
 * @property array|null $query
 * @property bool $enabled
 * @property string|null $remark
 *
 * @property-read ThirdApiConfig|null $thirdConfig
 */
class ThirdConfigEndpoint extends Model
{
    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'third_config_endpoints';

    protected $fillable = [
        'id',
        'third_config_id',
        'endpoint_code',
        'path',
        'method',
        'headers',
        'query',
        'enabled',
        'remark',
        'creator_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'query' => 'array',
        'enabled' => 'boolean',
    ];

    protected array $fields = [
        'id',
        'third_config_id',
        'endpoint_code',
        'path',
        'method',
        'enabled',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected array $form = [
        'third_config_id',
        'endpoint_code',
        'path',
        'method',
        'headers',
        'query',
        'enabled',
        'remark',
    ];

    public function thirdConfig(): BelongsTo
    {
        return $this->belongsTo(ThirdApiConfig::class, 'third_config_id', 'id');
    }
}
