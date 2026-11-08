<?php

declare(strict_types=1);

namespace Modules\Telegram\Console\Commands;

use Illuminate\Console\Command;
use Modules\Telegram\Models\Merchant;
use Modules\User\Models\User;

/**
 * 初始化平台商户，并按需创建商户超管账号。
 *
 * 商户超管「角色」由平台超管在后台分配，命令不越权替他决定权限。
 */
class MerchantCreate extends Command
{
    protected $signature = 'merchant:create
                            {name : 商户名称}
                            {code : 平台内部短码，唯一}
                            {--username= : 同时创建商户超管账号}
                            {--email=}
                            {--password=}';

    protected $description = '创建平台商户（可选同时创建商户超管账号）';

    public function handle(): int
    {
        $code = (string) $this->argument('code');

        if (Merchant::query()->where('code', $code)->exists()) {
            $this->error('商户短码已存在：' . $code);

            return self::FAILURE;
        }

        $merchant = Merchant::query()->create([
            'name' => (string) $this->argument('name'),
            'code' => $code,
            'status' => true,
        ]);

        $this->info('商户已创建：id=' . $merchant->id . ' code=' . $code);

        $username = (string) ($this->option('username') ?? '');

        if ($username !== '') {
            // 必须一次 save 写完 tenant_id：先 create 再 forceFill()->save() 会触发
            // protectTenantIdOnUpdate，把归属还原成 creating 钩子写入的 0。
            $user = new User();
            $user->forceFill([
                'username'          => $username,
                'email'             => (string) ($this->option('email') ?? ''),
                'password'          => (string) ($this->option('password') ?? ''),
                'creator_id'        => (int) config('catch.super_admin'),
                'tenant_id'         => $merchant->id,
                'is_merchant_admin' => 1,
            ]);
            $user->save();

            $this->info('商户超管已创建：id=' . $user->id . '（请在后台为其分配角色）');
        }

        return self::SUCCESS;
    }
}
