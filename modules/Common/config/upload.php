<?php
return [
    // 文件最大（字节）
    'max_size' => 10 * 1024 * 1024,

    // 扩展名白名单（Upload::checkExt() 会读取这两项）
    //
    // 背景：原配置里根本没有 image.ext / file.ext，
    // 导致 Upload::checkExt() 里 in_array($ext, null) 直接抛 TypeError，
    // 而 dealBeforeUpload()（唯一调用 checkExt 的地方）又从未被调用，
    // 两边的 bug 互相掩盖，结果是「上传完全没有任何类型校验」。
    // 这里补上白名单，并在 checkExt() 里加了空值防御。
    'image' => [
        // 注意：故意不包含 svg —— SVG 可以内嵌脚本，浏览器直接打开会造成 XSS
        'ext' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'ico', 'tif', 'tiff'],
    ],

    'file' => [
        // 覆盖导入导出（Excel/CSV）、模块包、常见文档
        'ext' => [
            'zip', 'rar', '7z',
            'xls', 'xlsx', 'csv',
            'doc', 'docx',
            'pdf', 'txt', 'json', 'xml',
            'mp3', 'mp4', 'wav',
        ],
    ],

    // oss 配置
    'oss' => [
        'bucket' => env('ALIOSS_BUCKET'),

        'access_id' => env('ALIOSS_ACCESS_ID'),

        'access_secret' => env('ALIOSS_ACCESS_SECRET'),

        'endpoint' => env('ALIOSS_ENDPOINT'),

        'dir' => env('ALIOSS_UPLOAD_DIR')
    ],
];
