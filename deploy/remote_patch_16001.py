#!/usr/bin/env python3
"""Point pharmacy bot at bridge agents 16001+ (not Medicare 15001)."""
import pathlib
import re

# --- vicidial_api.py ---
api = pathlib.Path("/opt/voicebot/vicidial_api.py")
t = api.read_text()
t = t.replace('"user": os.getenv("VICIDIAL_API_USER", "15001"),', '"user": os.getenv("VICIDIAL_API_USER", "16001"),')
t = t.replace('"source": os.getenv("VICIDIAL_API_SOURCE", "medicarebot"),', '"source": os.getenv("VICIDIAL_API_SOURCE", "pharmacybot"),')

old_fetch = '''def fetch_logged_in_agents(*, force: bool = False) -> list[list[str]]:
    now = time.time()
    with _global_lock:
        if (
            not force
            and _agents_cache["rows"]
            and now - _agents_cache["ts"] < _CACHE_TTL_SEC
        ):
            return list(_agents_cache["rows"])
    try:
        result = non_agent_api_call({"function": "logged_in_agents"})
        rows = [line.split("|") for line in result.splitlines() if "|" in line]
    except Exception:
        rows = []
    with _global_lock:
        _agents_cache["ts"] = now
        _agents_cache["rows"] = rows
    return list(rows)'''

new_fetch = '''def fetch_logged_in_agents(*, force: bool = False) -> list[list[str]]:
    """Return logged-in pharmacy bridge agents (16001+), not Medicare 15001."""
    bridge_prefix = os.getenv("VICIDIAL_BRIDGE_AGENT_PREFIX", "160")
    now = time.time()
    with _global_lock:
        if (
            not force
            and _agents_cache["rows"]
            and now - _agents_cache["ts"] < _CACHE_TTL_SEC
        ):
            return list(_agents_cache["rows"])
    try:
        result = non_agent_api_call({"function": "logged_in_agents"})
        rows = []
        for line in result.splitlines():
            if "|" not in line or line.startswith("ERROR"):
                continue
            parts = line.split("|")
            agent = parts[0].strip()
            if agent.startswith(bridge_prefix) and agent.isdigit():
                rows.append(parts)
    except Exception:
        rows = []
    with _global_lock:
        _agents_cache["ts"] = now
        _agents_cache["rows"] = rows
    return list(rows)'''

if old_fetch in t:
    t = t.replace(old_fetch, new_fetch, 1)
    print("patched fetch_logged_in_agents (160xx filter)")
else:
    print("fetch_logged_in_agents skip")
api.write_text(t)

# --- set_vicidial_dispo.py ---
dispo = pathlib.Path("/opt/voicebot/set_vicidial_dispo.py")
d = dispo.read_text()
d = d.replace('_BRIDGE_AGENT_PREFIX = os.getenv("VICIDIAL_BRIDGE_AGENT_PREFIX", "150")', '_BRIDGE_AGENT_PREFIX = os.getenv("VICIDIAL_BRIDGE_AGENT_PREFIX", "160")')
d = d.replace('VICIDIAL_CAMPAIGN_ID = os.getenv("VICIDIAL_CAMPAIGN_ID", "001")', 'VICIDIAL_CAMPAIGN_ID = os.getenv("VICIDIAL_CAMPAIGN_ID", "002")')
dispo.write_text(d)
print("patched set_vicidial_dispo defaults")

# --- connect_qualified_call comment ---
cq = pathlib.Path("/opt/voicebot/connect_qualified_call.py")
cqt = cq.read_text().replace("15001-15010", "16001+")
cq.write_text(cqt)
print("patched connect_qualified comment")

# --- .env ---
env = pathlib.Path("/opt/voicebot/.env")
text = env.read_text()
updates = {
    "VICIDIAL_API_USER": "16001",
    "VICIDIAL_BRIDGE_AGENT_PREFIX": "160",
    "VICIDIAL_DEFAULT_AGENT": "16001",
    "VICIDIAL_CAMPAIGN_ID": "002",
}
for key, val in updates.items():
    pat = re.compile(r"^" + re.escape(key) + r"=.*$", re.M)
    line = f"{key}={val}"
    text = pat.sub(line, text) if pat.search(text) else text.rstrip() + "\n" + line + "\n"
env.write_text(text)
print("patched .env", ", ".join(updates))

# --- extensions.conf API user in URLs ---
ext = pathlib.Path("/etc/asterisk/extensions.conf")
et = ext.read_text()
et = et.replace("user=15001&pass=", "user=16001&pass=")
ext.write_text(et)
print("patched extensions.conf API user 16001")
