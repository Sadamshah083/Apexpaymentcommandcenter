#!/usr/bin/env python3
from pathlib import Path

p = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter\app\Http\Controllers\MorpheusHubController.php")
text = p.read_text(encoding="utf-8")

needle = "            $maxTicks = 1800;\n"
if needle not in text:
    raise SystemExit("needle missing")

replacement = (
    "            // Hard cap — SSE must not occupy php-fpm for the life of a call (WS is primary).\n"
    "            $maxTicks = 450;\n"
    "            $startedAt = time();\n"
    "            $maxSeconds = 45;\n"
)
text = text.replace(needle, replacement, 1)

old_w = "while (! connection_aborted() && $idleTicks < $maxTicks) {"
new_w = "while (! connection_aborted() && $idleTicks < $maxTicks && (time() - $startedAt) < $maxSeconds) {"
i = text.find("public function streamCallEvents")
j = text.find(old_w, i)
if j < 0:
    raise SystemExit("while missing")
text = text[:j] + new_w + text[j + len(old_w) :]
p.write_text(text, encoding="utf-8")
print("OK patched streamCallEvents maxTicks")
