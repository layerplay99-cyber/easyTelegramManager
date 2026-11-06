<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Illuminate\Database\Eloquent\Model;

/** 上游声明支持的功能；只有 needs_upstream=1 的功能允许入库 */
class ThirdConfigFeature extends Model
{
    protected $table = 'third_config_features';

    protected $fillable = [
        'third_config_id', 'feature_id', 'tenant_id',
    ];

    protected $casts = ['tenant_id' => 'int'];
}
