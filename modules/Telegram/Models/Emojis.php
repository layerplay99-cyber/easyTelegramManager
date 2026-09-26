<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Catch\Base\CatchModel as Model;

/**
 * @property int $id
 * @property string $name
 * @property string $unicode
 * @property string $image_path
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Emojis extends Model
{
    protected $table = 'emojis';

    protected $fillable = [
        'id',
        'name',
        'unicode',
        'image_path',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'name',
        'unicode',
        'image_path',
        'created_at',
        'updated_at'
    ];

    protected array $form = [
        'name',
        'unicode',
        'image_path'
    ];

    public array $searchable = [
        'name' => 'like',
        'unicode' => 'like',
    ];

    protected bool $isPaginate = true;
}
