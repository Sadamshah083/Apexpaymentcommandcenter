/**
 * Call Monitoring wallboard — SSE/WebSocket stream + wall-clock connected timers.
 */

function formatTimer(seconds) {
    const sec = Math.max(0, Math.floor(Number(seconds) || 0));
    const minutes = Math.floor(sec / 60);
    const remain = sec % 60;
    // Fixed width (mm:ss) so digit changes do not shift columns.
    return `${String(minutes).padStart(2, '0')}:${String(remain).padStart(2, '0')}`;
}

function parseConnectedAtMs(value) {
    if (!value) {
        return null;
    }
    const ms = Date.parse(String(value));
    return Number.isFinite(ms) ? ms : null;
}

function timerSecForRow(row, nowMs = Date.now()) {
    const connectedAtMs = parseConnectedAtMs(row.connected_at);
    if (connectedAtMs) {
        return Math.max(1, Math.floor((nowMs - connectedAtMs) / 1000));
    }
    return Math.max(0, Math.floor(Number(row.timer_sec) || 0));
}

function bucketFor(row, timerSec = null) {
    if (row.bucket === 'incall_short' || row.bucket === 'incall_long' || row.status_group === 'incall') {
        const sec = timerSec == null ? timerSecForRow(row) : timerSec;
        return sec > 120 ? 'incall_long' : 'incall_short';
    }
    if (row.bucket) {
        return row.bucket;
    }
    const group = row.status_group || 'ringing';
    if (group === 'waiting') return 'ringing';
    return group;
}

function rowClassFor(bucket) {
    if (bucket === 'ringing' || bucket === 'waiting') return 'is-waiting';
    if (bucket === 'incall_short') return 'is-incall';
    if (bucket === 'incall_long') return 'is-incall-long';
    if (bucket === 'not_in_call' || bucket === 'idle') return 'is-idle';
    if (bucket === 'queue') return 'is-queue';
    if (bucket === 'dead') return 'is-dead';
    return 'is-waiting';
}

