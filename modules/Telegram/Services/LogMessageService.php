<?php
declare(strict_types=1);

namespace Modules\Telegram\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class LogMessageService
{
    private const LOG_CHANNEL = 'daily';
    private const MESSAGE_TYPE_TEXT = 'text';
    private const MESSAGE_TYPE_PHOTO = 'photo';
    private const MESSAGE_TYPE_DOCUMENT = 'document';
    private const EVENT_TYPE_COMMAND = 'command';
    private const EVENT_TYPE_MESSAGE = 'message';
    private const EVENT_TYPE_MEDIA = 'media';
    private const EVENT_TYPE_JOIN = 'join';
    private const EVENT_TYPE_LEAVE = 'leave';

    private const LOG_LEVEL_ERROR = 'error';
    private const LOG_LEVEL_WARNING = 'warning';
    private const LOG_LEVEL_DEBUG = 'debug';
    private const LOG_LEVEL_INFO = 'info';

    /**
     * 处理并记录 Telegram 消息
     */
    public function doAction(?Collection $message): void
    {
        if (!$message) {
            return;
        }

        $data = $this->extractBaseData($message);

        // 使用 match 表达式处理不同类型的消息
        match (true) {
            $message->has('text') => $this->handleText($message, $data),
            $message->has('photo') => $this->handlePhoto($message, $data),
            $message->has('document') => $this->handleDocument($message, $data),
            $message->has('new_chat_members') => $this->handleJoin($message, $data),
            $message->has('left_chat_member') => $this->handleLeave($message, $data),
            default => null
        };

        $this->createLaravelLog('chat_message_log', $data, 'message:');
    }

    private function extractBaseData(Collection $message): array
    {
        return [
            'identifier' => $message->getChat()?->id,
            'type' => $message->getChat()?->type,
            'title' => $message->getChat()?->title,
            'user_id' => $message->getFrom()?->id,
            'username' => $message->getFrom()?->username,
            'first_name' => $message->getFrom()?->first_name,
            'sent_at' => now()->toDateTimeString(),
        ];
    }

    private function handleText(Collection $message, array &$data): void
    {
        $text = $message->get('text', '');
        $data['message'] = $text;
        $data['message_type'] = self::MESSAGE_TYPE_TEXT;

        if (str_starts_with($text, '/')) {
            $data['event_type'] = self::EVENT_TYPE_COMMAND;
            $cmdParts = explode(' ', $text);
            $data['command'] = $cmdParts[0];
            $data['command_args'] = json_encode(array_slice($cmdParts, 1), JSON_UNESCAPED_UNICODE);
        } else {
            $data['event_type'] = self::EVENT_TYPE_MESSAGE;
        }
    }

    private function handlePhoto(Collection $message, array &$data): void
    {
        $photo = collect($message->getPhoto())->last();
        $data['message_type'] = self::MESSAGE_TYPE_PHOTO;
        $data['file_id'] = $photo?->getFileId();
        $data['event_type'] = self::EVENT_TYPE_MEDIA;
    }

    private function handleDocument(Collection $message, array &$data): void
    {
        $doc = $message->getDocument();
        $data['message_type'] = self::MESSAGE_TYPE_DOCUMENT;
        $data['file_id'] = $doc?->getFileId();
        $data['event_type'] = self::EVENT_TYPE_MEDIA;
    }

    private function handleJoin(Collection $message, array &$data): void
    {
        $data['event_type'] = self::EVENT_TYPE_JOIN;
        $members = collect($message->getNewChatMembers())
            ->pluck('first_name')
            ->join(', ');
        $data['message'] = "New member(s) joined: {$members}";
    }

    private function handleLeave(Collection $message, array &$data): void
    {
        $data['event_type'] = self::EVENT_TYPE_LEAVE;
        $memberName = $message->getLeftChatMember()?->getFirstName() ?? 'Unknown';
        $data['message'] = "Member left: {$memberName}";
    }

    /**
     * 写入自定义日志
     */
    public function createLaravelLog(
        string $fileName,
        array $data = [],
        string $msg = 'error:',
        string $level = self::LOG_LEVEL_INFO
    ): void {
        $logger = $this->buildLogger($fileName);

        $logMethods = [
            self::LOG_LEVEL_ERROR => 'error',
            self::LOG_LEVEL_WARNING => 'warning',
            self::LOG_LEVEL_DEBUG => 'debug',
            self::LOG_LEVEL_INFO => 'info',
        ];

        $method = $logMethods[$level] ?? 'info';
        $logger->{$method}($msg, $data);
    }

    private function buildLogger(string $fileName): LoggerInterface
    {
        return Log::build([
            'driver' => self::LOG_CHANNEL,
            'path' => storage_path("logs/{$fileName}.log"),
        ]);
    }
}
