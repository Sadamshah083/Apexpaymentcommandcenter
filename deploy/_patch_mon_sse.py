#!/usr/bin/env python3
from pathlib import Path

p = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter\app\Http\Controllers\CallMonitoringController.php")
text = p.read_text(encoding="utf-8")
old = """            $idleTicks = 0;
            // Absolute lifetime — never hold a php-fpm worker forever (was starving the pool → site-wide 504s).
            $maxTicks = 120;
            $maxTicks = 45; // ~45s wall (250ms sleep); client reconnects via WS / short poll
            $startedAt = time();
            $maxSeconds = 45;
            $ticksSinceFull = 0;
            $presence = app(AgentPresenceService::class);
            $workspaceId = (int) ($workspace?->id ?? 0);

            while (! connection_aborted() && $idleTicks < $maxIdle && (time() - $startedAt) < $maxSeconds) {"""
new = """            $idleTicks = 0;
            // Absolute lifetime — never hold a php-fpm worker forever (was starving the pool → site-wide 504s).
            $maxIdle = 180;
            $startedAt = time();
            $maxSeconds = 45;
            $ticksSinceFull = 0;
            $presence = app(AgentPresenceService::class);
            $workspaceId = (int) ($workspace?->id ?? 0);

            while (! connection_aborted() && $idleTicks < $maxIdle && (time() - $startedAt) < $maxSeconds) {"""
if old not in text:
    raise SystemExit("block missing")
p.write_text(text.replace(old, new, 1), encoding="utf-8")
print("OK CallMonitoringController")
