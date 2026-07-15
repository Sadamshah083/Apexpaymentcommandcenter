@extends('layouts.admin')

@section('title', 'Team Performance')

@section('content')
    <div class="app-page space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="app-page-title">Team Performance & Leaderboards</h1>
                <p class="app-page-subtitle">Weekly rankings for calls taken, talk time, dispositions, meetings, and deals funded. Click a name for full call details.</p>
            </div>
            <form method="GET" class="flex gap-2">
                <select name="period" onchange="this.form.submit()" class="app-input !w-auto">
                    <option value="week" {{ $period === 'week' ? 'selected' : '' }}>This Week</option>
                    <option value="day" {{ $period === 'day' ? 'selected' : '' }}>Today</option>
                </select>
            </form>
        </div>

        <div class="app-card app-card-padded">
            <x-data-table :paginator="null" min-width="800px">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Calls</th>
                            <th>Talk time</th>
                            <th>Dispositions</th>
                            <th>Meetings</th>
                            <th>Funded</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-leaderboard-body" data-leaderboard-period="{{ $period }}"
                        data-include-score="1">
                        @forelse($leaderboard as $index => $row)
                            <tr>
                                <td class="font-black text-zinc-900">#{{ $index + 1 }}</td>
                                <td class="font-bold">
                                    <a href="{{ route('admin.dashboard', ['detail' => 'performer', 'user_id' => $row['user_id']]) }}" class="app-link">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td>{{ $row['role'] }}</td>
                                <td>{{ (int) ($row['calls_taken'] ?? $row['calls'] ?? $row['dials'] ?? 0) }}</td>
                                <td>{{ $row['talk_label'] ?? '0s' }}</td>
                                <td>{{ (int) ($row['disposed'] ?? 0) }}</td>
                                <td>{{ $row['meetings'] }}</td>
                                <td>{{ $row['deals_funded'] }}</td>
                                <td class="font-bold">{{ $row['score'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-zinc-400 py-8">No activity logged yet this
                                    period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </div>
    </div>
@endsection
