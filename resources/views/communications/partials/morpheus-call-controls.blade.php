@php
    $uuid = $uuid ?? ($log['id'] ?? null);
    $queues = $queues ?? ($morpheusQueues ?? []);
    $users = $users ?? ($morpheusUsers ?? []);
    $conferences = $conferences ?? ($morpheusConferences ?? []);
    $activeCalls = $activeCalls ?? [];
@endphp

@if(filled($uuid))
    <details class="morpheus-call-controls comm-hub-card p-4" open>
        <summary class="text-sm font-bold text-slate-800 cursor-pointer">Live call controls</summary>

        <div class="flex flex-wrap items-center gap-2 mt-3">
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.hangup', ['uuid' => $uuid]) }}">@csrf<button type="submit" class="comm-hub-btn text-xs py-1 px-3 comm-hub-btn-secondary">Hang up</button></form>
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.hold', ['uuid' => $uuid]) }}">@csrf<button type="submit" class="comm-hub-btn text-xs py-1 px-3 comm-hub-btn-secondary">Hold</button></form>
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.unhold', ['uuid' => $uuid]) }}">@csrf<button type="submit" class="comm-hub-btn text-xs py-1 px-3 comm-hub-btn-secondary">Unhold</button></form>
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.park', ['uuid' => $uuid]) }}">@csrf<button type="submit" class="comm-hub-btn text-xs py-1 px-3 comm-hub-btn-secondary">Park</button></form>
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.unbridge', ['uuid' => $uuid]) }}">@csrf<button type="submit" class="comm-hub-btn text-xs py-1 px-3 comm-hub-btn-secondary">Unbridge</button></form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.transfer', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Blind transfer</label>
                    <input type="text" name="destination" placeholder="Extension or number" required class="comm-hub-input text-xs w-full">
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">Transfer</button>
            </form>

            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.unpark', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Unpark to</label>
                    <input type="text" name="destination" placeholder="Extension or number" required class="comm-hub-input text-xs w-full">
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">Unpark</button>
            </form>

            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.bridge', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Bridge to call UUID</label>
                    <input type="text" name="other_uuid" placeholder="Other active call UUID" required class="comm-hub-input text-xs w-full">
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">Bridge</button>
            </form>

            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.join-conference', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Join conference</label>
                    <select name="conference" class="comm-hub-input text-xs w-full" required>
                        <option value="">Select room</option>
                        @foreach($conferences as $room)
                            <option value="{{ $room['id'] ?? $room['name'] }}">{{ $room['name'] ?? 'Room' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">Join</button>
            </form>

            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.transfer-to-queue', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Transfer to queue</label>
                    <select name="queue_id" class="comm-hub-input text-xs w-full" required>
                        <option value="">Select queue</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue['id'] }}">{{ $queue['name'] ?? 'Queue' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">To queue</button>
            </form>

            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.transfer-to-agent', ['uuid' => $uuid]) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="comm-hub-label text-xs">Transfer to agent</label>
                    <select name="agent_user_id" class="comm-hub-input text-xs w-full" required>
                        <option value="">Select agent</option>
                        @foreach($users as $user)
                            <option value="{{ $user['id'] }}">{{ $user['name'] ?? $user['email'] ?? 'Agent' }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="comm-hub-btn text-xs py-1 px-3">To agent</button>
            </form>
        </div>

        <form method="POST" action="{{ route($routePrefix.'communications.morpheus.calls.disposition', ['uuid' => $uuid]) }}" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-2 items-end">
            @csrf
            <div>
                <label class="comm-hub-label text-xs">Disposition</label>
                <input type="text" name="disposition" placeholder="e.g. NI, SALE" required maxlength="64" class="comm-hub-input text-xs w-full">
            </div>
            <div class="md:col-span-2">
                <label class="comm-hub-label text-xs">Note</label>
                <input type="text" name="note" placeholder="Optional note" maxlength="1000" class="comm-hub-input text-xs w-full">
            </div>
            <label class="flex items-center gap-2 text-xs text-slate-600">
                <input type="hidden" name="update_lead" value="0">
                <input type="checkbox" name="update_lead" value="1" checked> Update lead disposition
            </label>
            <button type="submit" class="comm-hub-btn text-xs py-1 px-3 md:col-span-2">Record disposition</button>
        </form>
    </details>
@endif
