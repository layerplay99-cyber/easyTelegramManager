<?php

declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Modules\Permissions\Support\MerchantContext;
use Modules\Telegram\Models\Plugin;
use Modules\Telegram\Models\PluginSetting;
use Modules\Telegram\Services\Plugin\PluginManager;

/**
 * 功能插件管理（上传安装 / 停用启用 / 卸载 / 审核设置）
 *
 * 只给平台超管：插件代码运行在平台进程内，能读写所有商户的数据，
 * 商户自己上传等同于给自己开后台，必须由平台侧把关。
 *
 * 校验放 action 里，构造函数不读登录态（route:cache / route:list 也会实例化控制器）。
 */
class PluginsController extends Controller
{
    public function __construct(
        protected readonly PluginManager $manager
    ) {
    }

    /**
     * 已安装 / 已停用 / 被拒的插件列表
     */
    public function index(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        $query = Plugin::query()->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($keyword = trim((string) $request->input('name', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('slug', 'like', "%{$keyword}%"));
        }

        $paginator = $query->paginate((int) $request->input('per_page', 15));

        // api_key 不回传明文，避免前端一打开页面就把密钥打到浏览器
        $paginator->getCollection()->transform(fn (Plugin $p) => $p->toArray());

        return $paginator;
    }

    /**
     * 上传并安装插件包
     */
    public function store(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        $file = $request->file('file');

        if (! $file) {
            throw new FailedException('请选择插件 zip 包');
        }

        $plugin = $this->manager->install(
            $file,
            (int) (auth(getGuardName())->id() ?? 0),
            $request->boolean('force')
        );

        return $plugin;
    }

    /**
     * 启用 / 停用
     */
    public function update(string $slug, Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        $plugin = $this->findBySlug($slug);
        $status = (string) $request->input('status', '');

        match ($status) {
            Plugin::STATUS_DISABLED => $this->manager->disable($plugin),
            Plugin::STATUS_INSTALLED => $this->manager->enable($plugin),
            default => throw new FailedException('status 只能是 installed / disabled'),
        };

        return $plugin->refresh();
    }

    /**
     * 卸载（同时删除该插件的功能与绑定）
     */
    public function destroy(string $slug): mixed
    {
        $this->assertPlatformSuperAdmin();

        $this->manager->uninstall($this->findBySlug($slug));

        return response()->json(['success' => true]);
    }

    /**
     * 单个插件详情（含审核明细）
     */
    public function show(string $slug): mixed
    {
        $this->assertPlatformSuperAdmin();

        return $this->findBySlug($slug);
    }

    /**
     * 审核设置（AI 开关与模型配置）
     */
    public function settings(): mixed
    {
        $this->assertPlatformSuperAdmin();

        $setting = PluginSetting::current();

        return [
            'ai_enabled' => (int) $setting->ai_enabled,
            'ai_base_url' => (string) $setting->ai_base_url,
            'ai_model' => (string) $setting->ai_model,
            'ai_timeout' => (int) $setting->ai_timeout,
            // 只回「是否配过」，不回明文
            'ai_api_key_set' => trim((string) $setting->ai_api_key) !== '',
        ];
    }

    public function saveSettings(Request $request): mixed
    {
        $this->assertPlatformSuperAdmin();

        $setting = PluginSetting::current();

        $baseUrl = trim((string) $request->input('ai_base_url', $setting->ai_base_url));

        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            throw new FailedException('AI 接口地址必须以 http:// 或 https:// 开头');
        }

        $data = [
            'ai_enabled' => $request->boolean('ai_enabled') ? 1 : 0,
            'ai_base_url' => rtrim($baseUrl, '/'),
            'ai_model' => trim((string) $request->input('ai_model', $setting->ai_model)) ?: 'gpt-4o-mini',
            'ai_timeout' => max(10, min(120, (int) $request->input('ai_timeout', $setting->ai_timeout))),
            'updated_by' => (int) (auth(getGuardName())->id() ?? 0),
        ];

        $apiKey = trim((string) $request->input('ai_api_key', ''));

        if ($apiKey !== '' && $apiKey !== '******') {
            $data['ai_api_key'] = $apiKey;
        }

        if ((int) $data['ai_enabled'] === 1 && trim((string) ($data['ai_api_key'] ?? $setting->ai_api_key)) === '') {
            throw new FailedException('开启 AI 审核前请先填写 API Key');
        }

        $setting->forceFill($data)->save();

        return $this->settings();
    }

    protected function findBySlug(string $slug): Plugin
    {
        $plugin = Plugin::query()->where('slug', $slug)->first();

        if (! $plugin) {
            throw new FailedException('插件不存在：' . $slug);
        }

        return $plugin;
    }

    protected function assertPlatformSuperAdmin(): void
    {
        if (! MerchantContext::isPlatformSuperAdmin()) {
            throw new FailedException('仅平台超管可管理插件');
        }
    }
}
