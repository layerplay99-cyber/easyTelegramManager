<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $phone
 * @property int $status
 * @property string|null $scantime
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \Modules\User\Models\User $creator
 */
class Phones extends Model
{
    protected $table = 'phones';

    protected $fillable = [
        'id',
        'phone',
        'status',
        'scantime',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'id',
        'phone',
        'status',
        'scantime',
        'created_at'
    ];

    /**
     * @var array
     */
    protected array $form = [
        'phone',
        'status'
    ];

    /**
     * @var array
     */
    public array $searchable = [
        'phone' => 'like',
        'status' => '=',
    ];

    protected bool $isPaginate = true;

    // 关联关系
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\Modules\User\Models\User::class, 'creator_id', 'id');
    }
}
