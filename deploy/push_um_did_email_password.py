#!/usr/bin/env python3
"""Deploy UM create DID/email/password fixes + imported leads polish + reset passwords to 123456."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/communications/inbox/partials/panels/agents.blade.php",
    "resources/views/communications/inbox/partials/panels/extensions.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/js/workspace-admin.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("Uploading UM + Comm Hub + leads UI fixes...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building + normalizing passwords/emails...")
    out = sudo_run(
        ssh,
        f"""
cd {REMOTE_APP}
if [ ! -x node_modules/.bin/vite ]; then
  npm install --no-fund --no-audit > /tmp/npm-um-fix.log 2>&1
  echo NPM:$?
fi
npm run build > /tmp/vite-um-fix.log 2>&1
echo BUILD:$?
tail -n 20 /tmp/vite-um-fix.log
chown -R www-data:www-data {REMOTE_APP}/public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan tinker --execute="
\\$hash = Illuminate\\\\Support\\\\Facades\\\\Hash::make('123456');
\\$updated = 0;
\\$emailFixed = 0;
foreach (App\\\\Models\\\\User::query()->cursor() as \\$user) {{
    \\$dirty = false;
    if ((string) (\\$user->password_hint ?? '') !== '123456' || ! Illuminate\\\\Support\\\\Facades\\\\Hash::check('123456', (string) \\$user->password)) {{
        \\$user->password = '123456';
        \\$user->password_hint = '123456';
        \\$dirty = true;
        \\$updated++;
    }}
    \\$email = strtolower((string) \\$user->email);
    if (str_ends_with(\\$email, '@apexpayments.com')) {{
        \\$local = strstr(\\$email, '@', true) ?: preg_replace('/\\\\s+/', '', strtolower((string) \\$user->name));
        \\$local = preg_replace('/[^a-z0-9._+-]/', '', (string) \\$local) ?: 'agent'.\\$user->id;
        \\$candidate = \\$local.'@apexonepayments.com';
        if (! App\\\\Models\\\\User::query()->where('email', \\$candidate)->where('id', '!=', \\$user->id)->exists()) {{
            \\$user->email = \\$candidate;
            \\$dirty = true;
            \\$emailFixed++;
        }}
    }}
    if (\\$dirty) {{
        \\$user->save();
    }}
}}
echo \"passwords_set={{$updated}} emails_fixed={{$emailFixed}}\".PHP_EOL;
" 2>/dev/null | tail -n 20
grep -n "availablePhoneLines\\|apexonepayments\\|create-extension\\|Remaining" \\
  resources/views/workflows/partials/add-member-modal.blade.php \\
  resources/views/communications/inbox/partials/panels/agents.blade.php \\
  resources/views/communications/inbox/partials/panels/extensions.blade.php | head -30
""",
        check=False,
    )
    print(out)
    ssh.close()
    print("UM DID/email/password + leads UI deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
