#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# --- CallMonitoringController ---
mon = ROOT / "app/Http/Controllers/CallMonitoringController.php"
text = mon.read_text(encoding="utf-8")
text2 = text.replace(
    """            $idleTicks = 0;
            // Absolute lifetime — never hold a php-fpm worker forever (was starving the pool → site-wide 504s).
            $maxIdle = 120;
            $maxIdle = 45; // ~45s wall (250ms sleep); client reconnects via WS / short poll
            $startedAt = time();
            $maxSeconds = 45;""",
    """            $idleTicks = 0;
            // Absolute lifetime — never hold a php-fpm worker forever (was starving the pool → site-wide 504s).
            $maxIdle = 45;
            $startedAt = time();
            $maxSeconds = 45;""",
)
if text2 == text:
    # maybe only first patch applied partially
    if "$maxIdle = 120;" in text and "$maxIdle = 45;" in text:
        text2 = text.replace("$maxIdle = 120;\n            $maxIdle = 45; // ~45s wall (250ms sleep); client reconnects via WS / short poll", "$maxIdle = 45;")
mon.write_text(text2, encoding="utf-8")
print("CallMonitoringController:", "ok" if "$maxSeconds = 45" in text2 else "FAIL")

# --- MorpheusHubController ---
mph = ROOT / "app/Http/Controllers/MorpheusHubController.php"
text = mph.read_text(encoding="utf-8")
needle = "$maxIdle = 1800;"
if needle not in text:
    print("MorpheusHubController: maxIdle=1800 not found")
else:
    text = text.replace(needle, "$maxIdle = 450;\n            $startedAt = time();\n            $maxSeconds = 45;", 1)
    old_while = "while (! connection_aborted() && $idleTicks < $maxIdle) {"
    new_while = "while (! connection_aborted() && $idleTicks < $maxIdle && (time() - $startedAt) < $maxSeconds) {"
    # Only replace the streamCallEvents while — first occurrence after maxIdle patch is the stream one
    idx = text.find("$maxSeconds = 45;")
    if idx != -1:
        part = text[idx:]
        if old_while in part:
            text = text[:idx] + part.replace(old_while, new_while, 1)
    mph.write_text(text, encoding="utf-8")
    print("MorpheusHubController: ok", "$maxSeconds = 45" in text, new_while in text)
