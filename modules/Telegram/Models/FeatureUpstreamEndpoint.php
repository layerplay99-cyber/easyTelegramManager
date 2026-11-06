<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Illuminate\Database\Eloquent\Model;

/** 功能 → 它实际会调用的平台接口（由 CustomFeatureRegistry 从代码声明同步） */
class FeatureUpstreamEndpoint extends Model
{
    protected $table = 'feature_upstream_endpoints';

    protected $fillable = ['feature_id', 'endpoint_code'];
}
