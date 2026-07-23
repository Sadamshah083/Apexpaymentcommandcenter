<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
echo str_contains($src, 'teamLeadUserId') ? "create_tl_param=yes\n" : "create_tl_param=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/add-member-modal.blade.php'), 'Select campaign') ? "add_campaign=yes\n" : "add_campaign=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/add-member-modal.blade.php'), 'Select team lead') ? "add_tl=yes\n" : "add_tl=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/member-row.blade.php'), 'Under ') ? "under_line=yes\n" : "under_line=no\n";

$elijah = App\Models\User::where('name', 'ElijahMorgan')->first();
$damon = App\Models\User::where('name', 'damonpeterson')->first();
$ws = App\Models\Workspace::find(2);
$pivot = $ws->users()->where('user_id', $elijah->id)->first()->pivot;
echo "elijah_lead=".$pivot->team_lead_user_id." damon=".$damon->id." match=".((int)$pivot->team_lead_user_id === (int)$damon->id ? 'yes' : 'no')."\n";
echo "OK\n";
