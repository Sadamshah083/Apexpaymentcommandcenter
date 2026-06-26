@extends('layouts.admin')

@section('title', 'Team Performance')

@section('content')
<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="app-page-title">Team Performance & Leaderboards</h1>
            <p class="app-page-subtitle">Weekly rankings for dials, discoveries, meetings booked, and deals funded.</p>
        </div>
        <form method="GET" class="flex gap-2">
            <select name="period" onchange="this.form.submit()" class="px-3 py-2 border border-slate-200 rounded-xl text-sm">
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
                        <th>Dials</th>
                        <th>Live Conversations</th>
                        <th>Discoveries</th>
                        <th>Meetings Booked</th>
                        <th>Deals Funded</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($leaderboard as $index => $row)
                        <tr>
                            <td class="font-black text-indigo-600">#{{ $index + 1 }}</td>
                            <td class="font-bold">{{ $row['name'] }}</td>
                            <td>{{ $row['role'] }}</td>
                            <td>{{ $row['dials'] }}</td>
                            <td>{{ $row['conversations'] }}</td>
                            <td>{{ $row['discoveries'] }}</td>
                            <td>{{ $row['meetings'] }}</td>
                            <td>{{ $row['deals_funded'] }}</td>
                            <td class="font-bold">{{ $row['score'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-slate-400 py-8">No activity logged yet this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-data-table>
    </div>
</div>
@endsection
