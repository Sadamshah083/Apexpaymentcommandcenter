#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run
ssh = connect()
try:
    out = sudo_run(ssh, f"grep -n 'run_enrichment_on_import\\|@checked\\|processing_mode' {REMOTE_APP}/resources/views/workflows/show.blade.php | head -n 40", check=False)
    print(out.encode("ascii","replace").decode("ascii"))
    out2 = sudo_run(ssh, f"grep -n 'runEnrichment\\|import_only\\|dispatchPendingLeadJobs\\|runsEnrichmentOnImport' {REMOTE_APP}/app/Services/Workflow/WorkflowService.php {REMOTE_APP}/app/Jobs/ProcessWorkflowJob.php | head -n 40", check=False)
    print(out2.encode("ascii","replace").decode("ascii"))
finally:
    ssh.close()
