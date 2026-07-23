#!/usr/bin/env python3
"""Quick verify 50MB upload limits + assigned-agent summaries on NEW."""
import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PASS = "balitech1"

cmds = [
    "php -r \"echo 'cli upload='.ini_get('upload_max_filesize').' post='.ini_get('post_max_size').PHP_EOL;\"",
    "grep -n client_max_body_size /etc/nginx/sites-enabled/* 2>/dev/null | head -5",
    r"""cd /var/www/apexone && php artisan tinker --execute="$w=App\Models\Workflow::query()->whereHas('leads', fn($q)=>$q->whereNotNull('assigned_to'))->latest('id')->first(); if(!$w){echo 'no assigned'; exit;} echo 'wf='.$w->id.PHP_EOL; $rows=App\Models\WorkflowLead::query()->where('workflow_id',$w->id)->whereNotNull('assigned_to')->selectRaw('assigned_to, count(*) as c')->groupBy('assigned_to')->orderByDesc('c')->limit(5)->get(); foreach($rows as $r){ $u=App\Models\User::find($r->assigned_to); echo ($u?$u->name:'?').':'.$r->c.PHP_EOL; }" """,
    "grep -n 'max:51200\\|attachAssignedAgentSummaries\\|patchAssignedAgentsCell\\|data-turbo' /var/www/apexone/app/Http/Controllers/WorkflowController.php /var/www/apexone/app/Services/Workflow/WorkflowDashboardService.php /var/www/apexone/resources/js/workspace-sync.js /var/www/apexone/resources/views/workflows/create.blade.php | head -30",
]

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, username=USER, password=PASS, timeout=20)
for cmd in cmds:
    print(">>>", cmd[:80], "...")
    stdin, stdout, stderr = c.exec_command(cmd, timeout=90)
    out = stdout.read().decode()
    err = stderr.read().decode()
    if out:
        print(out)
    if err:
        print("ERR:", err[:500])
    print("---")
c.close()
print("PROBE_OK")
