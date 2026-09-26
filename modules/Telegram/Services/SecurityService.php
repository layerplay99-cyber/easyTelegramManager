<?php

namespace Modules\Telegram\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Random\RandomException;

/**
 * 安全服务类
 * 负责：防刷、防重放攻击、签名验证、频率限制
 */
class SecurityService
{
    /**
     * 验证签名
     * @param array $params 参数
     * @param string $sign 签名
     * @param string $secret 密钥
     * @return bool
     */
    public function verifySign(array $params, string $sign, string $secret): bool
    {
        $calculatedSign = $this->generateSign($params, $secret);
        return hash_equals($calculatedSign, $sign);
    }

    /**
     * 生成签名
     * @param array $params 参数
     * @param string $secret 密钥
     * @return string
     */
    public function generateSign(array $params, string $secret): string
    {
        // 移除sign字段
        unset($params['sign']);

        // 过滤空值
        $params = array_filter($params, function($value) {
            return $value !== '' && $value !== null;
        });

        // 按key排序
        ksort($params);

        // 拼接字符串
        $str = '';
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $str .= $key . '=' . $value . '&';
        }
        $str .= 'key=' . $secret;

        // MD5加密并转大写
        return strtoupper(md5($str));
    }

    /**
     * 防重放攻击验证
     * @param string $nonce 随机数
     * @param int $timestamp 时间戳
     * @param int $expire 有效期（秒）默认5分钟
     * @return bool
     * @throws \Exception
     */
    public function checkReplay(string $nonce, int $timestamp, int $expire = 300): bool
    {
        // 检查时间戳是否在有效期内
        $now = time();
        if (abs($now - $timestamp) > $expire) {
            throw new \Exception('请求已过期');
        }

        // 检查nonce是否已使用
        $key = "anti_replay:{$nonce}";
        if (Cache::has($key)) {
            throw new \Exception('请勿重复提交');
        }

        // 标记nonce已使用
        Cache::put($key, 1, $expire);

        return true;
    }

    /**
     * IP频率限制（防刷）
     * @param string $ip IP地址
     * @param string $action 操作类型（如：recharge, withdraw）
     * @param int $maxAttempts 最大次数
     * @param int $decayMinutes 时间窗口（分钟）
     * @return bool
     * @throws \Exception
     */
    public function checkIpRateLimit(string $ip, string $action, int $maxAttempts = 10, int $decayMinutes = 1): bool
    {
        $key = "rate_limit:ip:{$action}:{$ip}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            throw new \Exception("操作过于频繁，请{$decayMinutes}分钟后再试");
        }

        Cache::put($key, $attempts + 1, $decayMinutes * 60);

        return true;
    }

    /**
     * 用户频率限制
     * @param int $memberId 会员ID
     * @param string $action 操作类型
     * @param int $maxAttempts 最大次数
     * @param int $decayMinutes 时间窗口（分钟）
     * @return bool
     * @throws \Exception
     */
    public function checkUserRateLimit(int $memberId, string $action, int $maxAttempts = 5, int $decayMinutes = 1): bool
    {
        $key = "rate_limit:user:{$action}:{$memberId}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= $maxAttempts) {
            throw new \Exception("操作过于频繁，请{$decayMinutes}分钟后再试");
        }

        Cache::put($key, $attempts + 1, $decayMinutes * 60);

        return true;
    }

    /**
     * 检查IP黑名单
     * @param string $ip IP地址
     * @return bool
     * @throws \Exception
     */
    public function checkIpBlacklist(string $ip): bool
    {
        $blacklist = Cache::get('ip_blacklist', []);

        if (in_array($ip, $blacklist)) {
            throw new \Exception('您的IP已被限制访问');
        }

        return true;
    }

    /**
     * 将IP加入黑名单
     * @param string $ip IP地址
     * @param int $duration 时长（秒），0=永久
     */
    public function addToBlacklist(string $ip, int $duration = 0): void
    {
        $blacklist = Cache::get('ip_blacklist', []);

        if (!in_array($ip, $blacklist)) {
            $blacklist[] = $ip;
        }

        if ($duration > 0) {
            Cache::put('ip_blacklist', $blacklist, $duration);
        } else {
            Cache::forever('ip_blacklist', $blacklist);
        }
    }

    /**
     * 从黑名单移除IP
     * @param string $ip IP地址
     */
    public function removeFromBlacklist(string $ip): void
    {
        $blacklist = Cache::get('ip_blacklist', []);
        $blacklist = array_diff($blacklist, [$ip]);
        Cache::forever('ip_blacklist', array_values($blacklist));
    }

    /**
     * 生成随机nonce
     * @param int $length 长度
     * @return string
     * @throws RandomException
     */
    public function generateNonce(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * 验证请求来源
     * @param string $ip IP地址
     * @param array $whitelist 白名单IP列表
     * @return bool
     * @throws \Exception
     */
    public function checkWhitelist(string $ip, array $whitelist = []): bool
    {
        if (empty($whitelist)) {
            return true;
        }

        foreach ($whitelist as $allowedIp) {
            // 支持CIDR格式
            if ($this->ipInRange($ip, $allowedIp)) {
                return true;
            }
        }

        throw new \Exception('IP不在白名单中');
    }

    /**
     * 检查IP是否在指定范围内
     * @param string $ip IP地址
     * @param string $range IP范围（支持CIDR）
     * @return bool
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $mask) = explode('/', $range);

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);

        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }

    /**
     * 滑动窗口限流（Redis实现）
     * @param string $key 限流键
     * @param int $maxRequests 最大请求数
     * @param int $windowSeconds 时间窗口（秒）
     * @return bool
     * @throws \Exception
     */
    public function slidingWindowRateLimit(string $key, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        try {
            // 移除窗口外的记录
            Redis::zRemRangeByScore($key, 0, $windowStart);

            // 获取当前窗口内的请求数
            $currentCount = Redis::zCard($key);

            if ($currentCount >= $maxRequests) {
                throw new \Exception('请求过于频繁，请稍后再试');
            }

            // 添加当前请求
            Redis::zAdd($key, $now, $now);
            Redis::expire($key, $windowSeconds);

            return true;
        } catch (\Exception $e) {
            // Redis不可用时降级处理
            return $this->checkIpRateLimit(request()->ip(), $key, $maxRequests, (int)($windowSeconds / 60));
        }
    }

    /**
     * 令牌桶算法限流
     * @param string $key 限流键
     * @param int $capacity 桶容量
     * @param int $rate 令牌生成速率（个/秒）
     * @return bool
     * @throws \Exception
     */
    public function tokenBucketRateLimit(string $key, int $capacity = 10, int $rate = 1): bool
    {
        $now = time();
        $tokenKey = "token_bucket:{$key}";

        $bucket = Cache::get($tokenKey, [
            'tokens' => $capacity,
            'last_update' => $now
        ]);

        // 计算新增令牌数
        $elapsed = $now - $bucket['last_update'];
        $newTokens = min($capacity, $bucket['tokens'] + $elapsed * $rate);

        if ($newTokens < 1) {
            throw new \Exception('请求过于频繁，请稍后再试');
        }

        // 消耗一个令牌
        $bucket['tokens'] = $newTokens - 1;
        $bucket['last_update'] = $now;

        Cache::put($tokenKey, $bucket, 3600);

        return true;
    }
}

