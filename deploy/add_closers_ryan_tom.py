#!/usr/bin/env python3
"""Add Ryan + Tomhanderson as B2B Closers under Damon Peterson. Safe: no queue/session resets."""
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

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Soft add only — do not clear caches, sessions, queues, or restart workers.
$ws = Workspace::where('name', 'ApexPayments')->first() ?: Workspace::find(2);
if (! $ws) {
    echo "ERROR workspace missing\n";
    exit(1);
}

$damon = User::query()
    ->where(function ($q) {
        $q->whereRaw('LOWER(email)=?', ['damonpeterson@apexonepayments.com'])
            ->orWhereRaw('LOWER(name)=?', ['damonpeterson']);
    })
    ->first();

if (! $damon) {
    echo "ERROR damonpeterson missing\n";
    exit(1);
}

$damonPivot = DB::table('workspace_user')
    ->where('workspace_id', $ws->id)
    ->where('user_id', $damon->id)
    ->first();

echo "workspace={$ws->id} {$ws->name}\n";
echo "damon id={$damon->id} email={$damon->email} role=".($damonPivot->role ?? '?')."\n";

if (($damonPivot->role ?? '') !== 'closers_team_lead') {
    // Ensure Damon is closer TL so agents can attach under him (no other side effects).
    DB::table('workspace_user')
        ->where('workspace_id', $ws->id)
        ->where('user_id', $damon->id)
        ->update([
            'role' => 'closers_team_lead',
            'team_lead_user_id' => null,
            'status' => 'active',
            'updated_at' => now(),
        ]);
    echo "damon promoted to closers_team_lead\n";
}

$actor = User::platformSuperAdmin() ?: User::query()->where('email', 'like', '%admin%')->first();
if (! $actor) {
    echo "ERROR no admin actor\n";
    exit(1);
}

$svc = app(WorkspaceMemberService::class);

$agents = [
    [
        'username' => 'Ryan',
        'email' => 'ryan@apexonepayments.com',
        'password' => '123456',
    ],
    [
        'username' => 'Tomhanderson',
        'email' => 'tomhanderson@apexonepayments.com',
        'password' => '123456',
    ],
];

foreach ($agents as $agent) {
    $existing = User::query()
        ->whereRaw('LOWER(email)=?', [strtolower($agent['email'])])
        ->orWhereRaw('LOWER(name)=?', [strtolower($agent['username'])])
        ->first();

    if ($existing) {
        // Update password + ensure membership under Damon as closer — do not delete.
        $existing->forceFill([
            'password' => $agent['password'],
            'password_hint' => $agent['password'],
            'current_workspace_id' => $ws->id,
        ])->save();

        $inWs = DB::table('workspace_user')
            ->where('workspace_id', $ws->id)
            ->where('user_id', $existing->id)
            ->exists();

        if ($inWs) {
            DB::table('workspace_user')
                ->where('workspace_id', $ws->id)
                ->where('user_id', $existing->id)
                ->update([
                    'role' => 'closer',
                    'team_lead_user_id' => $damon->id,
                    'status' => 'active',
                    'joined_at' => DB::raw('COALESCE(joined_at, NOW())'),
                    'updated_at' => now(),
                ]);
        } else {
            $ws->users()->attach($existing->id, [
                'role' => 'closer',
                'status' => 'active',
                'joined_at' => now(),
                'team_lead_user_id' => $damon->id,
                'campaign_id' => $damonPivot->campaign_id ?? null,
            ]);
        }

        echo "UPDATED id={$existing->id} {$existing->name} <{$existing->email}> closer under damon={$damon->id}\n";
        continue;
    }

    try {
        $user = $svc->createAgent(
            $ws,
            $actor,
            $agent['username'],
            $agent['password'],
            'closer',
            null,
            $damon->id,
            null,
            $agent['email'],
        );
        echo "CREATED id={$user->id} {$user->name} <{$user->email}> closer under damon={$damon->id}\n";
    } catch (\Throwable $e) {
        echo "ERROR {$agent['email']}: ".$e->getMessage()."\n";
    }
}

echo "\n=== verify ===\n";
foreach (['ryan@apexonepayments.com', 'tomhanderson@apexonepayments.com'] as $email) {
    $u = User::whereRaw('LOWER(email)=?', [$email])->first();
    if (! $u) {
        echo "MISSING {$email}\n";
        continue;
    }
    $p = DB::table('workspace_user')->where('workspace_id', $ws->id)->where('user_id', $u->id)->first();
    $okPass = Hash::check('123456', $u->password) ? 'pass_ok' : 'pass_BAD';
    echo "{$u->name} <{$u->email}> role=".($p->role ?? '?')." lead=".($p->team_lead_user_id ?? 'null')." status=".($p->status ?? '?')." {$okPass}\n";
}

echo "sessions_untouched=1 queues_untouched=1\n";
"""

(ROOT / "deploy/_add_closers_ryan_tom.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_add_closers_ryan_tom.php", "scripts/_add_closers_ryan_tom.php")], app_root=REMOTE_APP)
    # Run as www-data; no artisan optimize/queue restart
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_add_closers_ryan_tom.php", check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_add_closers_ryan_tom.php", check=False)
finally:
    ssh.close()
    p = ROOT / "deploy/_add_closers_ryan_tom.php"
    if p.exists():
        p.unlink()
