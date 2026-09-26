<?php

declare(strict_types=1);

namespace Modules\Telegram\Models;

use Modules\Permissions\Models\Traits\DataRange;

use Catch\Base\CatchModel as Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\Telegram\Services\Bot\BotApiFactory;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * @property int $id
 * @property string $username
 * @property string $api_token
 * @property string $url_token
 * @property string $webhook_url
 * @property string $description
 * @property int|null $owner_id
 * @property bool $enabled
 * @property int $creator_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read Collection|BotGroups[] $botGroups
 * @property-read Collection|FeaturesBinds[] $avtivityBinds
 */
class Bots extends Model
{
    use DataRange;

    /**
     * 所属模块：当前用户没有该模块的功能权限时，该模块数据不可见
     */
    protected string $dataModule = 'telegram';

    protected $table = 'bots';

    protected $fillable = [
        'id',
        'api_token',
        'username',
        'url_token',
        'webhook_url',
        'description',
        'enabled',
        'creator_id',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'id',
        'api_token',
        'username',
        'url_token',
        'webhook_url',
        'description',
        'enabled',
        'created_at',
        'updated_at'
    ];

    /**
     * @var array
     */
    protected array $form = [
        'api_token',
        'username',
        'url_token',
        'webhook_url',
        'description',
        'enabled'
    ];

    /**
     * @var array
     */
    public array $searchable = [
        'username' => 'like',
        'enabled' => '=',

    ];

    protected bool $isPaginate = true;

    public function botGroups()
    {
        return $this->hasMany(BotGroups::class, 'bot_id', 'id');
    }

    public function avtivityBinds()
    {
        return $this->hasMany(FeaturesBinds::class, 'bot_id', 'id');
    }

    /**
     * @throws TelegramSDKException
     */
    public function telegramClient(): ?Api
    {
        if (!$this->api_token) return null;
        return app(BotApiFactory::class)->forToken($this->api_token);
    }
}
