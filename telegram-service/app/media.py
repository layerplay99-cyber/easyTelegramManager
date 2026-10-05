"""媒体下载：把用户头像存到共享媒体目录，供 PHP 侧读取。"""
from __future__ import annotations

import logging
import os

from .config import settings

log = logging.getLogger(__name__)


async def download_avatar(client, user_id, filename: str | None = None) -> str:
    """下载指定用户头像到 MEDIA_DIR，返回绝对路径。

    MEDIA_DIR 与 PHP 容器共享同一个卷，因此 PHP 能直接读取。
    """
    user = await client.get_entity(int(user_id))
    photo = getattr(user, "photo", None)
    if photo is None:
        raise ValueError("user has no photo")

    name = filename or f"avatars/{int(user_id)}.jpg"
    target = os.path.join(settings.MEDIA_DIR, name)
    os.makedirs(os.path.dirname(target), exist_ok=True)

    got = await client.download_media(photo, file=target)
    return got or target