function statusLabelFor(bucket) {
    if (bucket === 'incall_short') return 'INCALL ≤2M';
    if (bucket === 'incall_long') return 'INCALL >2M';
    if (bucket === 'not_in_call' || bucket === 'idle') return 'NOT IN CALL';
    if (bucket === 'ringing') return 'RINGING';
    if (bucket === 'queue') return 'QUEUE';
    if (bucket === 'dead') return 'DEAD';
    return 'RINGING';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function dialModeMeta(row, { idle = false } = {}) {
    let mode = String(row.dial_mode || '').toLowerCase().trim();
    let label = String(row.dial_mode_label || '').trim();
    if (!label && idle) {
        label = String(row.destination || '').trim();
    }
    if (!mode && label) {
        mode = /auto/i.test(label) ? 'auto' : 'manual';
    }
    if (!label && mode) {
        label = mode === 'auto' ? 'Auto dial' : 'Manual dial';
    }
    let pillClass = 'call-monitoring-dial-pill--manual';
    if (mode === 'auto') {
        pillClass = /paused/i.test(label)
            ? 'call-monitoring-dial-pill--auto-paused'
            : 'call-monitoring-dial-pill--auto';
    }
    return { mode, label, pillClass };
}

function dialModePillHtml(row, { idle = false } = {}) {
    const meta = dialModeMeta(row, { idle });
    if (!meta.label) {
        return '';
    }
    return `<span class="call-monitoring-dial-pill ${meta.pillClass}">${escapeHtml(meta.label)}</span>`;
}

function destinationCellHtml(row, { idle = false } = {}) {
    if (idle) {
        return dialModePillHtml(row, { idle: true }) || '—';
    }
    return row.destination ? escapeHtml(row.destination) : '—';
}

function campaignCellHtml(row, { idle = false } = {}) {
    if (!idle) {
        const meta = dialModeMeta(row);
        const campaign = String(row.campaign || '').trim();
        if (meta.label && (!campaign || campaign === '—' || campaign === meta.label)) {
            return dialModePillHtml(row);
        }
    }
    return escapeHtml(row.campaign || '—');
}

function buildRowHtml(row, nowMs = Date.now()) {
    const idle = row.bucket === 'not_in_call' || row.status_group === 'idle';
    const connected = !idle && (
        row.status_group === 'incall'
        || row.bucket === 'incall_short'
        || row.bucket === 'incall_long'
    );
    let timerSec = 0;
    if (connected) {
        timerSec = timerSecForRow(row, nowMs);
    } else if (idle) {
        const idleMs = parseConnectedAtMs(row.idle_since);
        timerSec = idleMs
            ? Math.max(0, Math.floor((nowMs - idleMs) / 1000))
            : Math.max(0, Math.floor(Number(row.timer_sec) || 0));
    }
    const bucket = idle
        ? 'not_in_call'
        : (connected ? (timerSec > 120 ? 'incall_long' : 'incall_short') : bucketFor(row, timerSec));
    const colorClass = rowClassFor(bucket);
    const role = row.role_label
        ? `<span class="call-monitoring-row__role">${escapeHtml(row.role_label)}</span>`
        : '';
    const status = escapeHtml(statusLabelFor(bucket));
    const connectedAt = connected && row.connected_at ? escapeHtml(row.connected_at) : '';
    const idleSince = idle && row.idle_since ? escapeHtml(row.idle_since) : '';
    const dialMeta = dialModeMeta(row, { idle });

    return `<tr class="call-monitoring-row ${colorClass}"
        data-row-id="${escapeHtml(row.id || '')}"
        data-status-group="${escapeHtml(idle ? 'idle' : (connected ? 'incall' : (row.status_group || 'ringing')))}"
        data-bucket="${escapeHtml(bucket)}"
        data-timer-sec="${timerSec}"
        data-dial-mode="${escapeHtml(dialMeta.mode || '')}"
        ${connectedAt ? `data-connected-at="${connectedAt}"` : ''}
        ${idleSince ? `data-idle-since="${idleSince}"` : ''}>
        <td class="call-monitoring-row__station">${escapeHtml(row.station || '—')}</td>
        <td class="call-monitoring-row__user">
            <span class="call-monitoring-row__name">${escapeHtml(row.user || '—')}</span>
            ${role}
        </td>
        <td class="call-monitoring-row__status">
            <span class="call-monitoring-status-pill">${status}</span>
        </td>
        <td class="call-monitoring-row__timer" data-row-timer>${(connected || idle) ? formatTimer(timerSec) : '00:00'}</td>
        <td class="call-monitoring-row__dest">${destinationCellHtml(row, { idle })}</td>
        <td class="call-monitoring-row__campaign">${campaignCellHtml(row, { idle })}</td>
    </tr>`;
}

function emptyRow(message) {
    return `<tr class="call-monitoring-empty" data-call-monitoring-empty>
        <td colspan="6">${escapeHtml(message)}</td>
    </tr>`;
}

function emptyMessageFor(bucket) {
    if (bucket === 'ringing') return 'No ringing calls.';
    if (bucket === 'incall_short') return 'No connected calls under 2 minutes.';
    if (bucket === 'incall_long') return 'No connected calls over 2 minutes.';
    if (bucket === 'not_in_call') return 'No logged-in agents available (not in call).';
    return 'No calls.';
}

function fillTable(root, bucket, rows, nowMs = Date.now()) {
    const tbody = root.querySelector(`[data-call-monitoring-rows="${bucket}"]`);
    const board = root.querySelector(`[data-call-monitoring-board="${bucket}"]`);
    if (!tbody) {
        return;
    }

    const list = Array.isArray(rows) ? rows : [];
    // Remember role labels per row so light/full poll flicker cannot blank "Appointment Setter".
    if (!root._roleLabelMemory) {
        root._roleLabelMemory = Object.create(null);
    }
    if (!root._stationMemory) {
        root._stationMemory = Object.create(null);
    }
    list.forEach((row) => {
        const id = String(row.id || '');
        const label = String(row.role_label || '').trim();
        if (id && label) {
            root._roleLabelMemory[id] = label;
        } else if (id && !label && root._roleLabelMemory[id]) {
            row.role_label = root._roleLabelMemory[id];
        }
        const station = String(row.station || row.extension || '').trim();
        if (id && station && station !== '—') {
            root._stationMemory[id] = station;
            row.station = station;
            row.extension = station;
        } else if (id && (!station || station === '—') && root._stationMemory[id]) {
            row.station = root._stationMemory[id];
            row.extension = root._stationMemory[id];
        }
    });

    const nextIds = list.map((row) => String(row.id || '')).join('|');
    const existingRows = [...tbody.querySelectorAll('.call-monitoring-row')];
    const currentIds = existingRows.map((row) => row.dataset.rowId || '').join('|');

    // Same set of calls: update cells in place so the table does not jump each poll/tick.
    if (list.length > 0 && nextIds === currentIds && existingRows.length === list.length) {
        list.forEach((row, index) => {
            patchRow(existingRows[index], row, nowMs);
        });
    } else {
        tbody.innerHTML = list.length > 0
            ? list.map((row) => buildRowHtml(row, nowMs)).join('')
            : emptyRow(emptyMessageFor(bucket));
    }

    const countEl = board?.querySelector('[data-board-count]');
    if (countEl) {
        countEl.textContent = String(list.length);
    }
}

function patchRow(el, row, nowMs = Date.now()) {
    if (!el || !row) {
        return;
    }

    const idle = row.bucket === 'not_in_call' || row.status_group === 'idle';
    const connected = !idle && (
        row.status_group === 'incall'
        || row.bucket === 'incall_short'
        || row.bucket === 'incall_long'
    );
    let timerSec = 0;
    if (connected) {
        timerSec = timerSecForRow(row, nowMs);
    } else if (idle) {
        const idleMs = parseConnectedAtMs(row.idle_since);
        timerSec = idleMs
            ? Math.max(0, Math.floor((nowMs - idleMs) / 1000))
            : Math.max(0, Math.floor(Number(row.timer_sec) || 0));
    }
    const bucket = idle
        ? 'not_in_call'
        : (connected ? (timerSec > 120 ? 'incall_long' : 'incall_short') : bucketFor(row, timerSec));

    el.dataset.statusGroup = idle ? 'idle' : (connected ? 'incall' : (row.status_group || 'ringing'));
    el.dataset.bucket = bucket;
    el.dataset.timerSec = String(timerSec);
    if (connected && row.connected_at) {
        el.dataset.connectedAt = String(row.connected_at);
    } else {
        delete el.dataset.connectedAt;
    }
    if (idle && row.idle_since) {
        el.dataset.idleSince = String(row.idle_since);
    } else {
        delete el.dataset.idleSince;
    }

    el.classList.remove('is-waiting', 'is-incall', 'is-incall-long', 'is-idle', 'is-queue', 'is-dead');
    el.classList.add(rowClassFor(bucket));

    const timerEl = el.querySelector('[data-row-timer]');
    if (timerEl) {
        timerEl.textContent = (connected || idle) ? formatTimer(timerSec) : '00:00';
    }
    const pill = el.querySelector('.call-monitoring-status-pill');
    if (pill) {
        pill.textContent = statusLabelFor(bucket);
    }
    const station = el.querySelector('.call-monitoring-row__station');
    if (station) {
        const nextStation = String(row.station || row.extension || '').trim();
        if (nextStation && nextStation !== '—') {
            station.textContent = nextStation;
        }
        // Never blank a known station during light polls.
    }
    const name = el.querySelector('.call-monitoring-row__name');
    if (name) {
        name.textContent = row.user || '—';
    }
    const userCell = el.querySelector('.call-monitoring-row__user');
    const roleLabel = String(row.role_label || '').trim();
    let roleEl = el.querySelector('.call-monitoring-row__role');
    if (roleLabel) {
        if (!roleEl && userCell) {
            roleEl = document.createElement('span');
            roleEl.className = 'call-monitoring-row__role';
            userCell.appendChild(roleEl);
        }
        if (roleEl) {
            roleEl.textContent = roleLabel;
            roleEl.hidden = false;
        }
    }
    // Keep an existing Appointment Setter (etc.) badge if this poll omitted role_label.
    const dest = el.querySelector('.call-monitoring-row__dest');
    if (dest) {
        dest.innerHTML = destinationCellHtml(row, { idle });
    }
    const campaign = el.querySelector('.call-monitoring-row__campaign');
    if (campaign) {
        campaign.innerHTML = campaignCellHtml(row, { idle });
    }
    const dialMeta = dialModeMeta(row, { idle });
    if (dialMeta.mode) {
        el.dataset.dialMode = dialMeta.mode;
    } else {
        delete el.dataset.dialMode;
    }
}

function applySnapshot(root, payload) {
    const nowMs = Date.now();
    const summary = payload.summary || {};
    root.querySelectorAll('[data-stat]').forEach((el) => {
        const key = el.dataset.stat;
        if (!key) {
            return;
        }
        if (key === 'ringing') {
            el.textContent = String(summary.ringing ?? summary.waiting ?? 0);
            return;
        }
        if (Object.prototype.hasOwnProperty.call(summary, key)) {
            el.textContent = String(summary[key] ?? 0);
        }
    });

    const updated = root.querySelector('[data-call-monitoring-updated]');
    if (updated) {
        updated.textContent = new Date(nowMs).toLocaleTimeString();
    }

    const tables = payload.tables || {};
    const allRows = Array.isArray(payload.rows) ? payload.rows : [];
    const enriched = allRows.map((row) => {
        if (row.status_group !== 'incall' && row.bucket !== 'incall_short' && row.bucket !== 'incall_long') {
            return row;
        }
        const timerSec = timerSecForRow(row, nowMs);
        return {
            ...row,
            timer_sec: timerSec,
            bucket: timerSec > 120 ? 'incall_long' : 'incall_short',
        };
    });

    const ringing = tables.ringing
        || enriched.filter((row) => bucketFor(row) === 'ringing');
    const shortRows = (tables.incall_short || enriched.filter((row) => bucketFor(row) === 'incall_short'))
        .map((row) => ({ ...row, timer_sec: timerSecForRow(row, nowMs) }))
        .filter((row) => timerSecForRow(row, nowMs) <= 120);
    const longRows = (tables.incall_long || enriched.filter((row) => bucketFor(row) === 'incall_long'))
        .map((row) => ({ ...row, timer_sec: timerSecForRow(row, nowMs) }))
        .filter((row) => timerSecForRow(row, nowMs) > 120);

    // Move any short→long boundary rows that tables arrays still split wrongly.
    const allConnected = [...shortRows, ...longRows];
    const shortFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) <= 120);
    const longFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) > 120);

    const notInCall = Array.isArray(tables.not_in_call) ? tables.not_in_call : [];

    fillTable(root, 'ringing', ringing, nowMs);
    fillTable(root, 'incall_short', shortFinal, nowMs);
    fillTable(root, 'incall_long', longFinal, nowMs);
    fillTable(root, 'not_in_call', notInCall, nowMs);

    const shortStat = root.querySelector('[data-stat="in_call_short"]');
    const longStat = root.querySelector('[data-stat="in_call_long"]');
    const totalStat = root.querySelector('[data-stat="in_call"]');
    const idleStat = root.querySelector('[data-stat="not_in_call"]');
    const agentsStat = root.querySelector('[data-stat="total"]');
    if (shortStat) shortStat.textContent = String(shortFinal.length);
    if (longStat) longStat.textContent = String(longFinal.length);
    if (totalStat) totalStat.textContent = String(shortFinal.length + longFinal.length);
    if (idleStat) idleStat.textContent = String(notInCall.length);
    if (agentsStat) {
        agentsStat.textContent = String(
            Number(summary.total ?? (shortFinal.length + longFinal.length + ringing.length + notInCall.length))
        );
    }
}

