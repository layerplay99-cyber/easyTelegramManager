<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute 必须接受。',
    'accepted_if' => '当 :other 为 :value 时，:attribute 必须接受。',
    'active_url' => ':attribute 不是一个有效的网址。',
    'after' => ':attribute 必须要晚于 :date。',
    'after_or_equal' => ':attribute 必须要等于 :date 或更晚。',
    'alpha' => ':attribute 只能由字母组成。',
    'alpha_dash' => ':attribute 只能由字母、数字、短划线(-)和下划线(_)组成。',
    'alpha_num' => ':attribute 只能由字母和数字组成。',
    'array' => ':attribute 必须是一个数组。',
    'before' => ':attribute 必须要早于 :date。',
    'before_or_equal' => ':attribute 必须要等于 :date 或更早。',
    'between' => [
        'array' => ':attribute 必须只有 :min - :max 个单元。',
        'file' => ':attribute 必须介于 :min - :max KB 之间。',
        'numeric' => ':attribute 必须介于 :min - :max 之间。',
        'string' => ':attribute 必须介于 :min - :max 个字符之间。',
    ],
    'boolean' => ':attribute 字段必须为 true 或 false。',
    'confirmed' => ':attribute 两次输入不一致。',
    'current_password' => '密码不正确。',
    'date' => ':attribute 不是一个有效的日期。',
    'date_equals' => ':attribute 必须要等于 :date。',
    'date_format' => ':attribute 的格式必须为 :format。',
    'declined' => ':attribute 必须被拒绝。',
    'declined_if' => '当 :other 为 :value 时，:attribute 必须被拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须是 :digits 位的数字。',
    'digits_between' => ':attribute 必须是介于 :min 和 :max 位的数字。',
    'dimensions' => ':attribute 图片尺寸不正确。',
    'distinct' => ':attribute 已经存在。',
    'doesnt_end_with' => ':attribute 不能以下列之一结尾：:values。',
    'doesnt_start_with' => ':attribute 不能以下列之一开头：:values。',
    'email' => ':attribute 不是一个合法的邮箱。',
    'ends_with' => ':attribute 必须以下列之一结尾：:values。',
    'enum' => '选择的 :attribute 无效。',
    'exists' => ':attribute 不存在。',
    'file' => ':attribute 必须是文件。',
    'filled' => ':attribute 不能为空。',
    'gt' => [
        'array' => ':attribute 必须多于 :value 个元素。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须多于或等于 :value 个元素。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须多于或等于 :value 个字符。',
    ],
    'image' => ':attribute 必须是图片。',
    'in' => '已选的属性 :attribute 非法。',
    'in_array' => ':attribute 没有在 :other 中。',
    'integer' => ':attribute 必须是整数。',
    'ip' => ':attribute 必须是有效的 IP 地址。',
    'ipv4' => ':attribute 必须是有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是有效的 IPv6 地址。',
    'json' => ':attribute 必须是正确的 JSON 格式。',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个元素。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 必须少于或等于 :value 个元素。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 必须少于或等于 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是一个有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 最多只有 :max 个单元。',
        'file' => ':attribute 不能大于 :max KB。',
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能大于 :max 个字符。',
    ],
    'max_digits' => ':attribute 不能超过 :max 位数字。',
    'mimes' => ':attribute 必须是一个 :values 类型的文件。',
    'mimetypes' => ':attribute 必须是一个 :values 类型的文件。',
    'min' => [
        'array' => ':attribute 至少有 :min 个单元。',
        'file' => ':attribute 大小不能小于 :min KB。',
        'numeric' => ':attribute 必须大于等于 :min。',
        'string' => ':attribute 至少为 :min 个字符。',
    ],
    'min_digits' => ':attribute 必须至少有 :min 位数字。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '已选的属性 :attribute 非法。',
    'not_regex' => ':attribute 的格式不正确。',
    'numeric' => ':attribute 必须是数字。',
    'password' => [
        'letters' => ':attribute 必须包含至少一个字母。',
        'mixed' => ':attribute 必须包含至少一个大写字母和一个小写字母。',
        'numbers' => ':attribute 必须包含至少一个数字。',
        'symbols' => ':attribute 必须包含至少一个符号。',
        'uncompromised' => '给定的 :attribute 已出现在数据泄露中。请选择不同的 :attribute。',
    ],
    'present' => ':attribute 字段必须存在。',
    'prohibited' => ':attribute 字段被禁止。',
    'prohibited_if' => '当 :other 为 :value 时，:attribute 字段被禁止。',
    'prohibited_unless' => '除非 :other 在 :values 中，否则 :attribute 字段被禁止。',
    'prohibits' => ':attribute 字段禁止 :other 的存在。',
    'regex' => ':attribute 格式不正确。',
    'required' => ':attribute 不能为空。',
    'required_array_keys' => ':attribute 字段必须包含以下条目：:values。',
    'required_if' => '当 :other 为 :value 时 :attribute 不能为空。',
    'required_if_accepted' => '当 :other 被接受时，:attribute 字段是必须的。',
    'required_unless' => '当 :other 不为 :values 时 :attribute 不能为空。',
    'required_with' => '当 :values 存在时 :attribute 不能为空。',
    'required_with_all' => '当 :values 存在时 :attribute 不能为空。',
    'required_without' => '当 :values 不存在时 :attribute 不能为空。',
    'required_without_all' => '当 :values 都不存在时 :attribute 不能为空。',
    'same' => ':attribute 和 :other 必须相同。',
    'size' => [
        'array' => ':attribute 必须为 :size 个单元。',
        'file' => ':attribute 大小必须为 :size KB。',
        'numeric' => ':attribute 大小必须为 :size。',
        'string' => ':attribute 必须是 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以 :values 为开头。',
    'string' => ':attribute 必须是字符串。',
    'timezone' => ':attribute 必须是一个合法的时区值。',
    'unique' => ':attribute 已经存在。',
    'uploaded' => ':attribute 上传失败。',
    'url' => ':attribute 格式不正确。',
    'uuid' => ':attribute 必须是有效的 UUID。',

    // Custom validation messages for Telegram module
    'url_resource_required' => 'url 与 resource_id 至少填写一个',
    'url_format_invalid' => 'url 格式不正确',
    'url_resource_duplicate' => '该 url 或 resource_id 已存在',

    // Custom system messages
    'route_not_found_or_register' => '路由未找到或未注册',
    'Server Error' => '服务器错误',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => '姓名',
        'username' => '用户名',
        'email' => '邮箱',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'phone' => '手机号',
        'mobile' => '手机号',
        'age' => '年龄',
        'sex' => '性别',
        'gender' => '性别',
        'year' => '年',
        'month' => '月',
        'day' => '日',
        'hour' => '时',
        'minute' => '分',
        'second' => '秒',
        'title' => '标题',
        'content' => '内容',
        'description' => '描述',
        'excerpt' => '摘要',
        'date' => '日期',
        'time' => '时间',
        'available' => '可用的',
        'size' => '大小',
        'url' => '链接',
        'address' => '地址',
        'city' => '城市',
        'country' => '国家',
        'province' => '省份',
        'zipcode' => '邮编',
        'status' => '状态',
        'type' => '类型',
        'category' => '分类',
        'image' => '图片',
        'file' => '文件',
        'avatar' => '头像',
        'company' => '公司',
        'position' => '职位',
        'website' => '网站',
        'qq' => 'QQ',
        'wechat' => '微信',
        'weibo' => '微博',
        'facebook' => 'Facebook',
        'twitter' => 'Twitter',
        'instagram' => 'Instagram',
        'created_at' => '创建时间',
        'updated_at' => '更新时间',
    ],

];
