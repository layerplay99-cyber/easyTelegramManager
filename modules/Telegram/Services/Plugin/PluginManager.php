<?php

declare(strict_types=1);

namespace Modules\Telegram\Services\Plugin;

use Catch\Exceptions\FailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Telegram\Models\FeatureCommands;
use Modules\Telegram\Models\Features;
use Modules\Telegram\Models\Plugin;
use Modules\Telegram\Services\Feature\CommandSlots;
use Modules\Telegram\Services\Feature\CustomFeatureRegistry;
use Modules\Telegram\Services\Feature\DriverRegistry;
use ZipArchive;

/**
 * 功能插件安装器
 *
 * 插件包结构（zip 根目录，或 zip 里唯一一层文件夹下）：
 *   manifest.json       必填：slug / name；可选 version / author / description
 *   任意 *.php          功能类，命名空间必须是 Modules\Telegram\Plugins\{Studly(slug)}
 *   可选 *.md/*.txt     说明文档
 *
 * 落盘位置：modules/Telegram/Plugins/{Studly(slug)}/（停用时移到同目录 _disabled/ 下）
 * 扫描规则由 CustomFeatureRegistry / DriverRegistry 负责，所以装完立刻出现在「功能列表」。
 *
 * 安装分三步，任何一步不过都不落盘：
 *   1) 解压校验（路径穿越、文件类型、体积）
 *   2) 脚本审核（PluginAuditor，硬规则）
 *   3) AI 审核（PluginAiReviewer，后台开关；不通过可用 force 交由平台超管强制安装）
 */
class PluginManager
{
    protected const MAX_PACKAGE_SIZE = 10 * 1024 * 1024;
    protected const MAX_UNPACK_SIZE = 5 * 1024 * 1024;
    protected const MAX_FILE_COUNT = 200;
    protected const ALLOWED_EXT = ['php', 'json', 'md', 'txt'];

    public function __construct(
        protected readonly PluginAuditor $auditor,
        protected readonly PluginAiReviewer $aiReviewer,
    ) {
    }

