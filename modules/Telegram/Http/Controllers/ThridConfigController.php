<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Modules\Permissions\Support\DataScope;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Services\Security\UpstreamKeyService;
use Modules\Telegram\Services\Security\UpstreamSignature;

class ThridConfigController extends Controller
{
    public function __construct(
        protected readonly ThirdApiConfig $model
    ){}

    /**
     * @return mixed
     */
    public function index(): mixed
    {
        return $this->model->getList();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        return $this->model->storeBy($request->all());
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        return $this->model->firstBy($id);
    }

    /**
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function update($id, Request $request)
    {
        // 参数是掩码则不更新该字段，避免把展示用的 ****** 写回库
        $data = $request->all();
        foreach (['token', 'secrept_key', 'secret_key'] as $field) {
            if (isset($data[$field]) && $data[$field] === '******************') {
                unset($data[$field]);
            }
        }
        $request->replace($data);
        return $this->model->updateBy($id, $request->all());
    }

    /**
     * 生成 / 轮换三方入站密钥对
     *
     * 明文 secret_key 只在本次响应返回一次，之后任何列表 / 详情都只给掩码。
     * 只有平台超管可以下发；商户用户不允许给自己签发接入凭证。
     */
    public function generateKeys($id): array
    {
        $config = $this->upstreamForAdmin($id);

        $pair = app(UpstreamKeyService::class)->generate($config);

        return [
            'notice' => '请立即保存接入密钥，关闭后不再显示明文',
            'access_key' => $pair['access_key'],
            'secret_key' => $pair['secret_key'],
            'key_version' => $pair['key_version'],
            'key_rotated_at' => $pair['key_rotated_at'],
            'headers' => [
                UpstreamSignature::HEADER_ACCESS_KEY,
                UpstreamSignature::HEADER_TIMESTAMP,
                UpstreamSignature::HEADER_NONCE,
                UpstreamSignature::HEADER_SIGNATURE,
            ],
            'canonical' => "METHOD\nPATH?SORTED_QUERY\nTIMESTAMP\nNONCE\nBODY_SHA256",
            'signature' => 'HMAC-SHA256(canonical, secret_key) 的小写 hex',
            'timestamp_ttl' => UpstreamSignature::TIMESTAMP_TTL,
            'nonce_ttl' => UpstreamSignature::NONCE_TTL,
        ];
    }

    public function revokeKey($id): array
    {
        $config = $this->upstreamForAdmin($id);

        app(UpstreamKeyService::class)->revoke($config);

        return ['status' => 'revoked', 'key_version' => (int) $config->key_version];
    }

    /**
     * 取原始模型（firstBy 会 mask，不能拿来 save），并做越权校验
     */
    private function upstreamForAdmin($id): ThirdApiConfig
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('只有平台超管可以下发接入密钥');
        }

        $config = ThirdApiConfig::query()->findOrFail($id);

        app(DataScope::class)->assertVisible($config);

        return $config;
    }

    /**
     * @param $id
     * @return mixed
     */
    public function destroy($id)
    {
        return $this->model->deleteBy($id);
    }
}