function rebucketConnectedRows(root) {
    const shortBody = root.querySelector('[data-call-monitoring-rows="incall_short"]');
    const longBody = root.querySelector('[data-call-monitoring-rows="incall_long"]');
    if (!shortBody || !longBody) {
        return;
    }

    const moveToLong = [];
    shortBody.querySelectorAll('.call-monitoring-row[data-bucket="incall_short"]').forEach((row) => {
        const sec = Number(row.dataset.timerSec) || 0;
        if (sec > 120) {
            moveToLong.push(row);
        }
    });

    moveToLong.forEach((row) => {
        row.dataset.bucket = 'incall_long';
        row.classList.remove('is-incall');
        row.classList.add('is-incall-long');
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill) {
            pill.textContent = 'INCALL >2M';
        }
        longBody.querySelector('[data-call-monitoring-empty]')?.remove();
        longBody.appendChild(row);
    });

    if (shortBody.children.length === 0) {
        shortBody.innerHTML = emptyRow(emptyMessageFor('incall_short'));
    }
    if (longBody.children.length === 0) {
        longBody.innerHTML = emptyRow(emptyMessageFor('incall_long'));
    }

    const shortBoard = root.querySelector('[data-call-monitoring-board="incall_short"] [data-board-count]');
    const longBoard = root.querySelector('[data-call-monitoring-board="incall_long"] [data-board-count]');
    if (shortBoard) {
        shortBoard.textContent = String(shortBody.querySelectorAll('.call-monitoring-row').length);
    }
    if (longBoard) {
        longBoard.textContent = String(longBody.querySelectorAll('.call-monitoring-row').length);
    }

    const shortStat = root.querySelector('[data-stat="in_call_short"]');
    const longStat = root.querySelector('[data-stat="in_call_long"]');
    const totalStat = root.querySelector('[data-stat="in_call"]');
    const shortCount = shortBody.querySelectorAll('.call-monitoring-row').length;
    const longCount = longBody.querySelectorAll('.call-monitoring-row').length;
    if (shortStat) shortStat.textContent = String(shortCount);
    if (longStat) longStat.textContent = String(longCount);
    if (totalStat) totalStat.textContent = String(shortCount + longCount);
}

