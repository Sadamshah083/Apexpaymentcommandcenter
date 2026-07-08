# Communications Hub — Browser Phone Calling Setup

This guide covers browser-based outbound and inbound calling through Morpheus and the **built-in Phone panel** (WebRTC webphone) in ApexOne Command Center.

> **Note:** SaraPhone is disabled. Use the **Phone** panel in Communications Hub only.

## Architecture (how a PSTN call works)

When `MORPHEUS_DIAL_METHOD=api` (required for US/PSTN numbers):

1. Agent opens Communications Hub → **Phone** panel auto-connects WebSocket to `wss://apexone.morpheus.cx:7443/`
2. Status shows **Registered** (check browser DevTools → Network → WS)
3. Agent enters number in dialer → **Call**
4. App calls `POST /admin/communications/morpheus/calls/originate` (Morpheus click-to-call API)
5. Morpheus sends SIP INVITE on WSS → webphone auto-answers (microphone required)
6. Morpheus dials the PSTN destination through the configured trunk/carrier
7. App polls `GET /admin/communications/morpheus/calls/{uuid}` for agent/destination status

**Do not** dial raw PSTN digits as a SIP URI from the browser — that causes SIP `404 Not Found`.

---

## 1. Application environment (`.env`)

```env
# Morpheus core
MORPHEUS_HOST=apexone.morpheus.cx
MORPHEUS_API_KEY=<your-api-key>
MORPHEUS_PLATFORM_API_KEY=<if-required-by-your-tenant>

# WebRTC / SIP
MORPHEUS_SIP_WSS_URL=wss://apexone.morpheus.cx:7443/
MORPHEUS_WEBRTC_SIP_DOMAIN=apexone.pbx.local
MORPHEUS_WEBRTC_ENABLED=true
MORPHEUS_WEBPHONE_AUTO_ANSWER=true

# Click-to-call (PSTN)
MORPHEUS_DIAL_METHOD=api
MORPHEUS_ORIGINATE_METHOD=click-to-call
MORPHEUS_DEFAULT_CAMPAIGN_ID=<required-campaign-uuid>

# Caller ID (anti-spam)
COMMUNICATIONS_DEFAULT_CALLER_ID_NAME="ApexOne Payments"

# SaraPhone (legacy — keep disabled)
MORPHEUS_SARAPHONE_ENABLED=false

# Optional carrier prefix (if trunk needs it)
MORPHEUS_OUTBOUND_PREFIX=
```

After changes: `php artisan config:cache` and reload PHP-FPM.

---

## 2. Morpheus / FreeSWITCH (server-side)

### Extensions (phone agents)

| Item | Example | Notes |
|------|---------|-------|
| Extension | `1001` | One WebRTC registration per extension |
| SIP password | From Morpheus Phone Agents | Used by webphone registration |
| SIP domain | `apexone.pbx.local` | Must match `MORPHEUS_WEBRTC_SIP_DOMAIN` |
| WSS URL | `wss://apexone.morpheus.cx:7443/` | TLS certificate must be valid in browser |
| Outbound DID | Per extension (see `config/morpheus_billing_dids.php`) | Shown as caller ID on destination |

### Campaign

- Create/assign a **campaign** in Morpheus for outbound dialing
- Set `MORPHEUS_DEFAULT_CAMPAIGN_ID` to that campaign's ID
- Without it, originate API calls fail

### Caller ID / CNAM (reduce spam flags)

- Each extension should have a unique billing DID
- CNAM name should be **ApexOne Payments** (not raw digits or "Billing Ext N")
- Carrier-side: register CNAM, STIR/SHAKEN, Free Caller Registry

### Trunk / carrier

- Trunk must route E.164 destinations (e.g. `+12722001232`)
- Verify dial prefix if carrier requires it (`MORPHEUS_OUTBOUND_PREFIX`)

---

## 3. Ports

| Port | Protocol | Purpose |
|------|----------|---------|
| **7443** | TCP (WSS) | Browser softphone (built-in Phone panel) |
| **443** | HTTPS | CRM + originate API |

---

## 4. Agent workflow

1. Open Communications Hub (dialer or inbox)
2. In **Phone** panel: pick extension → **Connect line** (or wait for auto-connect)
3. Allow microphone when prompted
4. Confirm status **Registered** and WebSocket shows `wss://apexone.morpheus.cx:7443/`
5. Enter destination number → **Call**
6. Your browser line rings briefly (auto-answer) → destination phone rings with **ApexOne Payments** + your extension DID

Close duplicate sessions before calling:

- Morpheus agent portal softphone for the same extension
- Another browser tab with Communications Hub for the same extension

---

## 5. Key URLs

| URL | Purpose |
|-----|---------|
| `/admin/communications/morpheus/calls/originate` | Click-to-call (POST) |
| `/admin/communications/morpheus/webphone/config` | Webphone SIP credentials (GET) |
| `wss://apexone.morpheus.cx:7443/` | Direct Morpheus SIP WebSocket (browser) |

---

## 6. Deploy

```bash
npm run build
python deploy/push_calling_hotfix.py
```

---

## 7. Troubleshooting

| Symptom | Check |
|---------|-------|
| No WebSocket in Network tab | Click **Connect line**; ensure `MORPHEUS_SIP_WSS_URL` is set |
| Originate 200 but no destination ring | Extension must be **Registered** before Call; check Morpheus campaign + trunk |
| Calls show as spam | CNAM = "ApexOne Payments"; per-extension DID assigned |
| `404 Not Found` on dial | Must use originate API, not raw SIP URI to PSTN |
