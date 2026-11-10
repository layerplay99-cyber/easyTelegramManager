<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * 已安装的功能插件
 *
 * 代码在磁盘：modules/Telegram/Plugins/{命名空间}/
 * 本表只记录元信息与审核结果，发现逻辑扫目录，不依赖本表。
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $version
 * @property string $author
 * @property string $description
 * @property string $namespace
 * @property string $status
 * @property array|null $manifest
 * @property array|null $audit_report
 * @property array|null $ai_review
 * @property array|null $feature_ids
 * @property array|null $disabled_feature_ids
 * @property int $file_count
 * @property int $size
 * @property int $installed_by
 */
class Plugin extends Model
{
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'feature_plugins';

    protected $fillable = [
        'id', 'slug', 'name', 'version', 'author', 'description', 'namespace',
        'status', 'manifest', 'audit_report', 'ai_review', 'feature_ids',
        'disabled_feature_ids', 'file_count', 'size', 'installed_by',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected array $fields = [
        'id', 'slug', 'name', 'version', 'author', 'description', 'namespace',
        'status', 'manifest', 'audit_report', 'ai_review', 'feature_ids',
        'file_count', 'size', 'installed_by', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'audit_report' => 'array',
        'ai_review' => 'array',
        'feature_ids' => 'array',
        'disabled_feature_ids' => 'array',
        'file_count' => 'integer',
        'size' => 'integer',
    ];

    public array $searchable = [
        'name' => 'like',
        'slug' => 'like',
        'status' => '=',
    ];
}
