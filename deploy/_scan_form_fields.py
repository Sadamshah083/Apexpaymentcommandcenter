import re
from pathlib import Path

# Multiline-aware scan of blade views
root = Path("resources/views")
pat = re.compile(r"<(input|select|textarea)\b((?:(?!>)[\s\S])*?)>", re.I)
missing = []

for path in root.rglob("*.blade.php"):
    text = path.read_text(encoding="utf-8", errors="replace")
    for m in pat.finditer(text):
        tag = m.group(1).lower()
        attrs = m.group(2)
        start = text[: m.start()].count("\n") + 1
        if re.search(r"\btype\s*=\s*[\"']hidden[\"']", attrs, re.I):
            continue
        if re.search(r"\btype\s*=\s*[\"'](?:submit|button|image|reset)[\"']", attrs, re.I):
            continue
        has_id = re.search(r"\bid\s*=", attrs)
        has_name = re.search(r"\bname\s*=", attrs)
        if not has_id and not has_name:
            snippet = re.sub(r"\s+", " ", m.group(0))[:220]
            missing.append((path.as_posix(), start, snippet))

print(f"blade_count={len(missing)}")
for item in missing:
    print(f"{item[0]}:{item[1]}: {item[2]}")

# JS createElement / innerHTML inputs
js_root = Path("resources/js")
js_missing = []
for path in js_root.rglob("*.js"):
    text = path.read_text(encoding="utf-8", errors="replace")
    for m in pat.finditer(text):
        attrs = m.group(2)
        start = text[: m.start()].count("\n") + 1
        if re.search(r"\btype\s*=\s*[\"'](?:hidden|submit|button|image|reset)[\"']", attrs, re.I):
            continue
        has_id = re.search(r"\bid\s*=", attrs)
        has_name = re.search(r"\bname\s*=", attrs)
        if not has_id and not has_name:
            snippet = re.sub(r"\s+", " ", m.group(0))[:220]
            js_missing.append((path.as_posix(), start, snippet))

print(f"js_html_count={len(js_missing)}")
for item in js_missing[:60]:
    print(f"{item[0]}:{item[1]}: {item[2]}")
