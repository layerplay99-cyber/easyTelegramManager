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
        'access_key',
        'secret_key',
        'key_status',
        'key_version',
        'key_rotated_at',
        'tenant_id',
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
        'access_key',
        'key_status',
        'key_version',
        'key_rotated_at',
        'tenant_id',
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

    /**
     * 平台级配置缓存只放运行必需的非敏感字段
     *
     * 原来用 static::all()->toArray()，把 token / public_key / secrept_key /
     * 加密后的 secret_key 全量写进 Cache::forever，等于在缓存里再放一份凭证。
     * 这个键没有消费方（各功能按 id 直接查模型），保留它只是兼容
     * ConfigCacheServiceProvider 的「缺键才重建」判断，因此不做按租户分键，
     * 只做字段白名单。group_configs 属于群发链路，不动。
     */
    public static function refreshCache(): void
    {
        // 只 select 迁移前后都一定存在的列：本方法也会在 saved/deleted 事件里触发，
        // 加 tenant_id 会让「表已存在但新迁移还没跑」的老库直接 Unknown column，
        // 连带 catch:migrate 都起不来。
        $formatted = static::query()
            ->get(['id', 'name', 'api_url', 'created_at', 'updated_at'])
            ->mapWithKeys(fn ($item) => [$item->id => $item->toArray()])
            ->toArray();

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
        // 一次性下发的入站密钥：密文也不能出现在 show 响应里
        $item->secret_key = self::MASKED_VALUE;

        return $item;
    }
}
