<?php

namespace Modules\Telegram\Jobs;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\Telegram\Http\Controllers\PhoneController;
use Modules\Telegram\Models\Phones;

class ImportPhonesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $file, $taskId, $userId;

    public function __construct($file, $taskId, $userId)
    {
        $this->file = $file;
        $this->taskId = $taskId;
        $this->userId = $userId;
    }

    public function handle()
    {
        set_time_limit(0);
        $phoneController = app(PhoneController::class);
        $model = app(Phones::class);
        $data = $phoneController->parseImportFile(storage_path('app/' . $this->file));
        $total = count($data);
        $failedRows = [];
        Cache::put($this->taskId, ['total' => $total, 'current' => 0, 'status' => 'processing'], 3600);

        $successCount = 0;
        $batch = [];
        $batchSize = 500;
        foreach ($data as $index => $row) {
            try {
                if (empty($row['phone'])) {
                    $failedRows[] = [
                        'row' => $index + 2,
                        'error' => '手机号码不能为空'
                    ];
                    continue;
                }

                if ($model->where('phone', $row['phone'])->exists()) {
                    $failedRows[] = [
                        'row' => $index + 2,
                        'error' => '手机号码已存在'
                    ];
                    continue;
                }

                $batch[] = [
                    'phone' => $row['phone'],
                    'creator_id' => $this->userId,
                    'created_at' => Carbon::now()->timestamp,
                    'updated_at' => Carbon::now()->timestamp,
                ];

                if (count($batch) >= $batchSize) {
                    $model->insert($batch);
                    $successCount += count($batch);
                    $batch = [];
                }
                Cache::put($this->taskId, [
                    'total' => $total,
                    'current' => $successCount,
                    'status' => 'processing'
                ], 3600);
            } catch (\Exception $e) {
                $failedRows[] = [
                    'row' => $index + 2,
                    'error' => $e->getMessage()
                ];
            }
        }
        if (!empty($batch)) {
            $model->insert($batch);
            $successCount += count($batch);
        }
        Cache::put($this->taskId, [
            'total' => $total,
            'current' => $total,
            'success' => $successCount,
            'failed' => count($failedRows),
            'failedRows' => $failedRows,
            'status' => 'done'
        ], 3600);
    }
}
