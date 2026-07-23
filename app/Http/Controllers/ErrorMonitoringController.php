<?php

namespace App\Http\Controllers;

use App\Models\ApplicationError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErrorMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $errors = collect();
        $failedJobs = collect();
        $errorCount = 0;
        $failedCount = 0;

        if (Schema::hasTable('application_errors')) {
            // Drop known-fixed fingerprints so resolved bugs leave Error Monitoring.
            $this->purgeResolvedFingerprints();

            $errorCount = ApplicationError::query()->count();
            $errors = ApplicationError::query()
                ->with('user:id,name,email')
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedCount = DB::table('failed_jobs')->count();
            $failedJobs = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(50)
                ->get(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at']);
        }

        return view('error-monitoring.index', [
            'errors' => $errors,
            'failedJobs' => $failedJobs,
            'errorCount' => $errorCount,
            'failedCount' => $failedCount,
        ]);
    }

    public function destroy(ApplicationError $error): RedirectResponse
    {
        $error->delete();

        return back()->with('success', 'Error removed from monitoring.');
    }

    public function clearResolved(): RedirectResponse
    {
        $removed = $this->purgeResolvedFingerprints();

        return back()->with('success', $removed > 0
            ? "Removed {$removed} resolved error(s) from monitoring."
            : 'No resolved errors to remove.');
    }

    public function clearAll(): RedirectResponse
    {
        if (! Schema::hasTable('application_errors')) {
            return back()->with('success', 'Nothing to clear.');
        }

        $count = ApplicationError::query()->count();
        ApplicationError::query()->delete();

        return back()->with('success', "Cleared {$count} application error(s).");
    }

    protected function purgeResolvedFingerprints(): int
    {
        if (! Schema::hasTable('application_errors')) {
            return 0;
        }

        $patterns = [
            'Undefined constant%App\\\\Services\\\\Workspace\\\\role%',
            'Undefined array key "recording_status"',
            "Unknown column 'event'%",
            'Column not found: 1054 Unknown column \'event\'%',
        ];

        $removed = 0;
        foreach ($patterns as $pattern) {
            $removed += ApplicationError::query()
                ->where('message', 'like', $pattern)
                ->delete();
        }

        // Also drop the fixed WorkspaceMemberService typo by file+line fingerprint.
        $removed += ApplicationError::query()
            ->where('file', 'like', '%WorkspaceMemberService.php')
            ->where('message', 'like', '%Undefined constant%role%')
            ->delete();

        $removed += ApplicationError::query()
            ->where('file', 'like', '%CommunicationsDataService.php')
            ->where('message', 'like', '%recording_status%')
            ->delete();

        return $removed;
    }
}
