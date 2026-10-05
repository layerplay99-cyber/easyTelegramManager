"""配置：全部从环境变量读取。"""
import os


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, default))
    except (TypeError, ValueError):
        return default


class Settings:
    # 账号凭证兜底（正常由 Laravel 每次请求带 app_id/app_hash 传入）
    API_ID = _int("TELEGRAM_API_ID", 0)
    API_HASH = os.getenv("TELEGRAM_API_HASH", "")

    # Telethon .session 与下载媒体目录
    SESSIONS_DIR = os.getenv("SESSIONS_DIR", "/data/sessions")
    MEDIA_DIR = os.getenv("MEDIA_DIR", "/data/media")

    # 事件回调到 Laravel
    LARAVEL_BASE_URL = os.getenv("LARAVEL_BASE_URL", "http://php")
    CALLBACK_TOKEN = os.getenv("CALLBACK_TOKEN", "")

    # 采集表情开关用的 Redis（与 Laravel 共用）
    REDIS_URL = os.getenv("REDIS_URL", "redis://redis:6379/0")
    EMOJI_SWITCH_PREFIX = os.getenv("EMOJI_SWITCH_PREFIX", "telegram_group_collect_emojis_")

    LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO")


settings = Settings()
