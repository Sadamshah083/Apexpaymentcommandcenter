from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
out = sudo_run(
    ssh,
    "grep -nE 'skipBusyProbe|hubCallStatus|drop_timeout' "
    + REMOTE_APP
    + "/app/Services/Integrations/ZoomApiService.php | head -40",
)
print("=== ZoomApiService.php ===")
print(out)

out = sudo_run(
    ssh,
    "grep -nE 'MORPHEUS_RING_TIMEOUT|MORPHEUS_BUSY_EXTENSIONS' "
    + REMOTE_APP
    + "/.env || true",
    check=False,
)
print("=== .env RING_TIMEOUT / BUSY ===")
print(out if out else "(no matches — BUSY gone and/or RING_TIMEOUT absent)")
# Also show whether BUSY is present specifically
busy = sudo_run(
    ssh,
    "grep -n 'MORPHEUS_BUSY_EXTENSIONS' " + REMOTE_APP + "/.env || echo BUSY_ABSENT",
    check=False,
)
print("=== BUSY check ===")
print(busy)
ring = sudo_run(
    ssh,
    "grep -n 'MORPHEUS_RING_TIMEOUT' " + REMOTE_APP + "/.env || echo RING_ABSENT",
    check=False,
)
print("=== RING_TIMEOUT check ===")
print(ring)
ssh.close()