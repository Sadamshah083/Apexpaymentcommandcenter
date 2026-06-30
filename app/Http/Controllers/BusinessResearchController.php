<?php

namespace App\Http\Controllers;

use App\Jobs\RunBusinessResearchJob;
use App\Models\BusinessResearch;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BusinessResearchController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $researches = BusinessResearch::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->paginate(15);

        return view('business-research.index', compact('researches'));
    }

    public function store(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $request->validate([
            'business_name' => 'required|string|max:500',
            'address' => 'nullable|string|max:1000',
            'website' => 'nullable|string|max:500',
        ]);

        $research = BusinessResearch::create([
            'workspace_id' => $workspace->id,
            'user_id' => Auth::id(),
            'business_name' => $request->business_name,
            'address' => $request->address,
            'website' => $request->website ? trim($request->website) : null,
            'status' => 'pending',
        ]);

        RunBusinessResearchJob::dispatchSync($research->id);

        return redirect()->route('admin.business-research.show', $research)
            ->with('success', 'Business research started. Results will appear shortly.');
    }

    public function show(BusinessResearch $businessResearch)
    {
        $this->ensureResearchBelongsToWorkspace($businessResearch);

        return view('business-research.show', ['research' => $businessResearch]);
    }

    public function status(BusinessResearch $businessResearch)
    {
        $this->ensureResearchBelongsToWorkspace($businessResearch);

        return response()->json([
            'status' => $businessResearch->status,
            'complete' => $businessResearch->isComplete(),
            'owner_name' => $businessResearch->owner_name,
        ]);
    }

    public function retry(BusinessResearch $businessResearch)
    {
        $this->ensureResearchBelongsToWorkspace($businessResearch);

        $businessResearch->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        RunBusinessResearchJob::dispatchSync($businessResearch->id);

        return redirect()->route('admin.business-research.show', $businessResearch)
            ->with('success', 'Research restarted.');
    }

    public function destroy(BusinessResearch $businessResearch)
    {
        $this->ensureResearchBelongsToWorkspace($businessResearch);

        $businessResearch->delete();

        return redirect()->route('admin.business-research.index')
            ->with('success', 'Research deleted.');
    }

    protected function ensureResearchBelongsToWorkspace(BusinessResearch $research): void
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        if ($research->workspace_id && $research->workspace_id !== $workspace->id) {
            abort(404);
        }
    }
}
