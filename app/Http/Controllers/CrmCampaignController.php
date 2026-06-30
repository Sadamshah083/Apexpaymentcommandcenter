<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCrmCampaignJob;
use App\Jobs\RunCrmLeadResearchJob;
use App\Models\CrmCampaign;
use App\Models\CrmLead;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrmCampaignController extends Controller
{
    public function index()
    {
        $campaigns = CrmCampaign::latest()->paginate(12);

        $stats = [
            'total_campaigns' => CrmCampaign::count(),
            'total_leads' => CrmLead::count(),
            'completed_leads' => CrmLead::where('status', 'completed')->count(),
            'processing_campaigns' => CrmCampaign::where('status', 'processing')->count(),
        ];

        return view('crm.index', compact('campaigns', 'stats'));
    }

    public function create()
    {
        return view('crm.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file|mimes:csv,txt|max:'.config('crm.max_upload_kb', 51200),
        ]);

        $campaign = CrmCampaign::create([
            'name' => $request->name,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'status' => 'pending',
        ]);

        $path = $request->file('file')->store('crm-uploads', 'local');

        ProcessCrmCampaignJob::dispatchSync($campaign->id, $path);

        $campaign->refresh();

        if ($campaign->status === 'failed') {
            return redirect()->route('admin.crm.show', $campaign)
                ->with('error', $campaign->import_error ?? 'Import failed. Check your CSV has a business name column.');
        }

        return redirect()->route('admin.crm.show', $campaign)
            ->with('success', 'CSV imported. Research runs in the background — keep the queue worker running.');
    }

    public function reupload(Request $request, CrmCampaign $crm)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:'.config('crm.max_upload_kb', 51200),
        ]);

        $crm->update([
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'status' => 'pending',
            'import_error' => null,
        ]);

        $path = $request->file('file')->store('crm-uploads', 'local');

        ProcessCrmCampaignJob::dispatchSync($crm->id, $path);

        $crm->refresh();

        if ($crm->status === 'failed') {
            return redirect()->route('admin.crm.show', $crm)
                ->with('error', $crm->import_error ?? 'Import failed. Check your CSV has a business name column.');
        }

        return redirect()->route('admin.crm.show', $crm)
            ->with('success', 'CSV re-imported. Row data updated; completed research kept unless business details changed.');
    }

    public function show(Request $request, CrmCampaign $crm)
    {
        $query = $crm->leads()->orderBy('row_number');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('payment_processor', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->get('enriched') === 'yes') {
            $query->enriched();
        } elseif ($request->get('enriched') === 'no') {
            $query->notEnriched();
        }

        $leads = $query->paginate(25)->withQueryString();
        $crm->refreshCounts();
        $enrichedCount = $crm->leads()->enriched()->count();

        return view('crm.show', compact('crm', 'leads', 'enrichedCount'));
    }

    public function progress(CrmCampaign $crm)
    {
        $crm->refreshCounts();

        return response()->json([
            'status' => $crm->status,
            'complete' => $crm->isComplete(),
            'total' => $crm->total_leads,
            'pending' => $crm->pending_count,
            'processing' => $crm->processing_count,
            'completed' => $crm->completed_count,
            'failed' => $crm->failed_count,
            'percent' => $crm->progressPercent(),
        ]);
    }

    public function leadShow(CrmCampaign $crm, CrmLead $lead)
    {
        abort_unless($lead->campaign_id === $crm->id, 404);

        return view('crm.lead-show', compact('crm', 'lead'));
    }

    public function leadStatus(CrmCampaign $crm, CrmLead $lead)
    {
        abort_unless($lead->campaign_id === $crm->id, 404);

        return response()->json([
            'status' => $lead->status,
            'complete' => $lead->isComplete(),
            'owner_name' => $lead->owner_name,
            'payment_processor' => $lead->payment_processor,
            'confidence' => $lead->confidence,
        ]);
    }

    public function retryLead(CrmCampaign $crm, CrmLead $lead)
    {
        abort_unless($lead->campaign_id === $crm->id, 404);

        $lead->update([
            'status' => 'pending',
            'error_message' => null,
        ]);
        $crm->refreshCounts();

        RunCrmLeadResearchJob::dispatchSync($lead->id);

        return redirect()->route('admin.crm.leads.show', [$crm, $lead->fresh()])
            ->with('success', 'Research completed.');
    }

    public function retryFailed(CrmCampaign $crm)
    {
        $ids = $crm->leads()
            ->whereIn('status', ['failed', 'processing'])
            ->pluck('id');

        CrmLead::whereIn('id', $ids)->update(['status' => 'pending', 'error_message' => null]);

        $delayMs = config('crm.dispatch_delay_ms', 100);
        foreach ($ids->values() as $index => $id) {
            $dispatch = RunCrmLeadResearchJob::dispatch($id);
            if ($delayMs > 0 && $index > 0) {
                $dispatch->delay(now()->addMilliseconds($index * $delayMs));
            }
        }

        $crm->refreshCounts();

        return redirect()->route('admin.crm.show', $crm)
            ->with('success', count($ids).' leads queued for re-research.');
    }

    public function export(CrmCampaign $crm): StreamedResponse
    {
        $filename = 'crm-'.str($crm->name)->slug().'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($crm) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Row', 'Status', 'Business Name', 'City', 'State', 'Input Address', 'Website',
                'Owner Name', 'Direct Phone', 'Direct Email', 'Payment Processor',
                'POS / Field Software', 'Primary Service', 'Operating Hours', 'Confidence', 'Summary',
            ]);

            $crm->leads()->orderBy('row_number')->chunk(100, function ($leads) use ($handle) {
                foreach ($leads as $lead) {
                    fputcsv($handle, [
                        $lead->row_number,
                        $lead->status,
                        $lead->business_name,
                        $lead->city,
                        $lead->state,
                        $lead->fullAddress(),
                        $lead->website,
                        $lead->owner_name,
                        $lead->displayPhone(),
                        $lead->displayEmail(),
                        $lead->payment_processor,
                        $lead->field_service_software ?? $lead->pos_system,
                        $lead->primary_service,
                        $lead->operating_hours,
                        $lead->confidence,
                        $lead->summary,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function destroy(CrmCampaign $crm)
    {
        $crm->delete();

        return redirect()->route('admin.crm.index')->with('success', 'Campaign deleted.');
    }
}