    /**
     * 插件根目录（modules/Telegram/Plugins）
     */
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/Plugins';
    }

    /**
     * 停用插件的存放目录（注册表不扫这里）
     */
    public static function disabledRoot(): string
    {
        return self::root() . '/_disabled';
    }

    /**
     * 安装插件包
     *
     * @param bool $force AI 审核拒绝时是否强制安装（脚本审核的 error 永远不可强制）
     */
    public function install(UploadedFile $file, int $operatorId = 0, bool $force = false): Plugin
    {
        $this->assertPackage($file);

        $workspace = storage_path('app/plugins/' . (string) Str::uuid());
        File::ensureDirectoryExists($workspace);

        try {
            $this->extract($file->getRealPath(), $workspace);

            $root = $this->normalizeRoot($workspace);
            $manifest = $this->readManifest($root);

            $namespace = 'Modules\\Telegram\\Plugins\\' . Str::studly($manifest['slug']);
            $targetDir = self::root() . '/' . Str::studly($manifest['slug']);

            $this->clearStaleInstall($manifest['slug'], $targetDir);

            // ---- 1) 脚本审核 ----
            $audit = $this->auditor->scan($root, $namespace);

            if ($audit['errors'] !== []) {
                $this->recordRejected($manifest, $namespace, $audit, null, $operatorId);

                throw new FailedException('脚本审核未通过：' . $this->summarize($audit['errors']));
            }

            // ---- 2) AI 审核（开关控制）----
            $aiReview = null;

            if ($this->aiReviewer->enabled()) {
                $aiReview = $this->aiReviewer->review($root, $audit);

                if ($aiReview['verdict'] !== 'approve' && ! $force) {
                    $this->recordRejected($manifest, $namespace, $audit, $aiReview, $operatorId);

                    throw new FailedException(
                        'AI 审核未通过：' . implode('；', $aiReview['reasons'])
                        . '（确认可信可勾选「强制安装」再传一次）'
                    );
                }
            }

            // ---- 3) 落盘 + 同步功能 ----
            File::ensureDirectoryExists(dirname($targetDir));
            File::moveDirectory($root, $targetDir);

            try {
                $plugin = $this->persist($manifest, $namespace, $audit, $aiReview, $targetDir, $operatorId);
                $this->syncFeatures($plugin);
            } catch (\Throwable $e) {
                // 装了一半：目录与记录都撤掉，避免「磁盘有代码、库里没登记」
                File::deleteDirectory($targetDir);
                Plugin::query()->where('slug', $manifest['slug'])->delete();

                throw new FailedException('安装失败：' . $e->getMessage());
            }

            $this->flushRegistries();

            return $plugin->refresh();
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * 停用：目录移到 _disabled，功能全部下架（保留开关快照，启用时还原）
     */
    public function disable(Plugin $plugin): void
    {
        if ($plugin->status !== Plugin::STATUS_INSTALLED) {
            throw new FailedException('该插件当前不是启用状态');
        }

        $ids = (array) ($plugin->feature_ids ?? []);
        $snapshot = [];

        if ($ids !== []) {
            $snapshot = Features::query()->whereIn('id', $ids)->pluck('enabled', 'id')->toArray();
            Features::query()->whereIn('id', $ids)->update(['enabled' => 0]);
        }

        $dir = self::root() . '/' . Str::studly($plugin->slug);

        if (is_dir($dir)) {
            File::ensureDirectoryExists(self::disabledRoot());
            File::moveDirectory($dir, self::disabledRoot() . '/' . Str::studly($plugin->slug));
        }

        $plugin->forceFill([
            'status' => Plugin::STATUS_DISABLED,
            'disabled_feature_ids' => $snapshot,
        ])->save();

        $this->flushRegistries();
    }

    /**
     * 启用：目录移回，还原之前的开关状态
     */
    public function enable(Plugin $plugin): void
    {
        if ($plugin->status !== Plugin::STATUS_DISABLED) {
            throw new FailedException('该插件当前不是停用状态');
        }

        $disabledDir = self::disabledRoot() . '/' . Str::studly($plugin->slug);
        $dir = self::root() . '/' . Str::studly($plugin->slug);

        if (is_dir($disabledDir)) {
            File::moveDirectory($disabledDir, $dir);
        }

        $snapshot = (array) ($plugin->disabled_feature_ids ?? []);

        foreach ($snapshot as $id => $enabled) {
            Features::query()->whereKey((int) $id)->update(['enabled' => (int) $enabled]);
        }

        $plugin->forceFill([
            'status' => Plugin::STATUS_INSTALLED,
            'disabled_feature_ids' => null,
        ])->save();

        $this->flushRegistries();
    }

    /**
     * 卸载：删目录 + 删该插件的功能（含绑定/命令），彻底干净
     *
     * 功能记录必须删：代码没了，留着只是「未知的执行器」；重新安装会重新同步。
     */
    public function uninstall(Plugin $plugin): void
    {
        $ids = (array) ($plugin->feature_ids ?? []);

        if ($ids !== []) {
            DB::table('features_binds')->whereIn('feature_id', $ids)->delete();
            DB::table('feature_commands')->whereIn('feature_id', $ids)->delete();
            DB::table('feature_upstream_endpoints')->whereIn('feature_id', $ids)->delete();
            DB::table('feature_hooks')->whereIn('feature_id', $ids)->delete();
            DB::table('feature_data')->whereIn('feature_id', $ids)->delete();
            // 直查直删：Features 模型带租户作用域，CLI / 非超管调用时
            // whereIn 会被追加租户条件而删不掉（残留功能继续占着命令名）
            DB::table('features')->whereIn('id', $ids)->delete();
        }

        foreach ([self::root(), self::disabledRoot()] as $base) {
            $dir = $base . '/' . Str::studly($plugin->slug);

            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        // 物理删除：软删除的行会继续占着 slug 唯一索引，导致同名插件再也装不回来
        DB::table('feature_plugins')->where('id', $plugin->getKey())->delete();

        $this->flushRegistries();
    }

    // ------------------------------------------------------------------
    // 内部步骤
    // ------------------------------------------------------------------

    protected function assertPackage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new FailedException('上传失败：' . $file->getErrorMessage());
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            throw new FailedException('只接受 zip 插件包');
        }

        if ($file->getSize() > self::MAX_PACKAGE_SIZE) {
            throw new FailedException('插件包不能超过 10MB');
        }
    }

    /**
     * 解压：逐条目校验，拒绝路径穿越与不允许的文件类型
     */
    protected function extract(string $zipPath, string $dest): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new FailedException('插件包无法打开，请确认是完整的 zip');
        }

        try {
            if ($zip->numFiles > self::MAX_FILE_COUNT) {
                throw new FailedException('插件包文件数超过 ' . self::MAX_FILE_COUNT . ' 个');
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');

                if ($name === '') {
                    continue;
                }

                // 路径穿越 / 绝对路径 / Windows 盘符
                if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
                    throw new FailedException('插件包含非法路径：' . $name);
                }

                // macOS 打包垃圾直接跳过
                if (str_starts_with($name, '__MACOSX/')) {
                    continue;
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (! in_array($ext, self::ALLOWED_EXT, true)) {
                    throw new FailedException('插件包含不允许的文件类型：' . $name);
                }

                $total += (int) ($stat['size'] ?? 0);

                if ($total > self::MAX_UNPACK_SIZE) {
                    throw new FailedException('插件解压后超过 5MB');
                }
            }

            if (! $zip->extractTo($dest)) {
                throw new FailedException('解压失败');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * zip 里常见「外面又套了一层文件夹」，统一成「manifest.json 在根」
     */
    protected function normalizeRoot(string $workspace): string
    {
        if (is_file($workspace . '/manifest.json')) {
            return $workspace;
        }

        $dirs = array_values(array_filter(
            File::directories($workspace),
            fn ($d) => basename($d) !== '__MACOSX'
        ));

        if (count($dirs) === 1 && is_file($dirs[0] . '/manifest.json')) {
            return $dirs[0];
        }

        throw new FailedException('插件根目录缺少 manifest.json');
    }

    /**
     * @return array{slug: string, name: string, version: string, author: string, description: string}
     */
    protected function readManifest(string $root): array
    {
        $raw = @file_get_contents($root . '/manifest.json');
        $data = json_decode((string) $raw, true);

        if (! is_array($data)) {
            throw new FailedException('manifest.json 不是合法 JSON');
        }

        $slug = (string) ($data['slug'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));

        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $slug)) {
            throw new FailedException('manifest.slug 只能用小写字母、数字、下划线，且以字母开头（3-64 位）');
        }

        if ($name === '') {
            throw new FailedException('manifest.name 不能为空');
        }

        // 保留原文，方便排查是谁传的什么版本
        return [
            'slug' => $slug,
            'name' => $name,
            'version' => (string) ($data['version'] ?? ''),
            'author' => (string) ($data['author'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            // 固定命令槽位：商户定制实现的归属。空 = 全局默认实现（官方插件）；
            // 非空 = features.merchant_id，运行时按群的商户号路由到这份实现
            'merchant_id' => trim((string) ($data['merchant_id'] ?? '')),
        ];
    }

    /**
     * 同 slug 重传 = 升级（覆盖），不再要求「先卸载旧版本」
     *
     * 旧目录直接清掉（新代码马上要落盘到同一位置）；表行留给 persist()
     * 按 slug 更新。旧版本的 features/commands/binds 由 syncFeatures()
     * 按 uuid 幂等更新——升级前后命令、绑定、后台配置都保留。
     */
    protected function clearStaleInstall(string $slug, string $targetDir): void
    {
        File::deleteDirectory($targetDir);
        File::deleteDirectory(self::disabledRoot() . '/' . Str::studly($slug));
    }

    /**
     * 落盘成功后登记（被拒过的记录直接复用，避免同 slug 堆多条历史）
     *
     * @param array<string, string> $manifest
     */
    protected function persist(
        array $manifest,
        string $namespace,
        array $audit,
        ?array $aiReview,
        string $targetDir,
        int $operatorId
    ): Plugin {
        $data = [
            'slug' => $manifest['slug'],
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'author' => $manifest['author'],
            'description' => $manifest['description'],
            'namespace' => $namespace,
            'status' => Plugin::STATUS_INSTALLED,
            'manifest' => $manifest,
            'audit_report' => $audit,
            'ai_review' => $aiReview,
            'feature_ids' => [],
            'disabled_feature_ids' => null,
            'file_count' => $audit['stats']['php_files'] ?? 0,
            'size' => (int) ($audit['stats']['bytes'] ?? 0),
            'installed_by' => $operatorId,
        ];

        // 这里必须用表操作：slug 唯一索引可能被一条「软删除」的历史行占着，
        // 模型查不到它、insert 就会撞唯一键。有行就更新，没有才插入。
        $existingId = DB::table('feature_plugins')->where('slug', $manifest['slug'])->value('id');

        if ($existingId) {
            $data['deleted_at'] = 0;
            DB::table('feature_plugins')->where('id', $existingId)->update($data);

            return Plugin::query()->findOrFail($existingId);
        }

        return Plugin::query()->create($data);
    }

    /**
     * 审核不通过也留一条记录：后台能看到被拒原因，而不是「传了没反应」
     *
     * @param array<string, string> $manifest
     */
    protected function recordRejected(
        array $manifest,
        string $namespace,
        array $audit,
        ?array $aiReview,
        int $operatorId
    ): void {
        // 同 slug 的历史行（可能被软删除）先物理清掉，再插一条新的被拒记录
        DB::table('feature_plugins')->where('slug', $manifest['slug'])->delete();

        Plugin::query()->create(
            [
                'slug' => $manifest['slug'],
                'name' => $manifest['name'],
                'version' => $manifest['version'],
                'author' => $manifest['author'],
                'description' => $manifest['description'],
                'namespace' => $namespace,
                'status' => Plugin::STATUS_REJECTED,
                'manifest' => $manifest,
                'audit_report' => $audit,
                'ai_review' => $aiReview,
                'feature_ids' => [],
                'file_count' => $audit['stats']['php_files'] ?? 0,
                'size' => (int) ($audit['stats']['bytes'] ?? 0),
                'installed_by' => $operatorId,
            ]
        );
    }

    /**
     * 同步该插件的功能到 features 表，并记录 id
     *
     * 命令体系（固定槽位）：
     *   · 槽位命令（CommandSlots，/ye /cx 这类业务命令）不全局唯一——
     *     官方插件一份全局默认实现（features.merchant_id 空），每个商户
     *     一份定制实现（manifest.merchant_id），同一商户同一命令只有一个，
     *     重传覆盖；运行时按群的商户号路由。
     *   · 其它命令仍然全局唯一，装第二个就报错，防止通用命令被稀释。
     */
    protected function syncFeatures(Plugin $plugin): void
    {
        $classes = $this->pluginClasses($plugin->namespace);

        if ($classes === []) {
            throw new FailedException('插件里没有可用的功能类（需继承 BaseCustomFeature）');
        }

        // 功能标识 = custom:{uuid}（默认取短类名）
        $keys = array_map(fn (string $class) => 'custom:' . $class::uuid(), $classes);

        // manifest.merchant_id：商户定制实现的归属（空 = 全局默认实现）
        $merchantId = trim((string) ($plugin->manifest['merchant_id'] ?? ''));

        // 本插件升级前已占用的功能：uuid 相同 = 重传升级，不算冲突
        $ownIds = DB::table('features')->whereIn('feature', $keys)->pluck('id')->all();

        // 升级时被移除的功能类：旧 feature 连命令一起删，不留占坑的僵尸
        $previousIds = array_values(array_diff((array) ($plugin->feature_ids ?? []), $ownIds));

        if ($previousIds !== []) {
            DB::table('feature_commands')->whereIn('feature_id', $previousIds)->delete();
            DB::table('feature_upstream_endpoints')->whereIn('feature_id', $previousIds)->delete();
            DB::table('feature_hooks')->whereIn('feature_id', $previousIds)->delete();
            DB::table('feature_data')->whereIn('feature_id', $previousIds)->delete();
            DB::table('features')->whereIn('id', $previousIds)->delete();
        }

        // 直查：模型带租户作用域，CLI 安装会漏检冲突。
        // ownIds 是本插件升级前的功能（uuid 相同），属于重传升级，不算冲突
        $conflict = DB::table('features')->whereIn('feature', $keys)
            ->when($ownIds !== [], fn ($q) => $q->whereNotIn('id', $ownIds))
            ->pluck('feature')
            ->all();

        if ($conflict !== []) {
            throw new FailedException(
                '功能标识冲突：' . implode('、', $conflict) . '（请修改插件功能类的 uuid() 后重新打包）'
            );
        }

        $slotCommands = [];
        $plainCommands = [];

        foreach ($classes as $class) {
            foreach ((array) $class::commands() as $command) {
                // 与 dispatcher 的 parseName 同口径：去斜杠、小写
                $code = strtolower(ltrim(trim((string) ($command['command'] ?? '')), '/'));

                if ($code === '') {
                    continue;
                }

                if (CommandSlots::is($code)) {
                    $slotCommands[] = $code;
                } else {
                    $plainCommands[] = $code;
                }
            }
        }

        // 1) 非槽位命令：全局唯一（排除本插件升级前自己的行）
        if ($plainCommands !== []) {
            $occupied = FeatureCommands::query()
                ->whereIn('command', array_values(array_unique($plainCommands)))
                ->when($ownIds !== [], fn ($q) => $q->whereNotIn('feature_id', $ownIds))
                ->pluck('command')
                ->all();

            if ($occupied !== []) {
                throw new FailedException(
                    '命令已被其它功能占用：/' . implode('、/', $occupied) . '（请修改插件 commands() 的 command 后重新打包）'
                );
            }
        }

        // 2) 槽位命令：同商户已有别的实现 → 整体替换（绑定迁移过来）；
        //    其它商户的同名实现并存不动 —— 命令固定，实现按商户隔离
        $replacedBinds = [];

        if ($slotCommands !== []) {
            $replacedIds = DB::table('feature_commands as fc')
                ->join('features as f', 'f.id', '=', 'fc.feature_id')
                ->whereIn('fc.command', array_values(array_unique($slotCommands)))
                ->where(function ($q) use ($merchantId) {
                    $merchantId === ''
                        ? $q->whereNull('f.merchant_id')
                        : $q->where('f.merchant_id', $merchantId);
                })
                ->when($ownIds !== [], fn ($q) => $q->whereNotIn('fc.feature_id', $ownIds))
                ->distinct()
                ->pluck('fc.feature_id')
                ->all();

            if ($replacedIds !== []) {
                // 旧实现上开着的群绑定记下来，装好新功能后原样迁过去
                $replacedBinds = DB::table('features_binds')
                    ->whereIn('feature_id', $replacedIds)
                    ->get(['bot_id', 'chat_id', 'third_config_id', 'config', 'enabled', 'creator_id', 'tenant_id'])
                    ->all();

                // 旧实现所在的插件整个下线：目录不删的话，CustomFeatureRegistry::sync()
                // 还能扫到旧类，把刚删掉的功能和命令行重新建回来（覆盖变并存）
                $staleRows = DB::table('feature_plugins')
                    ->where('status', '!=', Plugin::STATUS_REJECTED)
                    ->get(['slug', 'feature_ids']);

                foreach ($staleRows as $row) {
                    $oldIds = json_decode((string) $row->feature_ids, true) ?: [];

                    if (array_intersect($oldIds, $replacedIds) !== []) {
                        File::deleteDirectory(self::root() . '/' . Str::studly($row->slug));
                        File::deleteDirectory(self::disabledRoot() . '/' . Str::studly($row->slug));
                        DB::table('feature_plugins')->where('slug', $row->slug)->delete();
                    }
                }

                foreach (['feature_commands', 'feature_upstream_endpoints', 'feature_hooks', 'feature_data', 'features_binds'] as $table) {
                    DB::table($table)->whereIn('feature_id', $replacedIds)->delete();
                }

                DB::table('features')->whereIn('id', $replacedIds)->delete();
            }
        }

        app(CustomFeatureRegistry::class)->sync();

        $ids = DB::table('features')->whereIn('feature', $keys)->pluck('id')->all();

        // 商户归属写入（syncFeature 不感知 manifest，由安装器统一落库）
        if ($merchantId !== '') {
            DB::table('features')->whereIn('id', $ids)->update(['merchant_id' => $merchantId]);
        }

        // 被覆盖实现在哪些群开着，新实现就在哪些群接着用（后台随时可关）
        foreach ($replacedBinds as $bind) {
            foreach ($ids as $id) {
                $exists = DB::table('features_binds')
                    ->where('feature_id', $id)
                    ->where('bot_id', $bind->bot_id)
                    ->where('chat_id', $bind->chat_id)
                    ->where('deleted_at', 0)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('features_binds')->insert([
                    'bot_id' => $bind->bot_id,
                    'chat_id' => $bind->chat_id,
                    'feature_id' => $id,
                    'third_config_id' => $bind->third_config_id,
                    'config' => $bind->config,
                    'enabled' => $bind->enabled,
                    'creator_id' => $bind->creator_id,
                    'created_at' => time(),
                    'updated_at' => time(),
                    'deleted_at' => 0,
                    'tenant_id' => $bind->tenant_id,
                ]);
            }
        }

        $plugin->forceFill(['feature_ids' => array_values($ids)])->save();
    }

    /**
     * @return array<int, class-string>
     */
    protected function pluginClasses(string $namespace): array
    {
        $prefix = $namespace . '\\';

        return array_values(array_filter(
            CustomFeatureRegistry::discover(),
            fn (string $class) => str_starts_with($class, $prefix)
        ));
    }

    protected function flushRegistries(): void
    {
        DriverRegistry::flush();
    }

    /**
     * 审核错误摘要（给前端一句可读的话）
     *
     * @param array<int, array<string, mixed>> $errors
     */
    protected function summarize(array $errors): string
    {
        $lines = array_map(
            fn (array $e) => ($e['file'] ?? '?') . ':' . ($e['line'] ?? 0) . ' ' . ($e['message'] ?? ''),
            array_slice($errors, 0, 5)
        );

        return implode('；', $lines) . (count($errors) > 5 ? ' …共 ' . count($errors) . ' 处' : '');
    }
}
