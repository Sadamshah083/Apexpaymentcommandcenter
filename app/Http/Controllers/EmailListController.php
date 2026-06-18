<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyEmailChunkJob;
use App\Models\EmailList;
use App\Services\EmailList\EmailListService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class EmailListController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected EmailListService $listService,
    ) {}

    protected function resolveList(EmailList $list): EmailList
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureListBelongsToWorkspace($list, $workspace);

        return $list;
    }

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $lists = EmailList::query()
            ->where('workspace_id', $workspace->id)
            ->with('latestBatch', 'user')
            ->latest()
            ->paginate(15);

        return view('lists.index', compact('lists', 'workspace'));
    }

    public function create()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        return view('lists.create', compact('workspace'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file|mimes:csv,txt|max:51200',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        $list = $this->listService->createFromUpload(
            $workspace,
            $user,
            $request->input('name'),
            $request->file('file'),
            $request->input('notes'),
        );

        return redirect()->route($this->routePrefix().'lists.show', $list)
            ->with('success', 'List uploaded. Verification is running in the background.');
    }

    public function show(EmailList $list, Request $request)
    {
        $list = $this->resolveList($list);
        $list->load('latestBatch', 'user');

        $query = $list->contacts()->with('results');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $contacts = $query->orderBy('id')->paginate(50)->withQueryString();

        return view('lists.show', compact('list', 'contacts'));
    }

    public function progress(EmailList $list)
    {
        $list = $this->resolveList($list);
        $list->load('latestBatch');
        $batch = $list->latestBatch;

        return response()->json([
            'status' => $list->status,
            'progress' => $list->progress_percent,
            'processed' => $batch?->processed ?? 0,
            'total' => $batch?->total ?? 0,
            'valid_count' => $list->valid_count,
            'invalid_count' => $list->invalid_count,
            'risky_count' => $list->risky_count,
            'unknown_count' => $list->unknown_count,
            'complete' => $batch?->isComplete() ?? false,
        ]);
    }

    public function pause(EmailList $list)
    {
        $list = $this->resolveList($list);
        $this->listService->pause($list);

        return back()->with('success', 'Verification paused.');
    }

    public function resume(EmailList $list)
    {
        $list = $this->resolveList($list);
        $this->listService->resume($list);

        return back()->with('success', 'Verification resumed.');
    }

    public function export(EmailList $list, Request $request)
    {
        $list = $this->resolveList($list);
        $filter = $request->get('filter', 'valid');

        if ($filter === 'valid' && $list->total_count > 0) {
            $invalidPercent = (($list->invalid_count + $list->risky_count) / max($list->total_count, 1)) * 100;
            if ($invalidPercent > 5 && ! $request->boolean('confirmed')) {
                return redirect()->route($this->routePrefix().'lists.show', $list)
                    ->with('warning', 'Pre-send gate: '.round($invalidPercent, 1).'% of list is invalid/risky. Add ?confirmed=1 to export URL to proceed.');
            }
        }

        $filename = str($list->name)->slug()."-{$filter}-".now()->format('Y-m-d').'.csv';

        $callback = function () use ($list, $filter) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'email',
                'domain',
                'status',
                'score',
                'tags',
                'mx_host',
                'smtp_status',
                'disposable',
                'provider_type',
                'failure_reason',
            ]);

            foreach ($this->listService->exportRows($list, $filter) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function destroy(EmailList $list)
    {
        $list = $this->resolveList($list);
        $this->listService->delete($list);

        return redirect()->route($this->routePrefix().'lists.index')->with('success', 'List deleted.');
    }

    protected function routePrefix(): string
    {
        return request()->is('admin*') ? 'admin.' : 'portal.';
    }
}
