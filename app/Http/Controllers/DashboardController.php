<?php



namespace App\Http\Controllers;



use App\Models\BusinessResearch;

use App\Models\ContentAnalysis;

use App\Models\CrmCampaign;

use App\Models\CrmLead;

use App\Models\DeliverabilityTest;

use App\Models\EmailList;

use Illuminate\Support\Facades\DB;



class DashboardController extends Controller

{

    public function index()
    {
        // Simple auto-login helper if visiting dashboard guest
        if (!auth()->check()) {
            $user = \App\Models\User::first() ?: \App\Models\User::create([
                'name' => 'Demo Administrator',
                'email' => 'admin@example.com',
                'password' => bcrypt('password')
            ]);
            auth()->login($user);
        }

        $user = auth()->user();
        
        // Ensure user has a workspace
        if (!$user->current_workspace_id) {
            $workspace = $user->workspaces()->first();
            if (!$workspace) {
                $workspace = \App\Models\Workspace::create([
                    'name' => $user->name . "'s Workspace",
                    'admin_id' => $user->id
                ]);
                $workspace->users()->attach($user->id, ['role' => 'admin']);
            }
            $user->update(['current_workspace_id' => $workspace->id]);
        }

        $workspace = \App\Models\Workspace::find($user->current_workspace_id);
        $workflowsCount = $workspace ? $workspace->workflows()->count() : 0;
        $leadsCount = $workspace ? \App\Models\WorkflowLead::whereIn('workflow_id', $workspace->workflows()->pluck('id'))->count() : 0;

        $stats = [
            'total_lists' => EmailList::count(),
            'total_emails' => EmailList::sum('total_count'),
            'valid_emails' => EmailList::sum('valid_count'),
            'invalid_emails' => EmailList::sum('invalid_count'),
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'crm_campaigns' => CrmCampaign::count(),
            'crm_leads' => CrmLead::count(),
            'crm_enriched' => CrmLead::where('status', 'completed')->count(),
            'workflows_count' => $workflowsCount,
            'workspace_leads_count' => $leadsCount,
        ];

        $recentLists = EmailList::latest()->take(5)->get();
        $recentDeliverability = DeliverabilityTest::latest()->take(3)->get();
        $recentContent = ContentAnalysis::latest()->take(3)->get();
        $recentCampaigns = CrmCampaign::latest()->take(5)->get();
        $recentWorkflows = $workspace ? $workspace->workflows()->latest()->take(5)->get() : collect();

        return view('dashboard', compact('stats', 'recentLists', 'recentDeliverability', 'recentContent', 'recentCampaigns', 'recentWorkflows', 'workspace'));
    }

}


