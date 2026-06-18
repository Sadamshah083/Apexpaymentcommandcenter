<?php

namespace App\Http\Controllers;

use App\Models\EmailList;
use App\Models\ReputationLog;
use Illuminate\Http\Request;

class ReputationController extends Controller
{
    public function index()
    {
        $logs = ReputationLog::latest('recorded_at')->paginate(20);

        $hygiene = [
            'total_lists' => EmailList::count(),
            'avg_invalid_rate' => $this->averageInvalidRate(),
            'lists_needing_cleanup' => EmailList::whereRaw('invalid_count > total_count * 0.05')->where('total_count', '>', 0)->count(),
        ];

        $warmupSchedule = $this->generateWarmupSchedule();

        return view('reputation.index', compact('logs', 'hygiene', 'warmupSchedule'));
    }

    public function storeLog(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
            'metric' => 'required|string|max:100',
            'value' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'recorded_at' => 'required|date',
        ]);

        ReputationLog::create($request->only(['domain', 'metric', 'value', 'notes', 'recorded_at']));

        return redirect()->route('reputation.index')->with('success', 'Reputation log saved.');
    }

    public function warmupCalculator(Request $request)
    {
        $request->validate([
            'target_daily' => 'required|integer|min:100|max:100000',
            'weeks' => 'required|integer|min:4|max:12',
        ]);

        $schedule = $this->generateWarmupSchedule(
            $request->integer('target_daily'),
            $request->integer('weeks')
        );

        return response()->json(['schedule' => $schedule]);
    }

    protected function averageInvalidRate(): float
    {
        $lists = EmailList::where('total_count', '>', 0)->get();

        if ($lists->isEmpty()) {
            return 0;
        }

        $rates = $lists->map(fn ($l) => ($l->invalid_count / $l->total_count) * 100);

        return round($rates->avg(), 2);
    }

    protected function generateWarmupSchedule(int $targetDaily = 50000, int $weeks = 6): array
    {
        $schedule = [];
        $day = 1;

        $weeklyTargets = [];
        for ($w = 1; $w <= $weeks; $w++) {
            $weeklyTargets[$w] = (int) ($targetDaily * ($w / $weeks) ** 1.8);
        }

        for ($week = 1; $week <= $weeks; $week++) {
            $weekTarget = $weeklyTargets[$week];
            $startDay = $weeklyTargets[$week - 1] ?? 50;
            $daysInWeek = 7;

            for ($d = 0; $d < $daysInWeek; $d++) {
                $dailyVolume = (int) ($startDay + (($weekTarget - $startDay) / $daysInWeek) * ($d + 1));

                $schedule[] = [
                    'day' => $day,
                    'week' => $week,
                    'daily_volume' => max(50, $dailyVolume),
                    'focus' => $week <= 2 ? 'Most engaged subscribers only' : ($week <= 4 ? 'Monitor spam rate in Postmaster Tools' : 'Gradual scale to full list'),
                    'check' => $week >= 2 ? 'Keep spam rate below 0.1%' : 'Verify SPF, DKIM, DMARC first',
                ];
                $day++;
            }
        }

        return $schedule;
    }
}