function tickTimers(root) {
    const nowMs = Date.now();
    root.querySelectorAll('.call-monitoring-row[data-status-group="incall"]').forEach((row) => {
        const connectedAtMs = parseConnectedAtMs(row.dataset.connectedAt);
        const next = connectedAtMs
            ? Math.max(1, Math.floor((nowMs - connectedAtMs) / 1000))
            : (Number(row.dataset.timerSec) || 0) + 1;
        row.dataset.timerSec = String(next);
        const timerEl = row.querySelector('[data-row-timer]');
        if (timerEl) {
            timerEl.textContent = formatTimer(next);
        }
        const bucket = next > 120 ? 'incall_long' : 'incall_short';
        row.dataset.bucket = bucket;
        row.classList.remove('is-waiting', 'is-incall', 'is-incall-long', 'is-idle', 'is-queue', 'is-dead');
        row.classList.add(rowClassFor(bucket));
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill) {
            pill.textContent = statusLabelFor(bucket);
        }
    });
    root.querySelectorAll('.call-monitoring-row[data-status-group="idle"], .call-monitoring-row[data-bucket="not_in_call"]').forEach((row) => {
        const idleAtMs = parseConnectedAtMs(row.dataset.idleSince);
        const next = idleAtMs
            ? Math.max(0, Math.floor((nowMs - idleAtMs) / 1000))
            : (Number(row.dataset.timerSec) || 0) + 1;
        row.dataset.timerSec = String(next);
        const timerEl = row.querySelector('[data-row-timer]');
        if (timerEl) {
            timerEl.textContent = formatTimer(next);
        }
        row.dataset.bucket = 'not_in_call';
        row.classList.remove('is-waiting', 'is-incall', 'is-incall-long', 'is-queue', 'is-dead');
        row.classList.add('is-idle');
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill) {
            pill.textContent = 'NOT IN CALL';
        }
    });
    rebucketConnectedRows(root);
    syncSidebarFromBoard(root);
}

