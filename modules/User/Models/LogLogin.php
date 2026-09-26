<?php

namespace Modules\User\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class LogLogin extends Model
{
    protected $table = 'log_login';

    public $timestamps = false;

    protected $fillable = [
        'id', 'account', 'login_ip', 'browser', 'platform', 'login_at', 'status',
    ];


    protected $casts = [
        'login_at' => 'datetime:Y-m-d H:i'
    ];

    /**
     *
     * @param ?string $email
     * @return LengthAwarePaginator
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getUserLogBy(?string $email): LengthAwarePaginator
    {
        // limit 直接取自请求参数且没有上限，?limit=999999 会一次性把整表读进内存
        $limit = (int) request()->get('limit', 15);
        $limit = min(max($limit, 1), 100);

        return static::when($email, function ($query) use ($email){
                            $query->where('account', $email);
                        })
                        ->orderByDesc('id')
                        ->paginate($limit);
    }
}
