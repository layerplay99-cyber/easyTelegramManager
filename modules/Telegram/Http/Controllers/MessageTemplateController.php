<?php
declare(strict_types=1);

namespace Modules\Telegram\Http\Controllers;

use Catch\Base\CatchController as Controller;
use Illuminate\Http\Request;
use Modules\Telegram\Models\MessageTemplate;
use Modules\Telegram\Services\Message\MessageRenderer;

/**
 * 消息模板：存结构化 blocks，而不是「带魔法串的纯文本」
 */
class MessageTemplateController extends Controller
{
    public function __construct(
        protected readonly MessageTemplate $model,
        protected readonly MessageRenderer $renderer,
    ) {}

    public function index(): mixed
    {
        return $this->model->getList();
    }

    public function store(Request $request): mixed
    {
        $data = $request->validate([
            'title' => 'required|string|max:100',
            'blocks' => 'required|array',
            'channel' => 'nullable|in:bot,user',
        ]);

        return $this->model->storeBy($data);
    }

    public function show(int|string $id): mixed
    {
        return $this->model->firstBy($id);
    }

    public function update(int|string $id, Request $request): mixed
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:100',
            'blocks' => 'nullable|array',
            'channel' => 'nullable|in:bot,user',
        ]);

        return $this->model->updateBy($id, $data);
    }

    public function destroy(int|string $id): mixed
    {
        return $this->model->deleteBy($id);
    }

    /**
     * 预览：把 blocks 渲染成实际要发的文本与实体，前端可直接看到效果
     */
    public function preview(Request $request): mixed
    {
        $data = $request->validate([
            'blocks' => 'required|array',
            'channel' => 'nullable|in:bot,user',
        ]);

        return $this->renderer->render(
            $data['blocks'],
            [],
            $data['channel'] ?? 'bot'
        );
    }
}
