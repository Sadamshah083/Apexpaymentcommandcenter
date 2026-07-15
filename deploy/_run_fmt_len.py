#!/usr/bin/env python3
import os
from pathlib import Path
import paramiko

HOST = "203.215.160.44"
USER = "issac"
ROOT = "/var/www/apexone"


def password():
    p = Path(__file__).with_name(".deploy_password")
    if p.exists():
        return p.read_text(encoding="utf-8").strip()
    return os.environ.get("DEPLOY_PASSWORD", "")


def main():
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect(HOST, username=USER, password=password(), timeout=25)
    sftp = c.open_sftp()
    for name in ("_fmt_len2.php", "_fmt_len3.php"):
        local = Path(__file__).with_name(name)
        sftp.put(str(local), f"/tmp/{name}")
    sftp.close()
    for name in ("_fmt_len2.php", "_fmt_len3.php"):
        print("====", name)
        stdin, stdout, stderr = c.exec_command(
            f"cd {ROOT} && php /tmp/{name}", timeout=120
        )
        print(stdout.read().decode("utf-8", errors="replace")[-8000:])
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err[-1500:])
    c.close()


if __name__ == "__main__":
    main()
