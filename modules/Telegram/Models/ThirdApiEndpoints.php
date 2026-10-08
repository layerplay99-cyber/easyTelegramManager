<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 平台接口规范
 *
 * 接口由平台统一维护（见 PlatformEndpointRegistry），不属于任何用户/上游：
 *   code            平台唯一标识，功能按它引用
 *   path_template   平台约定的请求路径
 *   params_schema   统一入参规范（接入标准）
 *   response_schema 统一出参规范
 *
 * 各用户差异只体现在「三方配置」（base_url + token）上，由 bots.third_config_id 指向。
 */
class ThirdApiEndpoints extends Model
{
    protected $table = 'third_api_endpoints';

    protected $fillable = [
        'id',
        'code',
        'name',
        'method',
        'path_template',
        'params_schema',
        'response_schema',
        'headers',
        'query',
        'timeout',
        'enabled',
      'remark',
    ];

    protected $casts = [
    'params_schema' => 'array',
        'response_schema' => 'array',
        'headers' => 'array',
        'query' => 'array',
   'enabled' => 'boolean',
    ];

    protected array $fields = [
        'id',
        'code',
    'name',
        'method',
   'path_template',
   'timeout',
        'enabled',
        'remark',
  'created_at',
    ];

    protected array $form = [
        'code',
        'name',
        'method',
        'path_template',
    'params_schema',
        'response_schema',
        'timeout',
        'enabled',
        'remark',
    ];

    public array $searchable = [
   'code' => 'like',
        'name' => 'like',
    ];

    protected bool $isPaginate = true;
}