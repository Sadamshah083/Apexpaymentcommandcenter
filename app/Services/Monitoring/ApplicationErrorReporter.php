<?php

namespace App\Services\Monitoring;

use App\Models\ApplicationError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApplicationErrorReporter
{
    public function report(Throwable $e, ?Request $request = null): void
    {
        try {
            if (! Schema::hasTable('application_errors')) {
                return;
            }

            $request = $request ?: request();
            $message = mb_substr($e->getMessage() ?: class_basename($e), 0, 5000);
            $class = $e::class;
            $file = $e->getFile();
            $line = $e->getLine();
            $url = $request?->fullUrl();

            $existing = ApplicationError::query()
                ->where('exception_class', $class)
                ->where('message', $message)
                ->where('file', $file)
                ->where('line', $line)
                ->where('created_at', '>=', now()->subDay())
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $existing->forceFill([
                    'occurrences' => (int) $existing->occurrences + 1,
                    'last_seen_at' => now(),
                    'url' => $url ?: $existing->url,
                    'method' => $request?->method() ?: $existing->method,
                    'user_id' => Auth::id() ?: $existing->user_id,
                    'ip' => $request?->ip() ?: $existing->ip,
                ])->save();

                return;
            }

            ApplicationError::query()->create([
                'level' => 'error',
                'exception_class' => $class,
                'message' => $message,
                'trace' => mb_substr($e->getTraceAsString(), 0, 20000),
                'file' => $file,
                'line' => $line,
                'url' => $url ? mb_substr($url, 0, 2000) : null,
                'method' => $request?->method(),
                'user_id' => Auth::id(),
                'ip' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
                'occurrences' => 1,
                'last_seen_at' => now(),
            ]);
        } catch (Throwable $reportError) {
            Log::warning('application_error.report_failed', [
                'error' => $reportError->getMessage(),
            ]);
        }
    }
}
