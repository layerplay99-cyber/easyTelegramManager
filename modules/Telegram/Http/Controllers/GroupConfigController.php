<?php

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Modules\Permissions\Support\DataScope;
use Modules\Telegram\Models\BotGroups;
use Modules\Telegram\Models\GroupConfigs;
use Modules\Telegram\Services\MerchantRouteService;

class GroupConfigController extends CatchController
{
    public function __construct(
        protected readonly GroupConfigs $groupConfigs,
    )
    {

    }

    public function index(Request $request)
    {
        return $this->groupConfigs->getList();
    }

    /**
     * 保存（按群幂等）
     *
     * groupId 上有唯一索引（一个群只能有一行配置），而前端是「没有 configId 就 POST」，
     * 以前 show() 按主键查拿不到 id，于是每次都走 POST → 1062 重复键。
     * 这里按 groupId 先找再更新，避免重复插入。
     */
    public function store(Request $request)
    {
        $data = $this->normalizeData($request);

        $groupId = (int) ($data['groupId'] ?? 0);

        $existing = $groupId > 0
            ? GroupConfigs::query()->where('groupId', $groupId)->first()
            : null;

        return $existing
            ? $this->groupConfigs->updateBy($existing->id, $data)
            : $this->groupConfigs->storeBy($data);
    }

    /**
     * 取某个群的配置
     *
     * ⚠️ 这里的 $id 是 **bot_groups.id（groupId）**，不是 group_configs 的主键：
     * 前端「群列表 → 配置」传的是群行的 id。以前按主键 firstBy() 查，
     * 等于拿群 id 去匹配另一张表的主键，结果要么空、要么返回别的群的配置，
     * 表现就是「明明绑过商户号，配置弹窗里却什么都没有」。
     *
     * 没建过配置时也返回一个壳（带上生效中的商户号），保证弹窗能正常回填。
     */
    public function show($id)
    {
        $groupId = (int) $id;

        $config = GroupConfigs::query()->where('groupId', $groupId)->first();

        if (! $config) {
            return [
                'id' => null,
                'groupId' => $groupId,
                'mid' => $this->effectiveMerchantId($groupId),
            ];
        }

        app(DataScope::class)->assertVisible($config);

        // 配置里没填商户号时，显示 /bd 绑定上的那个（两处指的是同一个东西）
        if (trim((string) $config->mid) === '') {
            $config->mid = $this->effectiveMerchantId($groupId);
        }

        return $config;
    }

    public function update($id, Request $request)
    {
        return $this->groupConfigs->updateBy($id, $this->normalizeData($request));
    }

    public function destroy($id)
    {
        return $this->groupConfigs->deleteBy($id);
    }

    /**
     * 前端提交的是 group_id，模型字段是 groupId（DB 列名就是 groupId）。
     * 不一致时 group_id 会被 filterData() 当成非白名单字段丢掉，
     * 于是配置存进去了却没挂在任何群上 —— 下次打开自然读不到。
     */
    protected function normalizeData(Request $request): array
    {
        $data = $request->all();

        if (! isset($data['groupId']) && isset($data['group_id'])) {
            $data['groupId'] = $data['group_id'];
        }

        // ---- 基础能力开关与客服设置 ----
        // 开关一律转 0/1（前端可能传 true/false 或 "1"/"0"）
        foreach (['translate_enabled', 'ocr_enabled', 'notify_upstream'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }

        // 关键词：去空白、去空串、去重
        if (array_key_exists('service_keywords', $data)) {
            $keywords = is_array($data['service_keywords']) ? $data['service_keywords'] : [];

            $data['service_keywords'] = array_values(array_unique(array_filter(
                array_map(fn ($k) => trim((string) $k), $keywords),
                fn ($k) => $k !== ''
            )));
        }

        // 客服：只留 id / username，username 去掉 @ 前缀
        if (array_key_exists('customers', $data)) {
            $customers = is_array($data['customers']) ? $data['customers'] : [];

            $data['customers'] = array_values(array_filter(array_map(
                fn ($c) => [
                    'id' => (int) ($c['id'] ?? 0),
                    'username' => ltrim(trim((string) ($c['username'] ?? '')), '@'),
                ],
                $customers
            ), fn ($c) => $c['id'] > 0 || $c['username'] !== ''));
        }

        // 开启上游业务通知的前提：群已绑定商户号（只认 merchant_routes 路由表）
        if (! empty($data['notify_upstream'])) {
            $chatId = (string) (BotGroups::query()
                ->whereKey((int) ($data['groupId'] ?? 0))
                ->value('chat_id') ?? '');

            $bound = $chatId === '' ? [] : app(MerchantRouteService::class)->forChat($chatId);

            if ($bound === []) {
                throw new FailedException('该群尚未绑定商户号，请先在群内用绑定命令绑定商户号后再开启业务通知');
            }
        }

        return $data;
    }

    /**
     * 该群当前生效的商户号（/bd 绑定写在 merchant_routes 里）
     */
    protected function effectiveMerchantId(int $groupId): ?string
    {
        $chatId = BotGroups::query()->whereKey($groupId)->value('chat_id');

        if (! $chatId) {
            return null;
        }

        $bound = app(MerchantRouteService::class)->forChat((string) $chatId);

        return $bound[0] ?? null;
    }
}
