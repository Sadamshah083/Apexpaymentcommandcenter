<?php

namespace App\Services\Pipeline;

use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;

class LeadSegmentationService
{
    public function createImportList(Workspace $workspace, User $actor, string $listName): LeadList
    {
        return LeadList::create([
            'workspace_id' => $workspace->id,
            'name' => $listName,
            'description' => 'Import file contact list',
            'created_by' => $actor->id,
        ]);
    }
}
