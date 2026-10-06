"""事件处理：把 Telethon 的更新转发回 Laravel（复用既有事件/监听器链路）。

- 收到群消息且该群在 Redis 里有采集开关 -> 提取自定义 emoji -> 回调 Laravel 派发 CollectEmoji
- 自己（登录账号）进群/退群 -> 回调 Laravel 派发 UserGroupMembershipEvent
"""
from __future__ import annotations

import logging

import httpx
import redis.asyncio as aioredis
from telethon import events

from .config import settings

log = logging.getLogger(__name__)

_redis = None


async def _get_redis():
    global _redis
    if _redis is None:
        _redis = aioredis.from_url(settings.REDIS_URL, decode_responses=True)
    return _redis


async def _post(path: str, payload: dict):
    url = settings.LARAVEL_BASE_URL.rstrip("/") + path
    headers = {"X-Token": settings.CALLBACK_TOKEN}
    # nginx 按 server_name 匹配 vhost，必须覆盖 Host，否则命中 default_server 返回 404
    if settings.LARAVEL_HOST:
        headers["Host"] = settings.LARAVEL_HOST
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            r = await client.post(url, json=payload, headers=headers)
            if r.status_code >= 400:
                log.warning("callback %s -> %s %s", path, r.status_code, r.text[:200])
    except Exception as e:
        log.warning("callback %s failed: %s", path, e)


async def _emoji_switch_on(chat_id: int) -> bool:
    try:
        r = await _get_redis()
        return bool(await r.exists(f"{settings.EMOJI_SWITCH_PREFIX}{chat_id}"))
    except Exception as e:
        log.warning("redis check failed: %s", e)
        return False


def register_handlers(mc):
    """给某个 client 注册事件处理器（由 manager 在首次连接时调用一次）。"""
    client = mc.client
    session = mc.session

    async def on_new_message(event: events.NewMessage.Event):
        try:
            message = event.message
            chat_id = event.chat_id
            if not chat_id or not message.message:
                return
            if not await _emoji_switch_on(chat_id):
                return
            entities = []
            for e in (message.entities or []):
                if type(e).__name__ != "MessageEntityCustomEmoji":
                    continue
                doc_id = getattr(e, "document_id", None)
                if doc_id is None:
                    continue
                entities.append({
                    "type": "custom_emoji",
                    "document_id": int(doc_id),
                    "offset": int(getattr(e, "offset", 0)),
                    "length": int(getattr(e, "length", 0)),
                })
            if not entities:
                return
            await _post("/api/telegram/events/emoji", {
                "session": session,
                "entities": entities,
                "text": message.message,
            })
        except Exception as e:
            log.warning("on_new_message error: %s", e)

    async def on_chat_action(event: events.ChatAction.Event):
        try:
            me = await event.client.get_me()
            if not me or me.id not in (event.user_ids or set()):
                return
            if event.user_joined:
                action = "joined"
            elif event.user_left:
                action = "left"
            else:
                return
            await _post("/api/telegram/events/member", {
                "session": session,
                "chat_id": int(event.chat_id),
                "user_id": int(me.id),
                "action": action,
            })
        except Exception as e:
            log.warning("on_chat_action error: %s", e)

    client.add_event_handler(on_new_message, events.NewMessage(incoming=True))
    client.add_event_handler(on_chat_action, events.ChatAction)
