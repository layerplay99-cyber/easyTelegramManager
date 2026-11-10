<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 插件审核设置（单行 id=1）
 *
 * @property int $id
 * @property int $ai_enabled
 * @property string $ai_base_url
 * @property string $ai_model
 * @property string $ai_api_key
 * @property int $ai_timeout
 * @property int $updated_by
 */
class PluginSetting extends Model
{
    protected $table = 'plugin_settings';

    protected $fillable = [
        'id', 'ai_enabled', 'ai_base_url', 'ai_model', 'ai_api_key',
        'ai_timeout', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'ai_enabled', 'ai_base_url', 'ai_model', 'ai_timeout', 'updated_by',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'ai_enabled' => 'integer',
        'ai_timeout' => 'integer',
    ];

    /**
     * 当前设置（没有就按默认值造一行，避免全新库查不到设置）
     */
    public static function current(): self
    {
        $setting = static::query()->find(1);

        if ($setting) {
            return $setting;
        }

        $setting = static::query()->create([
            'id' => 1,
            'ai_enabled' => 0,
            'ai_base_url' => 'https://api.openai.com/v1',
            'ai_model' => 'gpt-4o-mini',
            'ai_api_key' => '',
            'ai_timeout' => 30,
            'updated_by' => 0,
        ]);

        return $setting;
    }
}
