# Morpheus Outbound Ring — Debug Report for Babar

**Tenant:** `apexone.morpheus.cx`  
**CRM server:** `203.215.160.44` (`crm.apexonepayments.com`)  
**Report date:** 2026-07-07 (UTC)  
**Prepared by:** Apex One / Communications Hub team  

---

## Summary

Outbound dialing from the CRM **accepts API requests** (`HTTP 200` + `call_uuid`) but **calls are not created on the PBX**. Immediately after originate:

- `GET /api/v1/call-control/calls/{uuid}` → **404** `"no call with that uuid in your tenant"`
- `GET /api/v1/call-control/calls` → **0 active calls**
- **No new CDR rows** for the returned UUIDs

This affects **both** endpoints:

| Endpoint | Path |
|----------|------|
| Click-to-call | `POST /api/v1/call-control/click-to-call` |
| Originate | `POST /api/v1/call-control/calls/originate` |

**Request:** Please check FreeSWITCH originate routing / tenant call creation for campaign `6c753496-2efd-4783-aa85-eb6ec73bc512` and extensions **1001**, **1004**, **1020**.

---

## Environment

| Setting | Value |
|---------|--------|
| Campaign ID | `6c753496-2efd-4783-aa85-eb6ec73bc512` ("Outbound") |
| Campaign dial_mode | `manual` |
| Campaign status | `active` |
| ring_timeout | `90` |
| drop_timeout | `45` |
| Outbound DID (caller ID) | `13133851223` |
| Trunk | contactivity (`167.235.102.215`, tech prefix `482983#` — applied server-side, not in API destination) |
| Test PSTN destination | `12722001232` (+1) |
| API host | `https://apexone.morpheus.cx/api/v1/call-control` |

---

## Issue A — Call UUID returned but call never exists (CRITICAL)

### Pattern (all tests)

```
POST /click-to-call  →  HTTP 200, ok: true, call_uuid: <uuid>
GET  /calls/<uuid>   →  HTTP 404, {"error":"no call with that uuid in your tenant"}
GET  /calls          →  {"calls":[]}  (0 active)
```

### Test UUIDs — POST 200, GET 404

| # | Endpoint | Extension | call_uuid | Notes |
|---|----------|-----------|-----------|-------|
| 1 | click-to-call | 1001 | `e16413f3-bc41-4cc4-b93d-9f3729da2cae` | Manual hit for Babar visibility |
| 2 | click-to-call | 1001 | `219ed039-7ace-497d-96f0-cd1c1d730a25` | Ring test |
| 3 | calls/originate | 1001 | `4d160a0d-ead8-4c9c-9115-9738a119ab8b` | Ring test |
| 4 | app originateCall | 1001 | `b17fed6c-007e-4925-9caf-92ae6b33d216` | CRM dialer path |
| 5 | click-to-call | 1001 | `b8ae55a2-7820-42ed-89da-ad5d11c00e02` | Deep ring test (no kick) |
| 6 | app originateCall | 1001 | `dbea7944-0370-47bb-ba8d-6013426144b0` | After campaign verify |
| 7 | click-to-call | 1001 | `263aecb8-b91b-4fe1-bc3a-7ca4721a126e` | Raw API test |
| 8 | calls/originate | 1001 | `8fc809f4-4670-4543-b102-ff021a2b26dd` | Raw API test |
| 9 | click-to-call | 1001 | `4c2dd239-8e54-473e-a1fd-903ca4dfb093` | Payload variant: minimal |
| 10 | click-to-call | 1001 | `e83c0204-1006-45c0-8f70-25c10b2097d5` | Payload variant: full |
| 11 | calls/originate | 1001 | `bd475610-39ba-40bc-bd0b-2dba7cd38746` | Payload variant: minimal |
| 12 | calls/originate | 1001 | `525bfa3e-2d24-4ca0-89ae-530d99843eb3` | Payload variant: full |
| 13 | click-to-call | 1004 | `fe2a1731-2cb8-4553-83dd-fb6d7e7a7d1b` | Extension 1004 |
| 14 | app originateCall | 1001 | `647daee8-d90a-4fdf-a6cb-c362bfa2c224` | After CRM validation deploy |
| 15 | click-to-call | 1001 | `d6abc51f-d741-4fea-aded-8008e2a771e2` | **Latest** — POST took ~7s, still 404 |