function setChip(el, value) {
    if (!el) {
        return;
    }
    const count = Math.max(0, Number(value) || 0);
    el.textContent = String(count);
    el.hidden = count === 0;
    el.classList.toggle('is-empty', count === 0);
}

function updateSidebarBadges(payload) {
    const summary = payload?.summary || {};
    const inCall = Number(summary.in_call ?? 0);
    const ringing = Number(summary.ringing ?? summary.waiting ?? 0);

    document.querySelectorAll('[data-call-monitoring-nav]').forEach((link) => {
        setChip(link.querySelector('[data-call-monitoring-nav-incall]'), inCall);
        setChip(link.querySelector('[data-call-monitoring-nav-waiting]'), ringing);
        link.classList.toggle('has-live-incall', inCall > 0);
        link.classList.toggle('has-live-waiting', ringing > 0);
    });
}

function syncSidebarFromBoard(root) {
    if (!root) {
        return;
    }
    const shortCount = root.querySelectorAll('[data-call-monitoring-rows="incall_short"] .call-monitoring-row').length;
    const longCount = root.querySelectorAll('[data-call-monitoring-rows="incall_long"] .call-monitoring-row').length;
    const ringingCount = root.querySelectorAll('[data-call-monitoring-rows="ringing"] .call-monitoring-row').length;
    updateSidebarBadges({
        summary: {
            in_call: shortCount + longCount,
            ringing: ringingCount,
            waiting: ringingCount,
        },
    });
}

let monitoringRuntime = null;

/**
 * Timers tick locally every 1s from connected_at (matches dialer).
 * When the board has live rows, poll fast so hangup disappears in real time.
 */
