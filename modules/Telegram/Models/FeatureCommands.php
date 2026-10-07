<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 功能斜杠命令
 *
 * 命令名从 features.feature 独立成实体后可加唯一约束，
 * 且能声明多个参数（必填/正则），不再受 PHP 类里 params() 硬编码限制。
 *
 * @property int $id
 * @property int $feature_id
 * @property string $command
 * @property string|null $usage
 * @property string|null $description
 * @property string $scope
 * @property string $permission
 * @property array|null $params
 * @property string|null $reply_template
 * @property bool $enabled
 */
class FeatureCommands extends Model
{
    protected $table = 'feature_commands';

    protected $fillable = ['id','feature_id','command','usage','description','scope','permission','params','reply_template','enabled'];

    protected $casts = [
        'params' => 'array',
        'enabled' => 'boolean',
    ];

    protected array $fields = ['id','feature_id','command','usage','description','scope','permission','enabled','created_at'];

    protected array $form = ['feature_id','command','usage','description','scope','permission','params','reply_template','enabled'];

    public array $searchable = ['command' => 'like','feature_id' => '='];

    protected bool $isPaginate = true;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Features::class, 'feature_id', 'id');
    }
}