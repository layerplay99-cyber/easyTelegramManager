<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $api_url
 * @property string $token
 * @property string $public_key
 * @property string $secrept_key
 * @property int $creator_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class ThirdApiConfig extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'thirdapi_config';

    protected $fillable = [
        'id',
        'name',
        'api_url',
        'token',
        'public_key',
        'secrept_key',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected array $fields = [
        'id',
        'name',
        'api_url',
        'token',
        'public_key',
        'secrept_key',
        'created_at',
        'updated_at'
    ];

    protected array $form = [
        'name',
        'api_url',
        'token',
        'public_key',
        'secrept_key'
    ];

    public array $searchable = [
        'name' => 'like',
    ];

    protected bool $isPaginate = true;

    private const MASKED_VALUE = '******************';

    public function getList(array $columns = ['*']): mixed
    {
        $data = parent::getList($columns);

        return match(true) {
            $data instanceof LengthAwarePaginator => $this->maskPaginatedData($data),
            $data instanceof Collection => $this->maskCollectionData($data),
            default => $data
        };
    }

    public function firstBy($value, $field = null, array $columns = ['*']): ?\Illuminate\Database\Eloquent\Model
    {
        $data = parent::firstBy($value, $field, $columns);

        if ($data) {
            $this->maskSensitiveFields($data);
        }

        return $data;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saved(fn($model) => static::refreshCache());
        static::deleted(fn($model) => static::refreshCache());
    }

    public static function refreshCache(): void
    {
        $configs = static::all();
        $formatted = $configs->mapWithKeys(
            fn($item) => [$item->id => $item->toArray()]
        )->toArray();

        Cache::forever('third_api_configs', $formatted);
    }

    private function maskPaginatedData(LengthAwarePaginator $data): LengthAwarePaginator
    {
        $data->getCollection()->transform(
            fn($item) => $this->maskSensitiveFields($item)
        );

        return $data;
    }

    private function maskCollectionData(Collection $data): Collection
    {
        return $data->transform(
            fn($item) => $this->maskSensitiveFields($item)
        );
    }

    private function maskSensitiveFields(mixed $item): mixed
    {
        $item->token = self::MASKED_VALUE;
        $item->secrept_key = self::MASKED_VALUE;

        return $item;
    }
}
