# MorpheusCX Calling Integration — ApexOne Command Center

**Tenant:** `apexone.morpheus.cx`  
**Production CRM:** `https://crm.apexonepayments.com`  
**Stack:** Laravel (backend) + Blade/Vite (frontend) + SIP.js (browser webphone)

This document describes how ApexOne integrates with MorpheusCX for outbound/inbound calling. It replaces generic Node.js examples with the **actual implementation in this repository**.

---

## Table of contents

1. [Architecture overview](#1-architecture-overview)
2. [Two different “WebSockets”](#2-two-different-websockets)
3. [Environment variables](#3-environment-variables)
4. [Security rules](#4-security-rules)
5. [CRM backend routes (your API)](#5-crm-backend-routes-your-api)
6. [Morpheus HTTP API (server only)](#6-morpheus-http-api-server-only)
7. [Click-to-call flow](#7-click-to-call-flow)
8. [Browser webphone flow](#8-browser-webphone-flow)
9. [Call status tracking](#9-call-status-tracking)
10. [Correct payloads](#10-correct-payloads)
11. [Testing commands](#11-testing-commands)
12. [Nginx configuration](#12-nginx-configuration)
13. [What can block a call to the destination](#13-what-can-block-a-call-to-the-destination)
14. [Troubleshooting “CONNECTING” / stuck calls](#14-troubleshooting-connecting--stuck-calls)
15. [Production checklist](#15-production-checklist)
16. [Key source files](#16-key-source-files)

---

## 1. Architecture overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│  Agent browser (Communications Hub)                                     │
│  ┌──────────────┐   HTTP (session auth)   ┌──────────────────────────┐  │
│  │ Dialer UI    │ ───────────────────────►│ Laravel CRM backend      │  │
│  │ Webphone UI  │                         │ MorpheusHubController    │  │
│  └──────┬───────┘                         │ ZoomApiService           │  │
│         │ SIP over WSS                    └────────────┬─────────────┘  │
│         │ (SIP.js, no API key)                        │ HTTPS + API key │
│         ▼                                             ▼                 │
│  wss://apexone.morpheus.cx:7443/          https://apexone.morpheus.cx   │
│  (Morpheus FreeSWITCH WebRTC)              /api/v1/call-control/*       │
└─────────────────────────────────────────────────────────────────────────┘
```

**Golden rule:** The Morpheus `ck_...` API key lives **only** in server `.env`. The browser never sees it.

| Layer | Protocol | Purpose |
|-------|----------|---------|
| CRM → Morpheus call control | HTTPS REST | Start/hangup/hold calls, read CDR, campaigns |
| Browser → Morpheus PBX | WSS + SIP | Register extension, receive INVITE, audio |
| CRM frontend → CRM backend | HTTPS (Laravel session) | Originate, call status, webphone config |

---

## 2. Two different “WebSockets”

Do not confuse these:

### A. Morpheus SIP WebSocket (required for browser phone)

| Setting | Value |
|---------|-------|
| URL | `wss://apexone.morpheus.cx:7443/` |
| Used by | `communications-webphone.js` via SIP.js |
| Auth | SIP digest (extension + password), **not** API key |
| Purpose | REGISTER, INVITE, BYE — actual phone audio |

**Important:**
- Do **not** open `wss://...` in the Chrome address bar (`ERR_UNKNOWN_URL_SCHEME` is normal).
- Do **not** use `crm.apexonepayments.com/morpheus-ws` for calling — it only passes REGISTER, not INVITE/BYE.
- Use **direct** `wss://apexone.morpheus.cx:7443/` (configured via `MORPHEUS_SIP_WSS_URL`).

### B. CRM internal real-time (optional / future)

The generic Morpheus docs describe a **separate** WebSocket for CRM UI updates (call status badges, wallboards). ApexOne currently uses:

- HTTP polling to `/admin/communications/morpheus/calls/{uuid}` every few seconds
- SIP.js events in the browser for live call audio

You do **not** need a Node.js `ws` server to place calls. Morpheus click-to-call is pure HTTP.

---

## 3. Environment variables

Production `.env` (server only — never commit real keys):

```env
# Morpheus Call-Control API
MORPHEUS_HOST=apexone.morpheus.cx
MORPHEUS_API_KEY=ck_your_real_key_here
MORPHEUS_DEFAULT_CAMPAIGN_ID=6c753496-2efd-4783-aa85-eb6ec73bc512
MORPHEUS_DIAL_METHOD=api
MORPHEUS_RING_TIMEOUT=30

# Outbound caller ID (PSTN digits, no +)
COMMUNICATIONS_DEFAULT_OUTBOUND_DID=+13133851223

# Browser webphone (SIP/WebRTC)
MORPHEUS_WEBRTC_ENABLED=true
MORPHEUS_WEBRTC_SIP_DOMAIN=apexone.pbx.local
MORPHEUS_SIP_WSS_URL=wss://apexone.morpheus.cx:7443/
MORPHEUS_WEBPHONE_AUTO_ANSWER=true
MORPHEUS_EXTENSION_PASSWORD=your_sip_password
MORPHEUS_STUN_SERVERS=stun:stun.l.google.com:19302

# Default extension in dialer dropdown
COMMUNICATIONS_DEFAULT_CALLER_ID=1020
```

Mapped in `config/integrations.php`:

| `.env` variable | Config key | Notes |
|-----------------|------------|-------|
| `MORPHEUS_API_KEY` | `integrations.morpheus.api_key` | Bearer + X-API-Key |
| `MORPHEUS_HOST` | `integrations.morpheus.host` | Tenant hostname |
| `MORPHEUS_DEFAULT_CAMPAIGN_ID` | `integrations.morpheus.default_campaign_id` | Required for click-to-call |
| `MORPHEUS_SIP_WSS_URL` | `integrations.morpheus.sip_wss_url` | Defaults to `:7443` |
| `COMMUNICATIONS_DEFAULT_OUTBOUND_DID` | `integrations.communications.default_outbound_did` | Fallback caller ID |

After changing `.env`:

```bash
php artisan config:cache
```

---

## 4. Security rules

1. **Never** put `MORPHEUS_API_KEY` in JavaScript, Blade templates, or `public/`.
2. Frontend sends only `extension` + `destination` — backend adds `campaign_id`, `caller_id_number`, `timeout_sec`.
3. `CommunicationsAgentService::userCanDialFrom()` ensures agents only dial from assigned extensions.
4. All Morpheus routes require Laravel authentication (`admin` middleware).
5. Rate limiting and circuit breaker (`MorpheusCircuitBreaker`) protect against Morpheus outages.

---

## 5. CRM backend routes (your API)

Base path: `/admin/communications/morpheus/`  
Registered in `routes/morpheus-communications.php`.

### Webphone

| Method | Route | Controller | Purpose |
|--------|-------|------------|---------|
| GET | `/webphone/config` | `webphoneConfig` | SIP credentials + WSS URL for extension |
| POST | `/webphone/prepare` | `prepareWebphone` | Validate extension before connect |

### Calls (agent-facing)

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/calls/originate` | Start click-to-call |
| GET | `/calls/{uuid}` | Poll call status (never 404 — returns `pending: true`) |
| POST | `/calls/{uuid}/hangup` | End call |
| POST | `/calls/{uuid}/hold` | Hold |
| POST | `/calls/{uuid}/transfer` | Transfer |
| POST | `/calls/{uuid}/disposition` | Save disposition |

### Frontend originate request (what the dialer sends)

```http
POST /admin/communications/morpheus/calls/originate
Content-Type: application/json
Accept: application/json

{
  "destination": "+12722001232",
  "from_extension": "1020"
}
```

Backend (`MorpheusHubController::originateCall`) adds campaign, caller ID, and calls Morpheus.

### Frontend originate response (success)

```json
{
  "ok": true,
  "action": "originate",
  "call_uuid": "7b00e122-5099-4239-823f-6412e878f46e",
  "from": "1020",
  "to": "12722001232",
  "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
  "caller_id_number": "13133851223",
  "internal_from": true,
  "attempted": ["POST /click-to-call"]
}
```

---

## 6. Morpheus HTTP API (server only)

Base URL:

```
https://apexone.morpheus.cx/api/v1/call-control
```

Auth headers (both accepted):

```
X-API-Key: ck_your_key
Authorization: Bearer ck_your_key
Accept: application/json
```

Implemented in `app/Services/Integrations/ZoomApiService.php`.

### List active calls

```bash
curl -s "https://apexone.morpheus.cx/api/v1/call-control/calls" \
  -H "Authorization: Bearer $MORPHEUS_API_KEY" \
  -H "Accept: application/json"
```

Response:

```json
{
  "calls": [
    {
      "uuid": "24698472-df5c-4dba-9b6e-4b78ace4471a",
      "status": "active",
      "direction": "outbound",
      "phone_number": "+15551234567",
      "campaign_id": "75dac1fd-9785-4ed7-a848-da467d2db544",
      "started_at": "2026-06-07T01:56:34Z"
    }
  ]
}
```

### Click-to-call

```bash
curl -s -X POST "https://apexone.morpheus.cx/api/v1/call-control/click-to-call" \
  -H "Authorization: Bearer $MORPHEUS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "extension": "1020",
    "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
    "destination": "12722001232",
    "timeout_sec": 30,
    "caller_id_number": "13133851223"
  }'
```

Response:







```json
{
  "ok": true,
  "call_uuid": "7b00e122-5099-4239-823f-6412e878f46e",
  "from": "1020",
  "to": "12722001232",
  "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
  "internal_from": true
}
```

### Retrieve a call

```bash
curl -s "https://apexone.morpheus.cx/api/v1/call-control/calls/{uuid}" \
  -H "Authorization: Bearer $MORPHEUS_API_KEY"
```

**Known limitation on ApexOne tenant:** `GET /calls/{uuid}` often returns `404` even immediately after a successful click-to-call. The CRM compensates with `pending: true` polling and CDR fallback in `ZoomApiService::resolveCallSnapshot()`.

---

## 7. Click-to-call flow

This is the sequence when an agent clicks **Call** in Communications Hub:

```
1. Agent selects extension (e.g. 1020) in "YOUR EXTENSION" dropdown
2. Agent clicks "Connect line" → webphone REGISTERs on wss://apexone.morpheus.cx:7443/
3. Status shows "Registered" (required before reliable calling)
4. Agent enters destination (+12722001232) and clicks Call
5. Frontend POST /admin/communications/morpheus/calls/originate
6. Laravel validates user, extension, phone number
7. ZoomApiService POST /click-to-call to Morpheus (with campaign_id, caller_id)
8. Morpheus returns call_uuid
9. Morpheus sends SIP INVITE to agent's browser webphone
10. Webphone auto-answers INVITE (MORPHEUS_WEBPHONE_AUTO_ANSWER=true)
11. Agent leg connected → Morpheus dials destination PSTN number
12. Destination rings with caller ID +13133851223
13. Frontend polls GET /calls/{uuid} until destination_connected or timeout
```

**UI message during step 9–10:**

> "Connecting your line… +12722001232 will ring once your browser phone answers."

This is **correct behavior** — the destination does not ring until the agent leg is answered.

---

## 8. Browser webphone flow

| Step | UI state | What happens |
|------|----------|--------------|
| Page load | Offline | Webphone panel shows extension + transport URL |
| Click "Connect line" | Connecting | SIP.js opens WSS to `:7443`, sends REGISTER |
| Success | **Registered** | Green status — ready for calls |
| Outbound click-to-call | Connecting / Dialing | Morpheus INVITE → auto-answer → bridge |
| Destination rings | Live | Audio flows, timer starts |
| Hangup | Registered | Back to idle |

### Verify WebSocket in browser

1. Open DevTools → **Network** → **WS**
2. Click **Connect line**
3. Confirm connection: `wss://apexone.morpheus.cx:7443/`
4. Look for SIP messages: `REGISTER`, then on outbound call `INVITE`, `200 OK`, `ACK`

### Webphone config API response (sanitized)

```json
{
  "enabled": true,
  "extension": "1020",
  "domain": "apexone.pbx.local",
  "wss_url": "wss://apexone.morpheus.cx:7443/",
  "outbound_caller_id": "13133851223",
  "auto_answer": true
}
```

Password is returned only to authenticated users via `prepareWebphone` — never logged.

---

## 9. Call status tracking

ApexOne does **not** rely solely on Morpheus `GET /calls/{uuid}`.

`ZoomApiService::resolveCallSnapshot()` tries in order:

1. `GET /calls/{uuid}` (live)
2. `GET /calls` (search active list)
3. `GET /cdr` (recent completed legs)

`MorpheusHubController::callStatus()` always returns HTTP 200:

```json
{
  "ok": true,
  "pending": true,
  "live": true,
  "state": "PENDING",
  "destination_connected": false,
  "billsec": 0
}
```

When CDR shows `billsec >= 2` on the PSTN leg, `destination_connected` becomes `true`.

Frontend polling: `communications-webphone.js` → `startDestinationPoll()`.

---

## 10. Correct payloads

### Valid click-to-call body

```json
{
  "extension": "1020",
  "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
  "destination": "12722001232",
  "timeout_sec": 30,
  "caller_id_number": "13133851223"
}
```

### Common mistakes

| Mistake | Result |
|---------|--------|
| `campiagn_id` (typo) | 400 / call not started |
| `timeout_sec: ""` (string) | 400 invalid body |
| `extension: 1001` (number not string) | May fail validation |
| Missing `campaign_id` | 400 `campaign_id is required` |
| API key in frontend | Security breach |
| Dial before **Registered** | Stuck on "CONNECTING" |
| Extension mismatch (dial 1001, webphone on 1007) | INVITE goes to wrong/offline extension |

---

## 11. Testing commands

Run from project root (requires SSH access to production):

```bash
# Verify WSS handshake + env
python deploy/verify_wss_direct.py

# Set WSS URL + verify webphone config
python deploy/fix_webphone_wss.py

# Test all Call-Control APIs (list, click-to-call, retrieve)
python deploy/test_morpheus_call_control_api.py

# Verify extension 1020 config
python deploy/verify_extension_1020.py

# Deploy latest webphone + calling code
npm run build
python deploy/push_calling_hotfix.py

# Check call status for a UUID
python deploy/verify_call_status.py <call-uuid>
```

### Quick auth test (on server)

```bash
curl -s "https://apexone.morpheus.cx/api/v1/call-control/calls" \
  -H "Authorization: Bearer $MORPHEUS_API_KEY" | jq .
```

| HTTP | Meaning |
|------|---------|
| 200 | API key valid |
| 401 | Invalid/missing key |
| 403 | Key lacks permission |

---

## 12. Nginx configuration

File: `deploy/nginx-apexone.conf`

### CRM site (HTTPS)

- Serves Laravel app at `crm.apexonepayments.com`
- Static assets from `public/build/`

### Morpheus WSS proxy (fallback only)

```nginx
location /morpheus-ws/ {
    proxy_pass https://apexone.morpheus.cx:7443/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    ...
}
```

**Production default:** Browser connects **directly** to `wss://apexone.morpheus.cx:7443/`.  
Use `/morpheus-ws/` only if corporate firewalls block port 7443 (note: call events may not pass through proxy).

---

## 13. What can block a call to the destination

Calls fail at different stages. Check in this order:

### Stage 1 — Before Morpheus accepts API request

| Blocker | Symptom | Fix |
|---------|---------|-----|
| Invalid JSON | 400 `invalid JSON body` | Fix commas, spelling `campaign_id` |
| Wrong API key | 401 | Rotate key in Morpheus portal, update `.env` |
| Missing `campaign_id` | 400 | Set `MORPHEUS_DEFAULT_CAMPAIGN_ID` |
| `timeout_sec` not integer | 400 | Use `30`, not `""` |
| Circuit breaker open | CRM error after Morpheus failures | `php artisan cache:forget integrations.morpheus.circuit_open` |

### Stage 2 — Agent leg (most common in CRM)

| Blocker | Symptom | Fix |
|---------|---------|-----|
| Webphone not **Registered** | Stuck "CONNECTING" | Click Connect line first |
| Wrong extension selected | INVITE to offline ext | Match dropdown + webphone extension |
| Extension offline in Morpheus | NO_ANSWER on agent CDR leg | Re-register webphone |
| Auto-answer disabled | INVITE rings, never bridges | Set `MORPHEUS_WEBPHONE_AUTO_ANSWER=true` |
| WSS blocked | Connect line fails | Allow `:7443` or use nginx proxy |
| SIP password wrong | Registration rejected | Sync password via Morpheus portal |

### Stage 3 — After agent answers (destination leg)

| Blocker | Symptom | Fix |
|---------|---------|-----|
| Invalid destination format | CDR shows garbage destination | Use 10+ digit E.164 |
| Caller ID not approved | Fast fail / carrier reject | Confirm DID with Morpheus |
| Spam labeling (STIR/SHAKEN) | Destination never rings | Test with your own mobile |
| Campaign routing | NO_ROUTE_DESTINATION | Check campaign outbound route |
| Lead on DNC | Morpheus blocks | Check campaign compliance |
| Carrier/trunk issue | hangup_cause varies | Contact Morpheus support |

### Stage 4 — Destination phone

| Blocker | Symptom |
|---------|---------|
| Phone off / no signal | NO_ANSWER |
| User blocked your number | NO_ANSWER or busy |
| Do Not Disturb | NO_ANSWER |

**`NO_ANSWER` ≠ blocked by API** — it usually means the call was attempted but nobody picked up.

---

## 14. Troubleshooting “CONNECTING” / stuck calls

If the UI shows **CONNECTING** / **Dialing** but the destination never rings:

### Checklist

- [ ] Webphone status is **Registered** (not Offline / Connecting)
- [ ] **YOUR EXTENSION** dropdown matches the connected webphone extension
- [ ] DevTools → WS shows `wss://apexone.morpheus.cx:7443/` with SIP traffic
- [ ] On dial, WS shows incoming `INVITE` (Morpheus ringing agent)
- [ ] `MORPHEUS_WEBPHONE_AUTO_ANSWER=true` in production `.env`
- [ ] Hard refresh (Ctrl+Shift+R) after deploy — load latest `communications-webphone-*.js`
- [ ] Originate response has `ok: true` and a `call_uuid`

### Extension mismatch example

If the center panel shows **Extension 1001** but the dropdown shows **1007**:

- Click-to-call may ring extension **1001** (from form submit)
- Webphone may be registered as **1007**
- Result: stuck on CONNECTING forever

**Fix:** Select the same extension in the dropdown, click **Connect line**, wait for Registered, then dial.

### Debug originate in DevTools

Network tab → filter `originate`:

```
POST .../morpheus/calls/originate  →  200, ok: true, call_uuid: ...
GET  .../morpheus/calls/{uuid}      →  pending: true (normal while connecting)
```

Network tab → WS → look for `INVITE sip:...` after originate.

---

## 15. Production checklist

- [ ] `MORPHEUS_API_KEY` set in server `.env` (never in git)
- [ ] `MORPHEUS_DEFAULT_CAMPAIGN_ID=6c753496-2efd-4783-aa85-eb6ec73bc512`
- [ ] `MORPHEUS_DIAL_METHOD=api`
- [ ] `MORPHEUS_SIP_WSS_URL=wss://apexone.morpheus.cx:7443/`
- [ ] `MORPHEUS_WEBPHONE_AUTO_ANSWER=true`
- [ ] `COMMUNICATIONS_DEFAULT_OUTBOUND_DID=+13133851223`
- [ ] `php artisan config:cache` after env changes
- [ ] `npm run build` + `python deploy/push_calling_hotfix.py`
- [ ] `python deploy/verify_wss_direct.py` → OK
- [ ] Agent can Connect line → Registered
- [ ] Test call to your own mobile succeeds
- [ ] CDR shows PSTN leg with correct destination (not SIP username)

---

## 16. Key source files

| File | Role |
|------|------|
| `app/Services/Integrations/ZoomApiService.php` | Morpheus HTTP client, click-to-call, CDR |
| `app/Http/Controllers/MorpheusHubController.php` | Originate, callStatus, hangup |
| `app/Services/Communications/CommunicationsWebphoneService.php` | WSS URL, SIP config |
| `app/Services/Communications/CommunicationsAgentService.php` | Per-extension caller ID + campaign |
| `app/Support/MorpheusSipIdentity.php` | Safe SIP display names (numeric DID) |
| `resources/js/communications-webphone.js` | SIP.js, WSS connect, auto-answer |
| `resources/js/communications-dialer.js` | Originate form → Laravel JSON |
| `config/integrations.php` | All Morpheus env mapping |
| `routes/morpheus-communications.php` | API routes |
| `deploy/nginx-apexone.conf` | HTTPS + optional WSS proxy |
| `deploy/push_calling_hotfix.py` | Production deploy script |

---

## Quick reference — ApexOne tenant values

| Setting | Value |
|---------|-------|
| Morpheus host | `apexone.morpheus.cx` |
| Call-Control base | `https://apexone.morpheus.cx/api/v1/call-control` |
| SIP realm | `apexone.pbx.local` |
| WSS URL | `wss://apexone.morpheus.cx:7443/` |
| Campaign ID | `6c753496-2efd-4783-aa85-eb6ec73bc512` |
| Default outbound DID | `+13133851223` |
| Test destination | `+12722001232` |
| Production CRM | `https://crm.apexonepayments.com` |

---

*Last updated: July 2026 — matches deployed Communications Hub on `crm.apexonepayments.com`.*
