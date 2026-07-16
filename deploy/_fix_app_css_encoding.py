from pathlib import Path

p = Path("resources/css/app.css")
raw = p.read_bytes()
try:
    raw.decode("utf-8")
    print("already valid utf-8")
except UnicodeDecodeError as e:
    print("invalid utf-8:", e)

# Decode leniently, remove replacement chars and Windows-1252 junk from comments
text = raw.decode("cp1252").encode("utf-8").decode("utf-8")

# Replace em-dash comments with ascii
text = text.replace("—", "-")
text = text.replace("–", "-")
text = text.replace("\u00c2", "")  # leftover from bad conversions

# Normalize the two polish comment headers
text = text.replace(
    "/* -- Campaigns overview table (workflows / command center) -- */",
    "/* Campaigns overview table (workflows / command center) */",
)
text = text.replace(
    "/* -- Global pagination polish -- */",
    "/* Global pagination polish */",
)
text = text.replace(
    "/* -- User Management table / modal polish -- */",
    "/* User Management table / modal polish */",
)

# Also catch corrupt sequences that look like curly dashes in comments
import re
text = re.sub(r"/\*[^\n*]{0,5}Campaigns overview", "/* Campaigns overview", text)
text = re.sub(r"/\*[^\n*]{0,5}Global pagination", "/* Global pagination", text)
text = re.sub(r"/\*[^\n*]{0,5}User Management", "/* User Management", text)

p.write_bytes(text.encode("utf-8"))
print("rewrote", p, "bytes", p.stat().st_size)
p.read_bytes().decode("utf-8")
print("utf-8 ok")
