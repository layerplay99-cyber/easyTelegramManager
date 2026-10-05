"""业务操作：把 MadelineProto 时期的调用语义落到 Telethon。"""
from __future__ import annotations

import base64
import logging
import os
import tempfile

from telethon import utils
from telethon.tl.functions.channels import EditBannedRequest
from telethon.tl.functions.contacts import ResolvePhoneRequest
from telethon.tl.functions.messages import SendMessageRequest
from telethon.tl.types import ChatBannedRights

from .entities import build_markup, convert_entities, random_id, render_qr_svg, reply_to_obj

log = logging.getLogger(__name__)


async def _peer(client, chat_id):
    cid = chat_id
    if isinstance(cid, str):
        cid = cid.strip()
        cid = int(cid) if cid.lstrip("-").isdigit() else cid
    try:
        return await client.get_input_entity(cid)
    except Exception:
        return await client.get_input_entity(await client.get_entity(cid))


async def get_login_token(client):
    token = await client.qr_login()
    return render_qr_svg(token.url)


async def sign_in_code(client, phone: str, code: str):
    await client.sign_in(phone=phone, code=code)


async def sign_in_password(client, password: str):
    await client.sign_in(password=password)


async def send_text(client, chat_id, text, buttons=None, entities=None, reply_to=None):
    peer = await _peer(client, chat_id)
    res = await client(SendMessageRequest(
        peer=peer, message=text, random_id=random_id(),
        entities=await convert_entities(client, entities),
        reply_markup=build_markup(buttons), reply_to=reply_to_obj(reply_to),
    ))
    return {"message_id": res.id, "peer_id": utils.get_peer_id(peer)}


async def send_media(client, chat_id, text="", buttons=None, reply_to=None,
                     file_b64=None, file_url=None, filename="upload.bin"):
    peer = await _peer(client, chat_id)
    tmp = None
    try:
        suffix = os.path.splitext(filename)[1] or ".jpg"
        if file_b64:
            fd, tmp = tempfile.mkstemp(suffix=suffix)
            with os.fdopen(fd, "wb") as f:
                f.write(base64.b64decode(file_b64))
        elif file_url:
            import httpx
            fd, tmp = tempfile.mkstemp(suffix=suffix)
            os.close(fd)
            async with httpx.AsyncClient(timeout=30) as c:
                with open(tmp, "wb") as f:
                    f.write((await c.get(file_url)).content)
        else:
            raise ValueError("send_media requires file_b64 or file_url")
        rto = reply_to_obj(reply_to)
        msg = await client.send_file(peer, tmp, caption=text or None,
                                     reply_to=(rto.reply_to_msg_id if rto else None),
                                     buttons=build_markup(buttons))
        return {"message_id": msg.id if msg else None, "peer_id": utils.get_peer_id(peer)}
    finally:
        if tmp and os.path.exists(tmp):
            try:
                os.unlink(tmp)
            except OSError:
                pass


async def kick_user(client, chat_id, user_id):
    rights = ChatBannedRights(view_messages=True, until_date=0)
    await client(EditBannedRequest(await _peer(client, chat_id), user_id, rights))
    return {"ok": True}


async def get_groups(client):
    groups = []
    async for dialog in client.iter_dialogs():
        name = type(dialog.entity).__name__
        if name not in ("Chat", "Channel"):
            continue
        kind = ("supergroup" if getattr(dialog.entity, "megagroup", False) else "channel") \
            if name == "Channel" else "group"
        groups.append({
            "id": utils.get_peer_id(dialog.entity),
            "title": getattr(dialog, "title", None) or "未知群组",
            "type": kind,
        })
    return groups


async def get_members(client, chat_id):
    out = []
    async for p in client.get_participants(await _peer(client, chat_id)):
        user = getattr(p, "user", None)
        uid = getattr(user, "id", None) or getattr(p, "user_id", None)
        if not uid:
            continue
        date = getattr(p, "date", None)
        out.append({
            "user_id": int(uid),
            "username": getattr(user, "username", None),
            "bot": bool(getattr(user, "bot", False)),
            "date": int(date.timestamp()) if hasattr(date, "timestamp") else None,
        })
    return out


def _display_name(user) -> str:
    return (getattr(user, "first_name", None)
            or getattr(user, "username", None)
            or f"用户{getattr(user, 'id', '')}")


async def resolve_phone(client, phone: str):
    res = await client(ResolvePhoneRequest(phone=phone))
    user = res.users[0] if res.users else None
    if user is None:
        return {"user": None}
    status = getattr(user, "status", None)
    was_online = bool(getattr(status, "was_online", None)) if status else False
    return {"user": {
        "id": user.id,
        "first_name": getattr(user, "first_name", None),
        "last_name": getattr(user, "last_name", None),
        "username": getattr(user, "username", None),
        "about": getattr(user, "about", None),
        "has_photo": getattr(user, "photo", None) is not None,
        "status": {"was_online": was_online},
    }}


async def resolve_users(client, user_ids):
    out = []
    for uid in user_ids or []:
        try:
            user = await client.get_entity(int(uid))
            out.append({"id": int(uid), "display_name": _display_name(user)})
        except Exception as e:
            log.warning("resolve user %s failed: %s", uid, e)
    return out
