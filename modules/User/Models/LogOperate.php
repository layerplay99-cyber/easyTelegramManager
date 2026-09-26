<?php

namespace Modules\User\Models;

use Catch\CatchAdmin;
use Catch\Traits\DB\BaseOperate;
use Catch\Traits\DB\ScopeTrait;
use Catch\Traits\DB\Trans;
use Catch\Traits\DB\WithAttributes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogOperate extends Model
{
    use BaseOperate, Trans, ScopeTrait, WithAttributes;

    protected $table = 'log_operate';

    protected $fillable = [
        'id',
        'module',
        'action',
        'params',
        'ip',
        'http_method',
        'http_code',
        'start_at',
        'time_taken',
        'creator_id',
        'created_at',
    ];

    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function log(Request $request, Response $response): void
    {
        $user = Auth::guard(getGuardName())->user();

        $userModel = getAuthUserModel();

        if (! $user instanceof $userModel) {
            return;
        }

        [$module, $controller, $action] = CatchAdmin::parseFromRouteAction();

        $requestStartAt = app(Kernel::class)->requestStartedAt()->getPreciseTimestamp(3);

        // 敏感字段必须脱敏后再落库：原来直接 $request->all() 全量写入，
        // 登录/改密/绑定 2FA 时 password、code、pending_token 都会以明文留在日志里，
        // 而操作日志又可以被任意登录用户读取（配合旧的 scope=all 越权危害更大）。
        $params = $this->sanitizeParams($request->all());

        // 如果参数过长则不记录
        if (!empty($params)) {
            if (strlen(\json_encode($params, JSON_UNESCAPED_UNICODE)) > 5000) {
                $params = [];
            }
        }

        $timeTaken = intval(microtime(true) * 1000 - $requestStartAt);
        $this->storeBy([
            'module' => $module,
            'action' => $controller . '@' . $action,
            'creator_id' => $user->id,
            'http_method' => $request->method(),
            'http_code' => $response->getStatusCode(),
            'start_at' => intval($requestStartAt/1000),
            'time_taken' => $timeTaken,
            'ip' => $request->ip(),
            'params' => \json_encode($params, JSON_UNESCAPED_UNICODE),
            'created_at' => time()
        ]);
    }

    /**
     * 递归脱敏请求参数中的敏感字段
     *
     * @param array $params
     * @return array
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'old_password',
            'new_password',
            'current_password',
            'code',
            'two_factor_code',
            'token',
            'api_token',
            'access_token',
            'refresh_token',
            'pending_token',
            'secret',
            'api_key',
            'app_hash',
        ];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->sanitizeParams($value);

                continue;
            }

            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $params[$key] = '******';
            }
        }

        return $params;
    }

    /**
     *
     * @return Attribute
     */
    protected function timeTaken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value > 1000 ? intval($value/1000) . 's' : $value . 'ms',
        );
    }
}
