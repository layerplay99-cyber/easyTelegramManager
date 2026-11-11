<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 空字符串 → NULL（仅用于可空的整型时间戳列）
 *
 * 背景：CatchModel 的 $autoNull2EmptyString 默认开启，filterData() 会把请求里的
 * null 统一转成 ''（这是框架给 varchar 列准备的约定）。但像 users.expired_at /
 * merchants.expired_at 这样「integer 存 Unix 时间戳 + 模型 cast datetime」的列，
 * 空字符串会踩两处坑：
 *   1. Illuminate\HasAttributes::fromDateTime() 对空值直接原样返回（`empty($value) ? $value`），
 *      所以 cast 不会把 '' 变成 NULL；
 *   2. '' 落到 integer 列就是 SQLSTATE 1366 Incorrect integer value。
 *
 * 表现：后台改密码 / 编辑用户时，表单没填「有效期」，提交空串就报 1366。
 * 修法：在模型 filterData() 收尾把这类字段的空串还原成 NULL（NULL = 永久有效）。
 */
final class NullTimestamp
{
    /**
     * @param array<int, string> $fields 需要清理的字段名
     */
    public static function clean(array $data, array $fields = ['expired_at', 'expire_noticed_at']): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
