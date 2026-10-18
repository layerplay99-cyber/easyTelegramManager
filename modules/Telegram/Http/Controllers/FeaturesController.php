<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Permissions\Support\DataScope;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\Bots;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\FeatureHookRoutes;
use Modules\Telegram\Models\FeatureHooks;
use Modules\Telegram\Services\Callback\TransformerRegistry;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\ThirdApiConfig;
use Modules\Telegram\Models\ThirdApiEndpoints;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;
use Modules\Telegram\Services\Feature\DriverRegistry;
use Modules\Telegram\Services\Feature\PlatformEndpointRegistry;


class FeaturesController extends Controller
{
    public function __construct(
        protected readonly Features $model
    ){}

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): mixed
    {
        // 自动扫描：Drivers/Custom/ 下新写的功能代码在此自动同步进数据库，
        // 后台「功能列表」打开即可见。
        //
        // 两种情况都要触发，否则「改了代码但刷新后台还是旧内容」：
        //   1) hasPending()   —— 库里还没有的新功能类
        //   2) 指纹变化       —— 已登记的功能改了声明（描述/名称/分类/命令名）
        // 指纹没变就不写库，避免每次打开列表都同步一遍。
        $custom = app(CustomFeatureRegistry::class);
        $fingerprintKey = 'telegram.custom_features.fingerprint';

        // 这个列表页以前不碰缓存，现在依赖它判断要不要同步。缓存（线上是 Redis）
        // 抖动时不能让页面 500：读不到指纹就按「需要同步」处理，写不进去也只是
        // 下次打开再同步一次——sync() 是幂等的，两条路都安全。
        try {
            $cached = Cache::get($fingerprintKey);
        } catch (\Throwable $e) {
            $cached = null;
        }

        if ($custom->hasPending() || $cached !== $custom->fingerprint()) {
            $custom->sync();

            try {
                Cache::forever($fingerprintKey, $custom->fingerprint());
            } catch (\Throwable $e) {
                // 忽略：本次已同步成功
            }
        }

        // 平台接口也要一起自动扫描：接口以前只在跑
        // php artisan telegram:sync-features 时才进库，导致自己写了 endpoints()
        // 却不知道为什么后台「上游接口配置」里没有。逻辑与上面一致——
        // 指纹变了才写库，缓存挂了也不让列表页 500。
        $endpointKey = 'telegram.platform_endpoints.fingerprint';
        $endpointFingerprint = PlatformEndpointRegistry::fingerprint();

        try {
            $cachedEndpoint = Cache::get($endpointKey);
        } catch (\Throwable $e) {
            $cachedEndpoint = null;
        }

        if ($cachedEndpoint !== $endpointFingerprint) {
            PlatformEndpointRegistry::sync();

            try {
                Cache::forever($endpointKey, $endpointFingerprint);
            } catch (\Throwable $e) {
                // 忽略：本次已同步成功
            }
        }

        return $this->model->setBeforeGetList(function ($query) use ($request) {
            if ($category = $request->input('category')) {
                $query->where('category', $category);
            }

            if ($request->query('mode') === 'multiple') {
                $query->select(['id', 'name', 'category']);
            }

            return $query;
        })->getList()->through(function ($feature) {
            //附带展示用的中文名：执行器名 + 触发方式名。
            // 由后端直接给出，避免前端再发一次 drivers 请求导致显示英文 key。
            $driverLabels = DriverRegistry::labelMap();
            $feature->driver_label = $driverLabels[$feature->driver] ?? $feature->driver;
            $feature->trigger_label = $feature->trigger;

            return $feature;
        });
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request): mixed
    {
        return $this->model->storeBy($request->all());
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    /**
     * @param int|string $id
     * @param Request $request
     * @return mixed
     */
    public function update(int|string $id, Request $request): mixed
    {
        return $this->model->updateBy($id, $request->all());
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
    }

    /**
     * 可选执行器清单（后台表单的数据源）
     *
     * 返回每个执行器的 key / 名称 / 分组 / 触发方式 / 配置项 schema，
     * 前端据此自动渲染表单，不再手写 JSON、不再手敲 handler 类名。
     */
    public function drivers(): JsonResponse
    {
        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => DriverRegistry::definitions(),
        ]);
    }

    /**
     * 自定义功能清单（Drivers/Custom 目录下继承 BaseCustomFeature 的类）
     */
    public function customFeatures(): JsonResponse
    {
        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => app(CustomFeatureRegistry::class)->definitions(),
        ]);
    }

    /**
     * 配置表单的动态选项源
     *
     * 前端遇到 source 为数据库来源（三方配置/接口）的字段时，按 source 拉一次：
     *   GET telegram/features/options?source=third_api_configs
     *   GET telegram/features/options?source=third_api_endpoints
     */
    public function options(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', '');

        // 平台接口下拉支持带 third_config_id：带上后括号里显示该上游实际生效的
        // method + path（含「上游接口配置」里的定制），而不是平台默认的示例值。
        $thirdConfigId = (int) $request->query('third_config_id', 0);

        $options = match ($source) {
            'third_api_configs' => ThirdApiConfig::query()
                ->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                ->toArray(),
            'third_api_endpoints', 'platform_endpoints' => PlatformEndpointRegistry::optionsForUpstream(
                $thirdConfigId > 0 ? $thirdConfigId : null
            ),
            'sign_algos' => [
                ['value' => 'hmac_sha256', 'label' => 'HMAC-SHA256（推荐）'],
                ['value' => 'md5', 'label' => 'MD5 + key 后缀（老上游）'],
            ],
            'parse_modes' => [
                ['value' => '', 'label' => '纯文本'],
                ['value' => 'HTML', 'label' => 'HTML'],
                ['value' => 'Markdown', 'label' => 'Markdown'],
                ['value' => 'MarkdownV2', 'label' => 'MarkdownV2'],
            ],
            // 群下拉同样要按数据范围收窄：否则部门管理员在配功能时
            // 能把功能绑到（并看到）别的部门的群。
            'bot_groups' => app(DataScope::class)->apply(
                BotGroups::query()->where('enabled', true),
                null,
                'telegram',
                'creator_id'
            )
                ->get(['id', 'name', 'chat_id'])
                ->map(fn ($g) => [
                    'value' => $g->chat_id,
                    'label' => "{$g->name}（{$g->chat_id}）",
                ])
                ->toArray(),
            // 机器人下拉同样按数据范围收窄：裸 query() 不会触发 DataRange，
            // 必须显式套一次，否则部门管理员能选到别的部门的机器人。
            'bots' => app(DataScope::class)
                ->apply(Bots::query(), null, 'telegram', 'creator_id')
                ->get(['id', 'username'])
                ->map(fn ($b) => [
                    'value' => $b->id,
                    'label' => "{$b->username}（#{$b->id}）",
                ])
                ->toArray(),
            // 回调消息转换器：全局代码资产，所有人都能看到有哪些，
            // 但「能不能给这个功能换」由归属链说了算（见 transformer / saveTransformer）
            'transformers' => TransformerRegistry::options(),
            default => [],
        };

        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => $options,
        ]);
    }

    /**
     * 功能的命令列表
     *
     * 带 id：返回该功能下的命令（编辑单个功能时用）
     * 不带 id：返回「功能ID => 命令列表」的映射（列表页一次拿全部，避免 N+1 请求）
     */
    public function commands(null|int|string $id = null): JsonResponse
    {
        // 批量：一次返回全部命令映射
        if ($id === null || $id === '') {
            $grouped = FeatureCommands::query()
                ->orderByDesc('id')
                ->get()
                ->groupBy('feature_id');

            $map = [];

            foreach ($grouped as $featureId => $rows) {
                $map[(string) $featureId] = $rows->toArray();
            }

            return response()->json($map);
        }

        return response()->json(
            FeatureCommands::query()
                ->where('feature_id', $id)
                ->orderByDesc('id')
                ->get()
                ->toArray()
        );
    }

    /**
     * 该功能的回调消息转换器
     *
     * 转换器是全局代码资产（所有人都能看到列表），
     * 但「能不能给这个功能换」按归属链判：只有功能的创建者 + 他创建的账号（递归）、
     * 以及超管能改，跟角色/部门/岗位的层级规则一致。
     */
    public function transformer(int|string $id): JsonResponse
    {
        $feature = Features::query()->findOrFail($id);

        $current = (string) data_get($feature->config, 'transformer', '');

        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => [
                'current' => $current,
                'options' => TransformerRegistry::options(),
                'can_edit' => $this->canEditFeature($feature),
                // 配着的转换器已经被删掉了：当前实际在跑的是默认
                'missing' => $current !== '' && ! TransformerRegistry::has($current),
            ],
        ]);
    }

    /**
     * 保存该功能的回调消息转换器
     *
     * 走独立接口而不是复用 update：只改一个字段、鉴权范围最小，
     * 不动现有「编辑功能」的权限语义。
     */
    public function saveTransformer(Request $request, int|string $id): JsonResponse
    {
        $data = $request->validate([
            'transformer' => 'nullable|string|max:64',
        ]);

        $feature = Features::query()->findOrFail($id);

        $key = trim((string) ($data['transformer'] ?? ''));

        if ($key !== '' && ! TransformerRegistry::has($key)) {
            return response()->json([
                'code' => 10002,
                'message' => '转换器不存在：' . $key . '（可能已被删除，请重新选择）',
                'data' => null,
            ]);
        }

        app(DataScope::class)->assertInOwnershipChain(
            $feature,
            $this->getLoginUser(),
            '只能修改自己及下级创建的功能'
        );

        $config = is_array($feature->config) ? $feature->config : [];

        if ($key === '') {
            unset($config['transformer']);
        } else {
            $config['transformer'] = $key;
        }

        $feature->config = $config;
        $feature->save();

        return response()->json([
            'code' => 10000,
            'message' => '已保存',
            'data' => [
                'current' => $key,
                'can_edit' => $this->canEditFeature($feature),
                'missing' => false,
            ],
        ]);
    }

    /**
     * 当前登录用户能不能改这个功能
     */
    protected function canEditFeature(Features $feature): bool
    {
        return app(DataScope::class)->inOwnershipChain($feature, $this->getLoginUser(), false);
    }

    /**
     * 该功能的回调入口（三方推送）
     *
     * 一个功能一个入口；没有则返回 null，由后台点「创建回调入口」生成。
     */
    public function hook(int|string $id): JsonResponse
    {
        $hook = FeatureHooks::query()->where('feature_id', $id)->first();

        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => $hook ? $hook->toArray() + ['url' => $this->hookUrl($hook)] : null,
        ]);
    }

    /**
     * 创建 / 更新回调入口
     *
     * token 只在首次创建时生成，之后不再变动——上游已经配好了回调地址，
     * 中途换 token 等于把线上回调全部打断。
     */
    public function saveHook(Request $request, int|string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
            'dedup_field' => 'nullable|string|max:128',
            'secret' => 'nullable|string|max:255',
            'sign_field' => 'nullable|string|max:64',
            'sign_algo' => 'nullable|string|max:32',
            'check_timestamp' => 'nullable|boolean',
            // 多机器人：默认用哪个机器人发
            'bot_id' => 'nullable|integer',
            // 从报文里取哪个字段做路由键（商户ID）
            'route_field' => 'nullable|string|max:128',
            // 未命中路由表时的兜底群
            'default_chat_id' => 'nullable|string|max:64',
        ]);

        $hook = FeatureHooks::query()->where('feature_id', $id)->first();

        if (! $hook) {
            $data['feature_id'] = (int) $id;
            $data['token'] = Str::random(32);
            $data['enabled'] = (bool) ($data['enabled'] ?? true);

            $hook = FeatureHooks::query()->create($data);
        } else {
            $hook->fill($data)->save();
        }

        return response()->json([
            'code' => 10000,
            'message' => '回调入口已保存',
            'data' => $hook->toArray() + ['url' => $this->hookUrl($hook)],
        ]);
    }

    /**
     * 回调路由表：路由键值（商户ID）→ 机器人 + 群
     */
    public function hookRoutes(int|string $id): JsonResponse
    {
        $hook = FeatureHooks::query()->where('feature_id', $id)->first();

        return response()->json([
            'code' => 10000,
            'message' => 'success',
            'data' => $hook
                ? FeatureHookRoutes::query()->where('hook_id', $hook->id)->orderByDesc('id')->get()->toArray()
                : [],
        ]);
    }

    /**
     * 批量保存回调路由表（提交什么就是什么，未提交的删除）
     *
     * 逐条校验「群属于机器人」：配错了宁可当场报错，
     * 也好过回调来时才发现要发到别人的群。
     */
    public function saveHookRoutes(Request $request, int|string $id): JsonResponse
    {
        $rows = $request->validate([
            'routes' => 'nullable|array',
            'routes.*.route_value' => 'required|string|max:128',
            'routes.*.bot_id' => 'required|integer',
            'routes.*.chat_id' => 'required|string|max:64',
            'routes.*.remark' => 'nullable|string|max:255',
            'routes.*.enabled' => 'nullable|boolean',
        ])['routes'] ?? [];

        $hook = FeatureHooks::query()->where('feature_id', $id)->first();

        if (! $hook) {
            return response()->json([
                'code' => 10001,
                'message' => '请先创建回调入口',
                'data' => null,
            ]);
        }

        $errors = [];

        foreach ($rows as $i => $row) {
            $belongs = BotGroups::query()
                ->where('bot_id', (int) $row['bot_id'])
                ->where('chat_id', (string) $row['chat_id'])
                ->exists();

            if (! $belongs) {
                $errors[] = sprintf(
                    '第 %d 行：群 %s 不属于所选机器人（请确认群已同步、且机器人确实在该群里）',
                    $i + 1,
                    $row['chat_id']
                );
            }
        }

        if ($errors) {
            return response()->json([
                'code' => 10002,
                'message' => implode('；', $errors),
                'data' => null,
            ]);
        }

        $keep = collect($rows)->pluck('route_value')->filter()->unique()->all();

        FeatureHookRoutes::query()
            ->where('hook_id', $hook->id)
            ->when($keep, fn ($q) => $q->whereNotIn('route_value', $keep))
            ->delete();

        foreach ($rows as $row) {
            FeatureHookRoutes::query()->updateOrCreate(
                ['hook_id' => $hook->id, 'route_value' => $row['route_value']],
                [
                    'bot_id' => (int) $row['bot_id'],
                    'chat_id' => (string) $row['chat_id'],
                    'remark' => $row['remark'] ?? null,
                    'enabled' => (bool) ($row['enabled'] ?? true),
                ]
            );
        }

        return response()->json([
            'code' => 10000,
            'message' => '路由表已保存',
            'data' => FeatureHookRoutes::query()->where('hook_id', $hook->id)->orderByDesc('id')->get()->toArray(),
        ]);
    }

    /**
     * 上游要填的回调地址
     */
    protected function hookUrl(FeatureHooks $hook): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/hooks/' . $hook->token;
    }

    /**
     * 保存功能的命令列表
     */
    public function saveCommands(Request $request, int|string $id): JsonResponse
    {
        // 没带 commands 字段 = 客户端没提交这块，直接拒绝。
        // 否则前端加载命令失败时会提交空数组，下面的删除逻辑会把该功能的命令全部清空。
        if (! $request->has('commands')) {
            return response()->json(['message' => '缺少 commands 参数，未做任何改动'], 400);
        }

        $commands = (array) $request->input('commands', []);

        // 先删掉该功能下未被提交的命令，实现「删改」语义
        $keep = collect($commands)->pluck('command')->filter()->all();

        FeatureCommands::query()
            ->where('feature_id', $id)
            ->when($keep, fn ($q) => $q->whereNotIn('command', $keep))
            ->delete();

        foreach ($commands as $command) {
            if (empty($command['command'])) {
                continue;
            }

            // 必须带 feature_id 一起匹配：只按 command 名匹配会把别的功能的
            // 同名命令行抢过来（连带把它的 feature_id 改成当前功能）。
            FeatureCommands::query()->updateOrCreate(
                ['feature_id' => $id, 'command' => $command['command']],
                [
                    'feature_id' => $id,
                    'command' => $command['command'],
                    'usage' => $command['usage'] ?? null,
                    'description' => $command['description'] ?? null,
                    'scope' => $command['scope'] ?? 'group',
                    'permission' => $command['permission'] ?? 'all',
                    'params' => $command['params'] ?? [],
                    'reply_template' => $command['reply_template'] ?? null,
                    'enabled' => (bool) ($command['enabled'] ?? true),
                ]
            );
        }

        return response()->json([
            'code' => 10000,
            'message' => '命令已保存',
            'data' => null,
        ]);
    }
}