### Example request (click-to-call)

```http
POST https://apexone.morpheus.cx/api/v1/call-control/click-to-call
X-API-Key: <tenant API key>

{
  "extension": "1001",
  "destination": "12722001232",
  "timeout_sec": 90,
  "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
  "caller_id_number": "13133851223"
}
```

### Example response

```json
{
  "ok": true,
  "call_uuid": "d6abc51f-d741-4fea-aded-8008e2a771e2",
  "campaign_id": "6c753496-2efd-4783-aa85-eb6ec73bc512",
  "from": "1001",
  "internal_from": true,
  "to": "12722001232"
}
```

### Example follow-up (3 seconds later)

```http
GET https://apexone.morpheus.cx/api/v1/call-control/calls/d6abc51f-d741-4fea-aded-8008e2a771e2
```

```json
{"error": "no call with that uuid in your tenant"}
```

---

## Issue B — Extension 1020 USER_BUSY

Extension **1020** returns immediate **`USER_BUSY`** (CDR / hangup cause) even when API lists **0 active calls**. SIP kick via password rotate does not clear it. Likely zombie channels on FreeSWITCH.

| call_uuid | hangup_cause | extension |
|-----------|--------------|-----------|
| `6e969354-9306-4c62-a55c-32620e95c470` | USER_BUSY | 1020 |

Older stuck call UUIDs (API hangup returned 404):

- `19c779cb-76de-467a-bc84-2d472549b2d6`
- `66ae754b-d496-4815-90bf-64415187d4d5`

**Extension 1001** does not show USER_BUSY but still hits Issue A (404 after POST).

---

## Issue C — Historical CDR with masked destination

One CDR row showed destination `vv0aou9q` (hopper/masked lead) instead of the dialed PSTN number — from when campaign was not manual. Campaign is now `manual`; this may be unrelated to current 404 issue.

| call_uuid | destination | hangup_cause |
|-----------|-------------|--------------|
| `6e969354-9306-4c62-a55c-32620e95c470` | `vv0aou9q` | USER_BUSY |

---

## Expected call flow (CRM)

Agent-first (current):

1. CRM `POST /click-to-call` with `extension` + `destination`
2. Morpheus rings **browser SIP extension** (WebRTC)
3. Agent auto-answers in CRM
4. Morpheus dials **PSTN destination** via contactivity trunk
5. `GET /calls/{uuid}` should show `live: true` while ringing

CRM now uses **`click-to-call`** so requests appear in Morpheus click-to-call logs.

---

## What we need from Morpheus

1. **Why does `POST /click-to-call` return `call_uuid` but FreeSWITCH never creates the call?**  
   Please check FS logs for UUID `d6abc51f-d741-4fea-aded-8008e2a771e2` (and any above).

2. **Clear zombie channels on extension 1020** so it stops returning USER_BUSY.

3. **Confirm trunk / dialplan** for campaign `6c753496-2efd-4783-aa85-eb6ec73bc512` → PSTN `12722001232` via contactivity.

4. **Confirm API key** has `calls:originate` and tenant scope matches `apexone`.

---

## CRM-side mitigations (already deployed)

- Switched to `MORPHEUS_ORIGINATE_METHOD=click-to-call`
- Validates call exists after originate (~3s); shows error if PBX never creates call
- Manual campaign patch (`dial_mode: manual`, ring_timeout 90, drop_timeout 45)
- Hangup clears both SIP + Morpheus legs; hold/transfer wired in UI

---

## Contact

Please reply when originate/click-to-call is creating live calls again. We can re-run verification immediately:

```bash
python deploy/hit_click_to_call.py 1001 +12722001232
python deploy/raw_morpheus_ring.py
```

Expected after fix: `GET /calls/{uuid}` returns call object with `live: true` for 30–90s while ringing.
