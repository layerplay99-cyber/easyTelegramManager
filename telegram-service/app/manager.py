"""Telethon 多会话客户端管理器。

- 一个 Telegram 账号 = 一个 TelegramClient，按 session key 缓存复用。
- 首次使用某 session 时惰性连接，并注册事件处理器（表情采集 / 群成员变更）。
- 每个 client 一把 asyncio.Lock，串行化操作（对齐 PHP 侧限速模型）。
- 记录当前 QR token，供 /auth/qr 与 /auth/wait 跨请求复用。
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
import time

from telethon import TelegramClient
from telethon.sessions import SQLiteSession

from .config import settings
from .events import register_handlers

log = logging.getLogger(__name__)

# 授权状态：与 PHP 侧历史魔数保持一致（CollectServer 依赖 3=已登录 / 2=等待2FA）
AUTH_NOT = 0
AUTH_WAIT_PASSWORD = 2
AUTH_LOGGED_IN = 3


def normalize_session(session: str) -> str:
    """把 session_file（app/telegram/session_+86138.madeline）归一化成安全的 key。"""
    s = str(session)
    digits = re.findall(r"\d{6,}", s)
    if digits:
        return digits[-1]
    return re.sub(r"[^A-Za-z0-9]+", "_", s).strip("_") or "default"


def _session_path(session: str) -> str:
    os.makedirs(settings.SESSIONS_DIR, exist_ok=True)
    return os.path.join(settings.SESSIONS_DIR, f"telethon_{normalize_session(session)}")


class ManagedClient:
    def __init__(self, client: TelegramClient, session: str):
        self.client = client
        self.session = session
        self.lock = asyncio.Lock()
        self.qr_token = None
        self.phone = None
        self.connected = False


class ClientManager:
    def __init__(self):
        self._clients: dict[str, ManagedClient] = {}
        self._guard = asyncio.Lock()

    async def get(self, session: str, api_id: int, api_hash: str) -> ManagedClient:
        key = normalize_session(session)
        async with self._guard:
            mc = self._clients.get(key)
            if mc is not None:
                return mc
            client = TelegramClient(
                _session_path(session),
                int(api_id or settings.API_ID),
                api_hash or settings.API_HASH,
                # 显式使用文件 session（SQLite），挂载在共享卷
            )
            # SQLiteSession 会在 connect 时建库
            mc = ManagedClient(client=client, session=session)
            self._clients[key] = mc
        await self._ensure_connected(mc)
        return mc

    async def _ensure_connected(self, mc: ManagedClient):
        if mc.connected and mc.client.is_connected():
            return
        try:
            if not mc.client.is_connected():
                await mc.client.connect()
            mc.connected = True
            # 注册事件（表情采集 / 成员变更）——只注册一次
            if not getattr(mc, "_handlers_registered", False):
                register_handlers(mc)
                mc._handlers_registered = True
        except Exception as e:
            log.exception("connect %s failed: %s", mc.session, e)
            raise

    async def close_all(self):
        for mc in list(self._clients.values()):
            try:
                await mc.client.disconnect()
            except Exception:
                pass
        self._clients.clear()


manager = ClientManager()
