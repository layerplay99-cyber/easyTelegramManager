"""Telethon 服务入口。"""
from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import Body, FastAPI, HTTPException
from telethon.errors import SessionPasswordNeededError

from . import media, ops
from .manager import AUTH_LOGGED_IN, AUTH_NOT, AUTH_WAIT_PASSWORD, manager

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("telegram-service")


@asynccontextmanager
async def lifespan(app: FastAPI):
    yield
    await manager.close_all()


app = FastAPI(title="Telethon Service", lifespan=lifespan)


async def authorization(client) -> int:
    """3=已登录 / 2=等待2FA / 0=其它（对齐 PHP 历史魔数）。"""
    if await client.is_user_authorized():
        return AUTH_LOGGED_IN
    try:
        await asyncio.wait_for(client.sign_in(), timeout=10)
        return AUTH_LOGGED_IN
    except SessionPasswordNeededError:
        return AUTH_WAIT_PASSWORD
    except Exception:
        return AUTH_NOT


async def mc_of(b: dict):
    return await manager.get(b.get("session", ""), int(b.get("api_id") or 0), b.get("api_hash") or "")


async def authed(b: dict):
    mc = await mc_of(b)
    if not await mc.client.is_user_authorized():
        raise HTTPException(status_code=401, detail="not authorized")
    return mc


@app.get("/health")
async def health():
    return {"ok": True}


@app.post("/auth/status")
async def auth_status(b: dict = Body(...)):
    mc = await mc_of(b)
    code = await authorization(mc.client)
    me = None
    if code == AUTH_LOGGED_IN:
        try:
            m = await mc.client.get_me()
            me = {"id": m.id, "phone": getattr(m, "phone", None)}
        except Exception:
            pass
    return {"authorization": code, "logged_in": code == AUTH_LOGGED_IN, "me": me}


@app.post("/auth/qr")
async def auth_qr(b: dict = Body(...)):
    mc = await mc_of(b)
    if await mc.client.is_user_authorized():
        return {"svg": None, "logged_in": True}
    return {"svg": await ops.get_login_token(mc.client), "logged_in": False}


@app.post("/auth/wait")
async def auth_wait(b: dict = Body(...)):
    mc = await mc_of(b)
    loop = asyncio.get_event_loop()
    deadline = loop.time() + max(1, int(b.get("timeout") or 25))
    while loop.time() < deadline:
        code = await authorization(mc.client)
        if code == AUTH_LOGGED_IN:
            return {"logged_in": True, "authorization": code, "svg": None}
        if code == AUTH_WAIT_PASSWORD:
            return {"logged_in": False, "require_2fa": True, "authorization": code, "svg": None}
        await asyncio.sleep(1)
    return {"logged_in": False, "expired": True, "authorization": 0,
            "svg": await ops.get_login_token(mc.client)}


@app.post("/auth/code")
async def auth_code(b: dict = Body(...)):
    mc = await mc_of(b)
    await ops.sign_in_code(mc.client, b.get("phone", ""), b.get("code", ""))
    return {"ok": True}


@app.post("/auth/2fa")
async def auth_2fa(b: dict = Body(...)):
    mc = await mc_of(b)
    await ops.sign_in_password(mc.client, b.get("password", ""))
    return {"ok": True}


@app.post("/auth/logout")
async def auth_logout(b: dict = Body(...)):
    mc = await mc_of(b)
    try:
        await mc.client.log_out()
    except Exception as e:
        log.warning("logout: %s", e)
    return {"ok": True}


@app.post("/msg/sendText")
async def send_text(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return await ops.send_text(mc.client, b["chat_id"], b.get("text", ""),
                                   buttons=b.get("buttons"), entities=b.get("entities"),
                                   reply_to=b.get("reply_to"))


@app.post("/msg/sendMedia")
async def send_media(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return await ops.send_media(mc.client, b["chat_id"], text=b.get("text", ""),
                                   buttons=b.get("buttons"), reply_to=b.get("reply_to"),
                                   file_b64=b.get("file_b64"), file_url=b.get("file_url"),
                                   filename=b.get("filename", "upload.jpg"))


@app.post("/msg/kick")
async def kick(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return await ops.kick_user(mc.client, b["chat_id"], b["user_id"])


@app.post("/groups/dialogs")
async def dialogs(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return {"groups": await ops.get_groups(mc.client)}


@app.post("/groups/members")
async def members(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return {"members": await ops.get_members(mc.client, b["chat_id"])}


@app.post("/contacts/resolvePhone")
async def resolve_phone_ep(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return await ops.resolve_phone(mc.client, b.get("phone", ""))


@app.post("/contacts/resolveUsers")
async def resolve_users_ep(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return {"users": await ops.resolve_users(mc.client, b.get("user_ids") or [])}


@app.post("/media/avatar")
async def avatar(b: dict = Body(...)):
    mc = await authed(b)
    async with mc.lock:
        return {"path": await media.download_avatar(mc.client, b["user_id"], b.get("filename"))}


@app.post("/admin/sync-sessions")
async def sync_sessions(b: dict = Body(...)):
    ok = []
    for s in b.get("sessions") or []:
        try:
            mc = await manager.get(s["session"], int(s.get("api_id") or 0), s.get("api_hash") or "")
            if await mc.client.is_user_authorized():
                ok.append(s["session"])
        except Exception as e:
            log.warning("sync %s: %s", s.get("session"), e)
    return {"connected": ok}