const BOARD_POLL_ACTIVE_MS = 1000;
const BOARD_POLL_IDLE_MS = 4000;
const BOARD_FULL_EVERY = 8;
const NAV_POLL_MS = 45000;

function digitsOnly(value) {
    return String(value ?? '').replace(/\D/g, '');
}

function destinationTail(value) {
    const digits = digitsOnly(value);
    return digits.length > 10 ? digits.slice(-10) : digits;
}

function removeEndedCallsFromBoard(board, detail = {}) {
    if (!board) {
        return false;
    }

    const endedId = String(detail.callUuid || '').trim();
    const endedDest = destinationTail(detail.phone || detail.destination || '');
    const endedExt = digitsOnly(detail.extension || '');
    const related = new Set((detail.relatedUuids || []).map((id) => String(id || '').trim()).filter(Boolean));
    if (endedId) {
        related.add(endedId);
    }

    let removed = false;
    board.querySelectorAll('.call-monitoring-row').forEach((row) => {
        const rowId = String(row.dataset.rowId || '').trim();
        const rowDest = destinationTail(row.querySelector('.call-monitoring-row__dest')?.textContent || '');
        const rowExt = digitsOnly(row.querySelector('.call-monitoring-row__station')?.textContent || '');
        const idMatch = rowId && related.has(rowId);
        const destMatch = endedDest && rowDest && endedDest === rowDest;
        const extMatch = !endedExt || !rowExt || endedExt === rowExt;
        if (idMatch || (destMatch && extMatch)) {
            row.remove();
            removed = true;
        }
    });

    if (!removed) {
        return false;
    }

    ['ringing', 'incall_short', 'incall_long'].forEach((bucket) => {
        const tbody = board.querySelector(`[data-call-monitoring-rows="${bucket}"]`);
        if (!tbody) {
            return;
        }
        if (!tbody.querySelector('.call-monitoring-row')) {
            tbody.innerHTML = emptyRow(emptyMessageFor(bucket));
        }
        const countEl = board.querySelector(`[data-call-monitoring-board="${bucket}"] [data-board-count]`);
        if (countEl) {
            countEl.textContent = String(tbody.querySelectorAll('.call-monitoring-row').length);
        }
    });

    const shortCount = board.querySelectorAll('[data-call-monitoring-rows="incall_short"] .call-monitoring-row').length;
    const longCount = board.querySelectorAll('[data-call-monitoring-rows="incall_long"] .call-monitoring-row').length;
    const ringingCount = board.querySelectorAll('[data-call-monitoring-rows="ringing"] .call-monitoring-row').length;
    const shortStat = board.querySelector('[data-stat="in_call_short"]');
    const longStat = board.querySelector('[data-stat="in_call_long"]');
    const totalStat = board.querySelector('[data-stat="in_call"]');
    const ringingStat = board.querySelector('[data-stat="ringing"]');
    if (shortStat) shortStat.textContent = String(shortCount);
    if (longStat) longStat.textContent = String(longCount);
    if (totalStat) totalStat.textContent = String(shortCount + longCount);
    if (ringingStat) ringingStat.textContent = String(ringingCount);
    syncSidebarFromBoard(board);

    return true;
}

function boardHasLiveRows(board) {
    return Boolean(board?.querySelector('.call-monitoring-row'));
}

function stopMonitoringRuntime() {
    if (!monitoringRuntime) {
        return;
    }
    window.clearInterval(monitoringRuntime.pollTimer);
    window.clearInterval(monitoringRuntime.tickTimer);
    monitoringRuntime.abortController?.abort();
    monitoringRuntime.eventSource?.close();
    if (monitoringRuntime.onVisibility) {
        document.removeEventListener('visibilitychange', monitoringRuntime.onVisibility);
    }
    if (monitoringRuntime.onHangup) {
        window.removeEventListener('comm:monitoring-hangup', monitoringRuntime.onHangup);
        window.removeEventListener('comm:call-ended', monitoringRuntime.onHangup);
    }
    if (monitoringRuntime.broadcast) {
        try {
            monitoringRuntime.broadcast.close();
        } catch {
            // ignore
        }
    }
    monitoringRuntime = null;
    document.documentElement.dataset.callMonitoringInit = '0';
}

