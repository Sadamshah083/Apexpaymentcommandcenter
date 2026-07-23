@php
    $formAction = route($routePrefix.'communications.notes');
    $showAllAgents = (bool) ($showAllAgents ?? false);
@endphp

<div class="app-page space-y-5 call-notes-page">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="app-page-title">Call Notes</h1>
        </div>
        <div class="call-notes-toolbar">
            @if ($isAdminView)
                <form method="GET" action="{{ $formAction }}" class="call-notes-agent-form">
                    <label class="call-notes-agent-label" for="call-notes-agent">Agent</label>
                    <select id="call-notes-agent" name="agent_id" class="app-input call-notes-agent-select"
                        data-pretty-select data-pretty-select-width="trigger" onchange="this.form.submit()">
                        <option value="all" @selected($showAllAgents || (int) $selectedAgentId === 0)>All agents</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent['id'] }}" @selected((int) $selectedAgentId === (int) $agent['id'])>
                                {{ $agent['name'] }}@if (!empty($agent['role'])) — {{ $agent['role'] }}@endif
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif

            @if (!empty($downloadUrl))
                <a href="{{ $downloadUrl }}" class="app-btn app-btn-secondary call-notes-download" download>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l4-4m-4 4l-4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>
                    </svg>
                    Download notes
                </a>
            @endif
        </div>
    </div>

    <div class="app-card app-card-padded call-notes-card">
        <div class="call-notes-card__head">
            <h2 class="call-notes-card__title">
                {{ $selectedAgent['name'] ?? 'Agent' }}
                @if (!empty($selectedAgent['role']))
                    <span class="call-notes-card__role">· {{ $selectedAgent['role'] }}</span>
                @endif
            </h2>
            @if ($notes)
                <span class="call-notes-card__count">{{ number_format($notes->total()) }} note{{ $notes->total() === 1 ? '' : 's' }}</span>
            @endif
        </div>

        <div class="call-notes-table-wrap">
            <table class="call-notes-table">
                <thead>
                    <tr>
                        @if ($showAllAgents)
                            <th>Agent</th>
                        @endif
                        <th>Number</th>
                        <th>Disposition</th>
                        <th>Notes</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($notes ?? collect()) as $row)
                        <tr>
                            @if ($showAllAgents)
                                <td>{{ $row['agent'] ?? '—' }}</td>
                            @endif
                            <td class="call-notes-phone">{{ $row['phone'] }}</td>
                            <td>
                                @if (($row['disposition'] ?? '—') !== '—')
                                    <span class="call-notes-dispo">{{ $row['disposition'] }}</span>
                                @else
                                    <span class="call-notes-muted">—</span>
                                @endif
                            </td>
                            <td class="call-notes-body">{!! nl2br(e($row['notes'])) !!}</td>
                            <td class="call-notes-muted" title="{{ $row['when_exact'] ?? '' }}">{{ $row['when_display'] ?? $row['when'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showAllAgents ? 5 : 4 }}" class="call-notes-empty-row">
                                No notes or dispositions logged yet{{ $showAllAgents ? ' for these agents' : ' for this agent' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($notes && $notes->total() > 0)
            <x-pagination :paginator="$notes" class="call-notes-pagination" />
        @endif
    </div>
</div>
