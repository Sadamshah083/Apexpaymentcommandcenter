<?php

namespace App\Services\Pipeline;

use App\Models\LeadList;
use App\Models\LeadTag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class LeadSegmentationService
{
    /**
     * @param  array<int, string>  $tagNames
     * @return array{list: LeadList, tag_ids: array<int, int>}
     */
    public function prepareImportSegmentation(
        Workspace $workspace,
        User $actor,
        string $listName,
        array $tagNames = [],
        array $existingTagIds = [],
    ): array {
        $list = LeadList::create([
            'workspace_id' => $workspace->id,
            'name' => $listName,
            'description' => 'Imported contact list',
            'created_by' => $actor->id,
        ]);

        $tagIds = collect($existingTagIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $tag = LeadTag::firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $name],
                ['color' => $this->colorForTag($name)]
            );

            $tagIds[] = $tag->id;
        }

        return [
            'list' => $list,
            'tag_ids' => array_values(array_unique($tagIds)),
        ];
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    public function attachTagsToLeads(array $leadIds, array $tagIds): void
    {
        if ($leadIds === [] || $tagIds === []) {
            return;
        }

        $rows = [];
        foreach ($leadIds as $leadId) {
            foreach ($tagIds as $tagId) {
                $rows[] = [
                    'workflow_lead_id' => $leadId,
                    'lead_tag_id' => $tagId,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            WorkflowLead::query()->getConnection()->table('lead_tag_workflow_lead')->insertOrIgnore($chunk);
        }
    }

    public function syncWorkflowLeadTags(WorkflowLead $lead, array $tagIds): void
    {
        $lead->tags()->sync(array_values(array_unique(array_map('intval', $tagIds))));
    }

    /**
     * @return Collection<int, LeadTag>
     */
    public function workspaceTags(Workspace $workspace): Collection
    {
        return LeadTag::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get();
    }

    protected function colorForTag(string $name): string
    {
        $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6'];

        return $palette[crc32(strtolower($name)) % count($palette)];
    }
}
