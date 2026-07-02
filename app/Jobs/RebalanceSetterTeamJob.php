<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Pipeline\SetterDistributionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebalanceSetterTeamJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $workspaceId,
    ) {}

    public function handle(SetterDistributionService $distribution): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        if (! $distribution->needsRebalance($workspace)) {
            return;
        }

        $actor = $workspace->users()
            ->wherePivot('role', 'appointment_setter_team_lead')
            ->wherePivot('status', 'active')
            ->first();

        if (! $actor) {
            $actor = User::find($workspace->admin_id);
        }

        if (! $actor) {
            return;
        }

        $distribution->rebalanceWorkspace($workspace, $actor);
    }
}
