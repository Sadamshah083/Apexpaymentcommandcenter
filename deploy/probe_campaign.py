#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$cid = config('integrations.morpheus.default_campaign_id');
$h = fn($m,$p,$b=[]) => Illuminate\Support\Facades\Http::withHeaders(['X-API-Key'=>$key])->timeout(15)->$m("https://{$host}/api/v1/call-control{$p}", $b);
echo "ENV dial dest: ".config('integrations.communications.default_dial_destination')."\n";
echo "ENV outbound did: ".config('integrations.communications.default_outbound_did')."\n";
echo "\nCAMPAIGN {$cid}:\n";
echo $h('get',"/campaigns/{$cid}")->body()."\n";
echo "\nEXTENSION 1020:\n";
$exts = $h('get','/extensions',['limit'=>50])->json('extensions') ?? [];
foreach ($exts as $e) {
  if (($e['extension_num'] ?? '') == '1020') echo json_encode($e, JSON_PRETTY_PRINT)."\n";
}
echo "\nTRUNKS probe:\n";
foreach (['/trunks','/sip/trunks','/settings/trunks'] as $p) {
  $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key'=>$key])->get("https://{$host}/api/v1{$p}");
  echo "GET {$p} => ".$r->status()." ".substr($r->body(),0,200)."\n";
}
"""

ssh = connect()
tmp = "/tmp/probe-campaign.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
