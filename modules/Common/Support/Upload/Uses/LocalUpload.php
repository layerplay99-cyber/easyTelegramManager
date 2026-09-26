<?php

namespace Modules\Common\Support\Upload\Uses;


use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalUpload extends Upload
{
    /**
     * upload
     *
     * @return array
     */
    public function upload(): array
    {
        return $this->addUrl($this->getUploadPath());
    }

    /**
     * app url
     *
     * @param $path
     * @return mixed
     */
    protected function addUrl($path): mixed
    {
        $path['path'] = config('app.url') . '/'.

                        Str::of($path['path'])->replace('\\', '/')->toString();

        return $path;
    }


    /**
     * local upload
     *
     * @return string
     */
    protected function localUpload(): string
    {
        $this->checkSize();

        $storePath = 'uploads' . DIRECTORY_SEPARATOR . $this->getUploadedFileMimeType() . DIRECTORY_SEPARATOR . date('Y-m-d', time());

        $filename = $this->generateImageName($this->getUploadedFileExt());

        // 获取完整的物理路径 - 修改为 storage 目录而不是 public 目录
        $fullPath = storage_path('app' . DIRECTORY_SEPARATOR . $storePath);

        // 确保目录存在，如果不存在则创建
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        Storage::build([
            'driver' => 'local',
            'root' => storage_path('app' . DIRECTORY_SEPARATOR . $storePath)
        ])->put($filename, $this->file->getContent());

        return $storePath . DIRECTORY_SEPARATOR . $filename;
    }
}
