#!/usr/bin/env python3
"""Migrate a production SQLite database to MySQL, or exit cleanly if production already uses MySQL."""

from __future__ import annotations

import os
import shlex
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, sudo_run_batch, upload_files
from deploy.configure_production_env import ENV_PATH, parse_env, sudo_cat


def build_target_settings(remote_env: dict[str, str]) -> dict[str, str]:
    db_password = os.environ.get("MYSQL_PASSWORD") or remote_env.get("DB_PASSWORD", "")
    if not db_password:
        raise RuntimeError("Missing MySQL password. Set MYSQL_PASSWORD or populate DB_PASSWORD on the server first.")

    current_database = remote_env.get("DB_DATABASE", "")
    current_username = remote_env.get("DB_USERNAME", "")

    return {
        "host": os.environ.get("MYSQL_HOST", "127.0.0.1"),
        "port": os.environ.get("MYSQL_PORT", "3306"),
        "database": os.environ.get("MYSQL_DATABASE", current_database if current_database and not current_database.endswith(".sqlite") else "apexone"),
        "username": os.environ.get("MYSQL_USERNAME", current_username if current_username and current_username != "root" else "apexone"),
        "password": db_password,
        "socket": os.environ.get("MYSQL_SOCKET", ""),
        "ssl_ca": os.environ.get("MYSQL_SSL_CA", ""),
    }


def quote_php(value: str) -> str:
    return shlex.quote(value)


def sql_string(value: str) -> str:
    return value.replace("'", "''")


def main() -> int:
    ssh = connect()
    remote_text = sudo_cat(ssh, ENV_PATH)
    remote_env = parse_env(remote_text)
    current_driver = remote_env.get("DB_CONNECTION", "sqlite").strip()

    if current_driver == "mysql":
        print("Production already uses MySQL. No database cutover is required.")
        ssh.close()
        return 0

    target = build_target_settings(remote_env)
    sqlite_path = remote_env.get("DB_DATABASE", f"{REMOTE_APP}/database/database.sqlite")
    if not sqlite_path or not sqlite_path.endswith(".sqlite"):
        sqlite_path = f"{REMOTE_APP}/database/database.sqlite"

    print(f"Preparing SQLite to MySQL migration for {sqlite_path} -> {target['database']}")

    upload_files(ssh, [
        (ROOT / "app/Console/Commands/MigrateSqliteToMysqlCommand.php", "app/Console/Commands/MigrateSqliteToMysqlCommand.php"),
    ], app_root=REMOTE_APP)

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
        f"test -f {sqlite_path}",
        f"cp {ENV_PATH} /tmp/apexone.env.pre-mysql.$(date +%Y%m%d%H%M%S)",
        f"cp {sqlite_path} /tmp/apexone.database.sqlite.$(date +%Y%m%d%H%M%S)",
        f"test ! -f {sqlite_path}-wal || cp {sqlite_path}-wal /tmp/apexone.database.sqlite-wal.$(date +%Y%m%d%H%M%S)",
        "systemctl stop apexone-queue",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan down --refresh=15",
    ])

    try:
        if target["host"] in {"127.0.0.1", "localhost"}:
            sql = (
                f"CREATE DATABASE IF NOT EXISTS `{target['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                f"CREATE USER IF NOT EXISTS '{sql_string(target['username'])}'@'localhost' IDENTIFIED BY '{sql_string(target['password'])}'; "
                f"ALTER USER '{sql_string(target['username'])}'@'localhost' IDENTIFIED BY '{sql_string(target['password'])}'; "
                f"GRANT ALL PRIVILEGES ON `{target['database']}`.* TO '{sql_string(target['username'])}'@'localhost'; "
                "FLUSH PRIVILEGES;"
            )
            sudo_run(ssh, f"mysql -e {shlex.quote(sql)}")

        migrate_command = (
            f"cd {REMOTE_APP} && sudo -u www-data php artisan db:migrate-sqlite-to-mysql "
            f"--sqlite-path={quote_php(sqlite_path)} "
            f"--mysql-host={quote_php(target['host'])} "
            f"--mysql-port={quote_php(target['port'])} "
            f"--mysql-database={quote_php(target['database'])} "
            f"--mysql-username={quote_php(target['username'])} "
            f"--mysql-password={quote_php(target['password'])} "
            + (f"--mysql-socket={quote_php(target['socket'])} " if target["socket"] else "")
            + (f"--mysql-ssl-ca={quote_php(target['ssl_ca'])} " if target["ssl_ca"] else "")
            + "--force"
        )
        print(sudo_run(ssh, migrate_command))

        set_env_vars(ssh, {
            "DB_CONNECTION": "mysql",
            "DB_HOST": target["host"],
            "DB_PORT": target["port"],
            "DB_DATABASE": target["database"],
            "DB_USERNAME": target["username"],
            "DB_PASSWORD": target["password"],
        })

        sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            "systemctl restart apexone-queue php8.3-fpm",
            "systemctl reload nginx",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan up",
        ])

        time.sleep(2)
        print(sudo_run(ssh, "curl -fsS http://127.0.0.1/up", check=False))
    except Exception:
        sudo_run_batch(ssh, [
            "systemctl restart apexone-queue php8.3-fpm",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan up",
        ], check=False)
        raise
    finally:
        ssh.close()

    print("Production SQLite to MySQL migration completed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
