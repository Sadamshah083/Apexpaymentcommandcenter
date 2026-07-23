# Server roles (remember)

| Role | IP | Domain | Purpose |
|------|-----|--------|---------|
| **NEW / Production** | `203.215.161.236` | `https://crm.apexonepayments.com` | Live traffic — **domain stays here** |
| **OLD / Testing** | `203.215.160.44` | **No production domain** — use `http://203.215.160.44` | Testing / QA only |

## Rules (always remember)

1. **DNS / domain never moves to the old server** for production.
2. Old server `APP_URL` must be the **IP** (`http://203.215.160.44`), not `crm.apexonepayments.com`.
3. **NEW = production only.** Domain `crm.apexonepayments.com` stays on NEW.
4. **OLD = testing only.** Use it to verify changes before / beside production.
5. Deploy hotfixes for **production** target NEW (`203.215.161.236` / `ateg`).
6. Experiments / QA on OLD (`203.215.160.44` / `issac`).

## Sync commands

Full clone from production (code + DB) when NEW SSH is reachable:

```bash
python deploy/sync_new_to_old_testing.py
```

Code from this laptop → OLD (when NEW SSH is down):

```bash
python deploy/sync_local_to_old_testing.py
```

## Testing URLs (old)

- Health: `http://203.215.160.44/up`
- Admin: `http://203.215.160.44/admin/login`
- Portal: `http://203.215.160.44/portal/login`

## Production URLs (new)

- `https://crm.apexonepayments.com`
