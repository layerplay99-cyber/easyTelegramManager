<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// 访问 storage 目录下的上传文件
//
// 安全说明：三个参数原先都是 '.*'，且未过滤 '..'，
// 构造 /uploads/../../../../.env 即可读取任意文件（路径穿越）。
// 现在收紧为安全字符集，并用 realpath 二次确认文件确实落在允许的根目录内。
Route::get('/uploads/{type}/{date}/{filename}', function ($type, $date, $filename) {
    $root = realpath(storage_path('app/uploads'));

    if ($root === false) {
        abort(404);
    }

    $relative = implode(DIRECTORY_SEPARATOR, [$type, $date, $filename]);
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);

    // realpath 返回 false 说明文件不存在；不以 $root 开头说明发生了穿越
    if ($path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        abort(404);
    }

    if (! is_file($path)) {
        abort(404);
    }

    $mimeType = mime_content_type($path) ?: 'application/octet-stream';

    return new BinaryFileResponse($path, 200, [
        'Content-Type' => $mimeType,
    ]);
})->where([
    // 只允许安全字符，禁止目录分隔符与 ..
    'type' => '[a-zA-Z0-9_-]+',
    'date' => '[0-9_-]+',
    'filename' => '[a-zA-Z0-9._-]+',
]);
