#!/usr/bin/env python3
"""Shared SSH/SFTP helpers for deploy scripts — fewer round-trips, faster hotfixes."""

from __future__ import annotations

import io
import os
import shlex
import tarfile
from pathlib import Path

import paramiko

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
REMOTE_APP = os.environ.get("REMOTE_APP", "/var/www/apexone")


def connect(timeout: int = 30) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=timeout)
    return ssh


def sudo_run(ssh: paramiko.SSHClient, command: str, check: bool = True) -> str:
    """Run one command under sudo (single SSH round-trip)."""
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    _, stdout, stderr = ssh.exec_command(full)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if check and code != 0:
        raise RuntimeError(f"Command failed ({code}):\n{out}\n{err}")
    return out.strip()


def sudo_run_batch(ssh: paramiko.SSHClient, commands: list[str], check: bool = True) -> str:
    """Run multiple commands in one SSH session (one sudo overhead)."""
    script = " && ".join(f"({cmd})" for cmd in commands if cmd)
    return sudo_run(ssh, script, check=check)


def set_env_vars(ssh: paramiko.SSHClient, values: dict[str, str], env_path: str = f"{REMOTE_APP}/.env") -> None:
    """Update many .env keys in a single remote Python process."""
    script = f"""
import pathlib, re
path = pathlib.Path({env_path!r})
text = path.read_text()
updates = {values!r}
for key, val in updates.items():
    pattern = re.compile(r'^' + re.escape(key) + r'=.*$', re.M)
    if re.search(r'[\\s#=\"\\']', val):
        escaped = val.replace('\\\\', '\\\\\\\\').replace('"', '\\\\"')
        line = key + '="' + escaped + '"'
    else:
        line = key + '=' + val
    text = pattern.sub(line, text) if pattern.search(text) else text.rstrip() + '\\n' + line + '\\n'
path.write_text(text)
"""
    sudo_run(ssh, f"python3 -c {shlex.quote(script)}")


def upload_files(ssh: paramiko.SSHClient, pairs: list[tuple[Path, str]], app_root: str = REMOTE_APP) -> None:
    """
    Upload many files as one tarball (one SFTP transfer + one extract).
    pairs: (local_path, remote_relative_path e.g. app/Foo.php)
    """
    if not pairs:
        return

    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for local_path, remote_rel in pairs:
            tar.add(local_path, arcname=remote_rel.replace("\\", "/"))
    buffer.seek(0)

    remote_tar = "/tmp/apexone-hotfix.tar.gz"
    sftp = ssh.open_sftp()
    sftp.putfo(buffer, remote_tar)
    sftp.close()

    extract_cmds = [
        f"tar -xzf {remote_tar} -C {app_root}",
        f"chown -R www-data:www-data " + " ".join(shlex.quote(f"{app_root}/{rel}") for _, rel in pairs),
        f"rm -f {remote_tar}",
    ]
    sudo_run_batch(ssh, extract_cmds)


def restart_queue_workers(ssh: paramiko.SSHClient, app_root: str = REMOTE_APP) -> None:
    """
    Signal workers to restart after the current job — returns immediately.
    Avoid systemctl restart during enrichment; it blocks until long Gemini jobs finish.
    """
    sudo_run(ssh, f"cd {app_root} && sudo -u www-data php artisan queue:restart")
