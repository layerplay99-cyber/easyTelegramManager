<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;

/**
 * 统一素材库：官方 unicode emoji / Telegram 自定义（动态）emoji / 贴纸 / 消息特效
 *
 * @property int $id
 * @property string $type           unicode|custom_emoji|sticker|message_effect
 * @property string|null $telegram_id emoji_id / effect_id / file_unique_id
 * @property string $name
 * @property string|null $unicode   回退字符（Telegram 的 alt，实体要覆盖它才能显示）
 * @property string|null $image_path 预览图
 * @property string|null $file_id   仅 sticker，绑定 owner
 * @property string|null $owner_type bot|user
 * @property int|null $owner_id
 * @property string|null $set_name
 * @property array|null $tags
 * @property string $source         collected|uploaded|manual
 * @property int $usage_count
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Emojis extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'emojis';

    protected $fillable = [
        'id',
        'type',
        'telegram_id',
        'name',
        'unicode',
        'image_path',
        'file_id',
        'owner_type',
        'owner_id',
        'set_name',
        'tags',
        'source',
        'usage_count',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'type',
        'telegram_id',
        'name',
        'unicode',
        'image_path',
        'set_name',
        'source',
        'usage_count',
        'created_at',
        'updated_at'
    ];

    protected array $form = [
        'type',
        'telegram_id',
        'name',
        'unicode',
        'image_path',
        'set_name',
        'tags',
        'source'
    ];

    // 注意：不能写 protected array $casts —— 父类 Model 的 $casts 没有类型声明，
    // 子类加类型会触发 "Type of ...::$casts must be omitted to match the parent definition"。
    protected $casts = [
        'tags' => 'array',
    ];

    public array $searchable = [
        'name' => 'like',
        'unicode' => 'like',
        'type' => '=',
        'telegram_id' => '=',
        'set_name' => 'like',
    ];

    /**
     * 素材类型选项（给前端下拉用）
     */
    public static function types(): array
    {
        return [
            ['label' => '官方 emoji', 'value' => 'unicode'],
            ['label' => '自定义/动态 emoji', 'value' => 'custom_emoji'],
            ['label' => '贴纸', 'value' => 'sticker'],
            ['label' => '消息特效', 'value' => 'message_effect'],
        ];
    }

    protected bool $isPaginate = true;
}