export function initCallMonitoring(root = document) {
    const scope = root === document ? document : root;
    const board = scope.querySelector('[data-call-monitoring]');
    const navLinks = document.querySelectorAll('[data-call-monitoring-nav]');

    if (!board && navLinks.length === 0) {
        return;
    }

    // Re-bind after Turbo navigations.
    stopMonitoringRuntime();
    document.documentElement.dataset.callMonitoringInit = '1';

    const pollUrl = board?.dataset.callMonitoringPollUrl
        || navLinks[0]?.dataset.callMonitoringPollUrl
        || '';

    const navOnly = !board && navLinks.length > 0;

    let pollTimer = null;
    let tickTimer = null;
    let fetching = false;
    let pollCount = 0;
    let abortController = null;

    const handlePayload = (payload) => {
        if (!payload || payload.ok === false) {
            return;
        }
        if (board) {
            applySnapshot(board, payload);
            syncSidebarFromBoard(board);
        } else {
            updateSidebarBadges(payload);
        }
    };

    const poll = async ({ full = false } = {}) => {
        if (!pollUrl || fetching) {
            return;
        }
        if (document.hidden) {
            return;
        }
        fetching = true;
        abortController = new AbortController();
        if (monitoringRuntime) {
            monitoringRuntime.abortController = abortController;
        }
        try {
            const url = full
                ? `${pollUrl}${pollUrl.includes('?') ? '&' : '?'}full=1`
                : pollUrl;
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: abortController.signal,
                cache: 'no-store',
            });
            const payload = await response.json();
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'Could not refresh call monitoring.');
            }
            handlePayload(payload);
        } catch (error) {
            if (error?.name !== 'AbortError') {
                console.warn('[call-monitoring]', error);
            }
        } finally {
            fetching = false;
        }
    };

    const reschedulePoll = () => {
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }
        if (navOnly) {
            pollTimer = window.setInterval(() => {
                void poll({ full: false });
            }, NAV_POLL_MS);
        } else {
            const delay = boardHasLiveRows(board) ? BOARD_POLL_ACTIVE_MS : BOARD_POLL_IDLE_MS;
            pollTimer = window.setInterval(scheduleBoardPoll, delay);
        }
        if (monitoringRuntime) {
            monitoringRuntime.pollTimer = pollTimer;
        }
    };

    const scheduleBoardPoll = () => {
        void poll({ full: pollCount === 0 || (pollCount % BOARD_FULL_EVERY) === 0 }).finally(() => {
            // After each fetch, adapt interval to whether anyone is still live.
            if (!navOnly) {
                reschedulePoll();
            }
        });
        pollCount += 1;
    };

    let lastVisibilityPollAt = 0;
    const onVisibility = () => {
        if (document.hidden || document.documentElement.dataset.callMonitoringInit !== '1') {
            return;
        }
        const now = Date.now();
        const minGap = boardHasLiveRows(board) ? BOARD_POLL_ACTIVE_MS : BOARD_POLL_IDLE_MS;
        if (now - lastVisibilityPollAt < minGap) {
            return;
        }
        lastVisibilityPollAt = now;
        scheduleBoardPoll();
    };

    const onHangup = (event) => {
        const detail = event?.detail || event?.data || {};
        if (detail?.type && detail.type !== 'call-ended') {
            return;
        }
        if (board) {
            removeEndedCallsFromBoard(board, detail);
        }
        // Confirm with server immediately so other viewers also clear.
        void poll({ full: false });
        reschedulePoll();
    };

    if (navOnly) {
        void poll({ full: false });
        reschedulePoll();
    } else {
        scheduleBoardPoll();
        reschedulePoll();
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('comm:monitoring-hangup', onHangup);
        window.addEventListener('comm:call-ended', onHangup);

        tickTimer = window.setInterval(() => {
            if (board) {
                tickTimers(board);
            }
        }, 1000);
    }

    let broadcast = null;
    try {
        broadcast = new BroadcastChannel('apex-call-monitoring');
        broadcast.onmessage = (event) => onHangup(event);
    } catch {
        broadcast = null;
    }

    monitoringRuntime = {
        pollTimer,
        tickTimer,
        abortController,
        eventSource: null,
        onVisibility: navOnly ? null : onVisibility,
        onHangup: navOnly ? null : onHangup,
        broadcast,
    };

    document.addEventListener('turbo:before-cache', stopMonitoringRuntime, { once: true });
}

export function teardownCallMonitoring() {
    stopMonitoringRuntime();
}
