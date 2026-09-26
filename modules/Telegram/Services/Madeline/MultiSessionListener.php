<?php

namespace Modules\Telegram\Services\Madeline;

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;

class MultiSessionListener
{
    protected string $sessionPath;
    protected array $instances = [];

    public function __construct()
    {
        $this->sessionPath = storage_path('app/telegram');
    }

    /**
     * 扫描目录中的所有 session 文件
     */
    public function scanSessions(): array
    {
        $files = glob($this->sessionPath . '/session_*.madeline');
        return $files ?: [];
    }

    /**
     * 启动所有已有 session
     * @throws Exception
     */
    public function startAllSessions(): void
    {
        $this->loadSession($this->scanSessions());
    }

    /**
     * @throws Exception
     */
    public function loadSession(array $files=[]): void
    {
        if (empty($files)) {
            return;
        }

        $instances = [];
        foreach ($files as $file) {
            $instances[$file] = new API($file);
        }

        API::startAndLoopMulti(
            $instances,
            SessionEventHandler::class
        );
    }

    /**
     * 热更新：扫描目录，新的 Session 自动加入
     * @throws Exception
     */
    public function hotReload(): void
    {
        $files = $this->scanSessions();
        $this->loadSession($files);
    }
}
