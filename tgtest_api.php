<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 模拟 HTTP 请求测试三个新接口
use Illuminate\Http\Request;

$ctrl = app(Modules\Telegram\Http\Controllers\FeaturesController::class);

echo "=== features/drivers ===" . PHP_EOL;
$r = $ctrl->drivers();
$d = $r->getData(true)['data'];
foreach ($d as $x) {
    echo "  {$x['key']} | {$x['label']} | group={$x['group']} | schema=" . count($x['config_schema']) . PHP_EOL;
}

echo PHP_EOL . "=== features/options?source=third_api_configs ===" . PHP_EOL;
$r2 = $ctrl->options(new Request(['source' => 'third_api_configs']));
echo "  返回 " . count($r2->getData(true)['data']) . " 项（测试库为空，正常）" . PHP_EOL;

echo PHP_EOL . "=== features/options?source=third_api_endpoints ===" . PHP_EOL;
$r3 = $ctrl->options(new Request(['source' => 'third_api_endpoints']));
echo "  返回 " . count($r3->getData(true)['data']) . " 项" . PHP_EOL;

echo PHP_EOL . "=== 造数据后再次验证 ===" . PHP_EOL;
$cfgId = Illuminate\Support\Facades\DB::table('thirdapi_config')->insertGetId([
    'name' => '测试上游', 'api_url' => 'https://api.example.com', 'token' => 'tk123',
    'public_key' => '', 'secrept_key' => '', 'creator_id' => 0,
    'created_at' => time(), 'updated_at' => time(), 'deleted_at' => 0,
]);
Illuminate\Support\Facades\DB::table('third_api_endpoints')->insert([
    'third_config_id' => $cfgId, 'name' => '查询余额', 'method' => 'GET',
    'path_template' => 'api/webhook/users/{userID}', 'timeout' => 30, 'enabled' => 1,
    'creator_id' => 0, 'created_at' => time(), 'updated_at' => time(), 'deleted_at' => 0,
]);

$r4 = $ctrl->options(new Request(['source' => 'third_api_configs']));
foreach ($r4->getData(true)['data'] as $o) echo "  config: {$o['value']} => {$o['label']}" . PHP_EOL;

$r5 = $ctrl->options(new Request(['source' => 'third_api_endpoints']));
foreach ($r5->getData(true)['data'] as $o) echo "  endpoint: {$o['value']} => {$o['label']}" . PHP_EOL;