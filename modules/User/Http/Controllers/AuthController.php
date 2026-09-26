<?php

namespace Modules\User\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Catch\Exceptions\FailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Modules\User\Events\Login;
use Modules\User\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class AuthController extends Controller
{
    private Google2FA $google2FA;

    public function __construct()
    {
        $this->google2FA = new Google2FA();
    }

    public function login(Request $request): array
    {
        $email = $request->get('email');
        $password = $request->get('password');
        $code = $request->get('code'); // 2FA验证码

        /* @var User $user */
        $user = User::query()->where('email', $email)->first();

        if (!$user) {
            throw new FailedException('登录失败！请检查账号或者密码');
        }

        if ($user->isDisabled()) {
            throw new FailedException('账号被禁用，请联系管理员');
        }

        if (!Hash::check($password, $user->password)) {
            throw new FailedException('登录失败！请检查账号或者密码');
        }

        // Login 事件必须在密码校验通过之后再派发。
        // 原来放在最前面，监听器会写 login_ip / login_at，
        // 导致密码输错也会刷新用户的登录信息，并被记进登录日志。
        Event::dispatch(new Login($request, $user));

        if (! $user->two_factor_enabled) {
            $token = $user->createToken('auth_token')->plainTextToken;
            return [
                'token' => $token,
                'two_factor' => false,
                'message' => '登录成功'
            ];
        }

        if (!$user->two_factor_secret) {
            return $this->initiateTwoFactorSetup($user);
        }

        if (!$code) {
            throw new FailedException('请输入二次验证码');
        }

        if (!$this->google2FA->verifyKey($user->two_factor_secret, $code)) {
            throw new FailedException('二次验证码错误');
        }

        $token = $user->createToken('token')->plainTextToken;
        return compact('token');
    }

    private function initiateTwoFactorSetup(User $user): array
    {
        $secret = $this->google2FA->generateSecretKey();

        $qrCodeUrl = $this->google2FA->getQRCodeUrl(
            config('app.name', 'BotManager'),
            $user->email,
            $secret
        );

        $qrCode = new QrCode($qrCodeUrl);
        $writer = new PngWriter();
        $qrCodeImage = base64_encode($writer->write($qrCode)->getString());

        $pendingToken = bin2hex(random_bytes(32));
        Cache::put("pending_2fa_{$pendingToken}", $user->id, 600);

        $user->update(['two_factor_secret_temp' => $secret]);

        return [
            'require_2fa_setup' => true,
            'qr_code' => $qrCodeImage,
            'pending_token' => $pendingToken,
            'message' => '请使用Google Authenticator扫描二维码，然后输入验证码完成绑定'
        ];
    }

    public function completeTwoFactorSetup(Request $request): array
    {
        $pendingToken = $request->get('pending_token');
        $code = $request->get('code');

        if (!$pendingToken || !$code) {
            throw new FailedException('缺少必要参数');
        }

        $userId = Cache::get("pending_2fa_{$pendingToken}");
        if (!$userId) {
            throw new FailedException('验证已过期，请重新登录');
        }

        $user = User::find($userId);
        if (!$user || !$user->two_factor_secret_temp) {
            throw new FailedException('用户状态异常');
        }

        // 验证验证码
        if (!$this->google2FA->verifyKey($user->two_factor_secret_temp, $code)) {
            throw new FailedException('验证码错误');
        }

        // 绑定成功
        $user->update([
            'two_factor_secret' => $user->two_factor_secret_temp,
            'two_factor_secret_temp' => null,
            'two_factor_enabled_at' => now()
        ]);

        // 清除临时状态
        Cache::forget("pending_2fa_{$pendingToken}");

        // 生成登录token
        $token = $user->createToken('token')->plainTextToken;

        return [
            'token' => $token,
            'message' => '2FA绑定成功，登录完成'
        ];
    }

    /**
     * logout
     *
     * @return array
     */
    public function logout(): array
    {
        /* @var  User $user */
        $user = Auth::guard(getGuardName())->user();

        $user->currentAccessToken()->delete();

        return [];
    }
}
