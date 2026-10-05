"""MadelineProto 风格实体数组 <-> Telethon 实体互转。

PHP 侧（MessageRenderer / EmojiService / resolveMentions）产出的实体是
MadelineProto 的数组形式：
    ['_' => 'messageEntityBold', 'offset' => 0, 'length' => 5]
这里转换成 Telethon 的 MessageEntity*。offset/length 已是 UTF-16 code unit，
直接透传（Telethon 同样用 UTF-16）。
"""
from __future__ import annotations

import io
import logging
import random

import qrcode
import qrcode.image.svg
from telethon.tl import types
from telethon.tl.types import ReplyInlineMarkup

log = logging.getLogger(__name__)

# MadelineProto 实体名 -> Telethon 类型（用 getattr 防御性取，缺失则跳过）
_SIMPLE_NAMES = {
    "messageEntityBold": "MessageEntityBold",
    "messageEntityItalic": "MessageEntityItalic",
    "messageEntityUnderline": "MessageEntityUnderline",
    "messageEntityStrike": "MessageEntityStrike",
    "messageEntitySpoiler": "MessageEntitySpoiler",
    "messageEntityCode": "MessageEntityCode",
    "messageEntityBlockquote": "MessageEntityBlockquote",
}


def render_qr_svg(url: str) -> str:
    """把登录 token URL 渲染成 SVG（对齐 MadelineProto getQRSvg 的返回形态）。"""
    img = qrcode.make(url, image_factory=qrcode.image.svg.SvgPathImage)
    buf = io.BytesIO()
    img.save(buf)
    return buf.getvalue().decode("utf-8")


async def convert_entities(client, entities) -> list:
    """MadelineProto 实体数组 -> Telethon 实体列表。"""
    out = []
    for raw in entities or []:
        if not isinstance(raw, dict):
            continue
        name = raw.get("_") or raw.get("type")
        offset = int(raw.get("offset", 0))
        length = int(raw.get("length", 0))
        if length <= 0:
            continue

        if name in _SIMPLE_NAMES:
            cls = getattr(types, _SIMPLE_NAMES[name], None)
            if cls:
                out.append(cls(offset=offset, length=length))
        elif name == "messageEntityPre":
            out.append(types.MessageEntityPre(offset=offset, length=length, language=raw.get("language", "")))
        elif name == "messageEntityTextUrl":
            url = raw.get("url", "")
            if url:
                out.append(types.MessageEntityTextUrl(offset=offset, length=length, url=url))
        elif name == "messageEntityMention":
            out.append(types.MessageEntityMention(offset=offset, length=length))
        elif name == "messageEntityHashtag":
            out.append(types.MessageEntityHashtag(offset=offset, length=length))
        elif name in ("messageEntityCustomEmoji", "messageEntityTextEffect"):
            doc_id = raw.get("document_id")
            if doc_id:
                out.append(types.MessageEntityCustomEmoji(offset=offset, length=length, document_id=int(doc_id)))
        elif name == "messageEntityMentionName":
            user_id = raw.get("user_id")
            if user_id:
                # 新版 Telethon：user_id 直接用整数，不再需要 InputUser
                out.append(types.MessageEntityMentionName(offset=offset, length=length, user_id=int(user_id)))
        else:
            log.debug("unsupported entity skipped: %s", name)
    return out


def _as_bytes(v) -> bytes:
    return v.encode("utf-8") if isinstance(v, str) else (v or b"")


def build_markup(buttons) -> ReplyInlineMarkup | None:
    """扁平按钮数组 -> inline keyboard（复刻原 buildReplyMarkup：每 2 个一行）。

    单个按钮支持：{text,url} / {text,callback_data} / {text,data} / {text}
    注意：新版 Telethon 用 KeyboardInlineButton + InlineButtonTypeUrl/Callback，
    回调数据必须是 bytes。
    """
    if not buttons:
        return None
    rows = []
    for chunk in _chunk(list(buttons), 2):
        row = []
        for b in chunk:
            if not isinstance(b, dict):
                continue
            text = b.get("text", "")
            if b.get("url"):
                btn_type = types.InlineButtonTypeUrl(url=b["url"])
            elif b.get("callback_data") is not None:
                btn_type = types.InlineButtonTypeCallback(data=_as_bytes(b["callback_data"]))
            elif b.get("data") is not None:
                btn_type = types.InlineButtonTypeCallback(data=_as_bytes(b["data"]))
            else:
                btn_type = types.InlineButtonTypeCallback(data=b"")
            row.append(types.KeyboardInlineButton(text=text, type=btn_type))
        if row:
            rows.append(types.KeyboardInlineButtonRow(buttons=row))
    return types.ReplyInlineMarkup(rows=rows) if rows else None


def _chunk(seq, size):
    for i in range(0, len(seq), size):
        yield seq[i:i + size]


def reply_to_obj(reply_to):
    """PHP 的 ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => N] -> Telethon。"""
    if not isinstance(reply_to, dict):
        return None
    if (reply_to.get("_") or "") == "inputReplyToMessage" and reply_to.get("reply_to_msg_id"):
        return types.InputReplyToMessage(reply_to_msg_id=int(reply_to["reply_to_msg_id"]))
    return None


def random_id() -> int:
    return random.randint(0, 2 ** 31 - 1)
