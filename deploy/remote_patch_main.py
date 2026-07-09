#!/usr/bin/env python3
import pathlib
import re

path = pathlib.Path("/opt/voicebot/main.py")
text = path.read_text()

old_skip = '''    if skip_side_effects:
        try:
            from set_vicidial_dispo import apply_disposition, resolve_agent_user

            phone = (phone_number or "").strip()
            ra_callid = ""
            agent_user = ""
            if redis_client:
                if not phone or phone == "Unknown":
                    p_val = await redis_client.get(f"uuid_phone:{client_id}")
                    if p_val:
                        phone = p_val.decode("utf-8", errors="ignore") if isinstance(p_val, bytes) else str(p_val)
                ra_val = await redis_client.get(f"uuid_ra_callid:{client_id}")
                if ra_val:
                    ra_callid = ra_val.decode("utf-8", errors="ignore") if isinstance(ra_val, bytes) else str(ra_val)
                u_val = await redis_client.get(f"uuid_vici_user:{client_id}")
                if u_val:
                    agent_user = u_val.decode("utf-8", errors="ignore") if isinstance(u_val, bytes) else str(u_val)
            if ra_callid or agent_user:
                agent_user = await asyncio.to_thread(resolve_agent_user, agent_user, ra_callid)
            dispo_results = await asyncio.to_thread(
                apply_disposition, phone, disposition, ra_callid, agent_user, True
            )
            logger.info(
                f"vicidial_dispo lead/flag confirm for {client_id} (terminal handled): "
                f"agent={agent_user} lead={dispo_results.get('lead', '')[:80]} "
                f"call_log={dispo_results.get('call_log', '')[:80]} "
                f"agent_screen={dispo_results.get('agent_screen', '')[:80]}"
            )
        except Exception as e:
            logger.error(f"vicidial_dispo lead confirm failed for {client_id}: {e}")
        return'''

new_skip = '''    if skip_side_effects:
        logger.debug(f"Skipping duplicate Vicidial dispo push for {client_id} (terminal already handled)")
        return'''

old_terminal_else = '''        else:
            dispo_results = await asyncio.to_thread(
                apply_disposition, phone, early_dispo, ra_callid, agent_user, True
            )
            logger.info(
                f"Terminal dispo lead={dispo_results.get('lead', '')[:80]} "
                f"call_log={dispo_results.get('call_log', '')[:80]} "
                f"agent_screen={dispo_results.get('agent_screen', '')[:80]}"
            )
            if ra_callid:
                await asyncio.to_thread(hangup_remote_agent, ra_callid, agent_user)
            if channel_name:
                await asyncio.to_thread(release_asterisk_channel, channel_name)'''

new_terminal_else = '''        else:
            if channel_name:
                rel = await asyncio.to_thread(release_asterisk_channel, channel_name)
                logger.info(f"Terminal channel release ({reason}) for {client_id}: {rel[:120]}")
            dispo_results = await asyncio.to_thread(
                apply_disposition, phone, early_dispo, ra_callid, agent_user, True
            )
            logger.info(
                f"Terminal dispo lead={dispo_results.get('lead', '')[:80]} "
                f"call_log={dispo_results.get('call_log', '')[:80]} "
                f"agent_screen={dispo_results.get('agent_screen', '')[:80]}"
            )
            if ra_callid:
                await asyncio.to_thread(hangup_remote_agent, ra_callid, agent_user)'''

old_finally_push = '''            await cache_call_disposition(app.state.redis, client_id, disposition)
            if state.action_triggered or state.terminal_handled:
                await cache_bot_handled(app.state.redis, client_id)
            await push_vicidial_disposition(
                app.state.redis,
                client_id,
                phone_number,
                disposition,
                xfer_attempted=state.xfer_attempted,
                skip_side_effects=state.terminal_handled,
            )
            if not state.terminal_handled:
                await cache_bot_handled(app.state.redis, client_id)
            if state.terminal_handled:
                await asyncio.sleep(0.4)

            if app.state.redis:
                await app.state.redis.delete(f"active_calls:{client_id}")
            await voice_pool.release_voice(app.state.redis, client_id)

            # Record log to Postgres asynchronously
            if app.state.pg_pool:'''

new_finally_push = '''            await cache_call_disposition(app.state.redis, client_id, disposition)
            if state.action_triggered or state.terminal_handled:
                await cache_bot_handled(app.state.redis, client_id)

            _release_channel = ""
            if app.state.redis:
                _c_val = await app.state.redis.get(f"uuid_channel:{client_id}")
                if _c_val:
                    _release_channel = _c_val.decode("utf-8", errors="ignore") if isinstance(_c_val, bytes) else str(_c_val)
            if _release_channel and not state.terminal_handled:
                try:
                    from set_vicidial_dispo import release_asterisk_channel
                    _rel = await asyncio.to_thread(release_asterisk_channel, _release_channel)
                    logger.info(f"Pre-close channel release for {client_id}: {_rel[:120]}")
                except Exception as _rel_exc:
                    logger.warning(f"Pre-close channel release failed for {client_id}: {_rel_exc}")

            try:
                await websocket.close()
            except Exception:
                pass

            if not state.terminal_handled:
                asyncio.create_task(
                    push_vicidial_disposition(
                        app.state.redis,
                        client_id,
                        phone_number,
                        disposition,
                        xfer_attempted=state.xfer_attempted,
                        skip_side_effects=state.terminal_handled,
                    )
                )
            if not state.terminal_handled:
                await cache_bot_handled(app.state.redis, client_id)

            if app.state.redis:
                await app.state.redis.delete(f"active_calls:{client_id}")
            await voice_pool.release_voice(app.state.redis, client_id)

            # Record log to Postgres asynchronously
            if app.state.pg_pool:'''

old_dup_close = '''        # Ensure WebSocket is closed so the bridge tears down the Asterisk channel
        try:
            await websocket.close()
        except Exception:
            pass
        receiver_task.cancel()'''

new_dup_close = '''        # WebSocket already closed above before Vicidial push (keeps AudioSocket from stalling).
        receiver_task.cancel()'''

for name, old, new in [
    ("skip_side_effects", old_skip, new_skip),
    ("run_vicidial_terminal", old_terminal_else, new_terminal_else),
    ("finally block", old_finally_push, new_finally_push),
    ("duplicate close", old_dup_close, new_dup_close),
]:
    if old in text:
        text = text.replace(old, new, 1)
        print(f"patched {name}")
    else:
        print(f"SKIP {name} (not found)")

path.write_text(text)
assert "MAX_CALL_DURATION_SEC = 150" in text
print("MAX_CALL_DURATION_SEC still 150")
