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
        // Match dialer: start at 00:00 (do not force a +1s floor).
        return Math.max(0, Math.floor((nowMs - connectedAtMs) / 1000));
    }
    return Math.max(0, Math.floor(Number(row.timer_sec) || 0));
}

function bucketFor(row, timerSec = null) {
    if (row.bucket === 'disposition' || row.status_group === 'disposition') {
        return 'disposition';
    }
    if (row.bucket === 'not_logged_in' || row.status_group === 'not_logged_in') {
        return 'not_logged_in';
    }
    if (row.bucket === 'dead' || row.status_group === 'dead') {
        return 'dead';
    }
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
    if (bucket === 'break') return 'is-break';
    if (bucket === 'lunch') return 'is-lunch';
    if (bucket === 'disposition') return 'is-disposition';
    if (bucket === 'not_logged_in') return 'is-offline';
    if (bucket === 'queue') return 'is-queue';
    if (bucket === 'dead') return 'is-dead';
    return 'is-waiting';
}

function statusLabelFor(bucket) {
    if (bucket === 'incall_short') return 'INCALL ≤2M';
    if (bucket === 'incall_long') return 'INCALL >2M';
    if (bucket === 'not_in_call' || bucket === 'idle') return 'NOT IN CALL';
    if (bucket === 'break') return 'BREAK';
    if (bucket === 'lunch') return 'LUNCH';
    if (bucket === 'disposition') return 'DISPOSITION';
    if (bucket === 'not_logged_in') return 'NOT LOGGED IN';
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

function campaignCellHtml(row, { idle = false, onBreak = false } = {}) {
    if (onBreak || row.bucket === 'break' || row.bucket === 'lunch'
        || row.status_group === 'break' || row.status_group === 'lunch') {
        return '—';
    }
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
    const onBreak = row.bucket === 'break' || row.status_group === 'break'
        || row.bucket === 'lunch' || row.status_group === 'lunch';
    const disposition = row.bucket === 'disposition' || row.status_group === 'disposition';
    const offline = row.bucket === 'not_logged_in' || row.status_group === 'not_logged_in';
    const dead = row.bucket === 'dead' || row.status_group === 'dead';
    const connected = !idle && !onBreak && !disposition && !offline && !dead && (
        row.status_group === 'incall'
        || row.bucket === 'incall_short'
        || row.bucket === 'incall_long'
    );
    let timerSec = 0;
    if (connected) {
        timerSec = timerSecForRow(row, nowMs);
    } else if (idle || disposition || onBreak) {
        const idleMs = parseConnectedAtMs(row.idle_since);
        timerSec = idleMs
            ? Math.max(0, Math.floor((nowMs - idleMs) / 1000))
            : Math.max(0, Math.floor(Number(row.timer_sec) || 0));
    }
    const bucket = offline
        ? 'not_logged_in'
        : (idle
            ? 'not_in_call'
            : (onBreak
                ? (row.bucket === 'lunch' || row.status_group === 'lunch' ? 'lunch' : 'break')
                : (disposition
                    ? 'disposition'
                    : (dead ? 'dead' : (connected ? (timerSec > 120 ? 'incall_long' : 'incall_short') : bucketFor(row, timerSec))))));
    const colorClass = rowClassFor(bucket);
    const role = row.role_label
        ? `<span class="call-monitoring-row__role">${escapeHtml(row.role_label)}</span>`
        : '';
    const status = escapeHtml(row.status || statusLabelFor(bucket));
    const connectedAt = connected && row.connected_at ? escapeHtml(row.connected_at) : '';
    const idleSince = (idle || disposition || onBreak) && row.idle_since ? escapeHtml(row.idle_since) : '';
    const breakEndsAt = onBreak && (row.break_ends_at || row.ends_at)
        ? escapeHtml(row.break_ends_at || row.ends_at)
        : '';
    const dialMeta = dialModeMeta(row, { idle });
    const statusGroup = offline
        ? 'not_logged_in'
        : (idle
            ? 'idle'
            : (onBreak
                ? bucket
                : (disposition
                    ? 'disposition'
                    : (dead ? 'dead' : (connected ? 'incall' : (row.status_group || 'ringing'))))));

    return `<tr class="call-monitoring-row ${colorClass}"
        data-row-id="${escapeHtml(row.id || '')}"
        data-status-group="${escapeHtml(statusGroup)}"
        data-bucket="${escapeHtml(bucket)}"
        data-timer-sec="${timerSec}"
        data-dial-mode="${escapeHtml(dialMeta.mode || '')}"
        ${connectedAt ? `data-connected-at="${connectedAt}"` : ''}
        ${idleSince ? `data-idle-since="${idleSince}"` : ''}
        ${breakEndsAt ? `data-break-ends-at="${breakEndsAt}"` : ''}>
        <td class="call-monitoring-row__station">${escapeHtml(row.station || '—')}</td>
        <td class="call-monitoring-row__user">
            <div class="call-monitoring-row__user-inner">
                <span class="call-monitoring-row__name" title="${escapeHtml(row.user || '—')}">${escapeHtml(row.user || '—')}</span>
                ${role}
            </div>
        </td>
        <td class="call-monitoring-row__status">
            <span class="call-monitoring-status-pill">${status}</span>
        </td>
        <td class="call-monitoring-row__timer" data-row-timer>${(connected || idle || disposition || onBreak) ? formatTimer(timerSec) : '00:00'}</td>
        <td class="call-monitoring-row__dest">${onBreak ? escapeHtml(breakDestinationLabel(row, nowMs)) : destinationCellHtml(row, { idle })}</td>
        <td class="call-monitoring-row__campaign">${campaignCellHtml(row, { idle: idle || disposition, onBreak })}</td>
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
    if (bucket === 'dead') return 'No dead / missed calls right now.';
    if (bucket === 'disposition') return 'No agents on disposition.';
    if (bucket === 'not_in_call') return 'No logged-in agents available (not in call).';
    if (bucket === 'not_logged_in') return 'All monitorable agents are logged in.';
    if (bucket === 'all') return 'No agents or calls to show right now.';
    return 'No calls.';
}

function rowIdentityKeys(row) {
    const id = String(row?.id || '').trim();
    const uid = Number(row?.user_id || 0);
    const ext = String(row?.station || row?.extension || '').replace(/\D/g, '');
    return { id, uid, ext };
}

function occupyKeySet(rows) {
    const users = new Set();
    const exts = new Set();
    (rows || []).forEach((row) => {
        const { uid, ext } = rowIdentityKeys(row);
        if (uid > 0) users.add(uid);
        if (ext) exts.add(ext);
    });
    return { users, exts };
}

function isOccupied(row, occupied) {
    const { uid, ext } = rowIdentityKeys(row);
    if (uid > 0 && occupied.users.has(uid)) return true;
    if (ext && occupied.exts.has(ext)) return true;
    return false;
}

const STICKY_ROW_MS = 12000;

function rememberStickyRows(root, rows, nowMs = Date.now()) {
    if (!root._stickyRows) {
        root._stickyRows = Object.create(null);
    }
    (rows || []).forEach((row) => {
        const { id, uid, ext } = rowIdentityKeys(row);
        if (!id) return;
        const bucket = bucketFor(row);
        // Only pin statuses that briefly disappear between light/full polls.
        if (!['ringing', 'waiting', 'queue', 'incall_short', 'incall_long', 'disposition', 'not_in_call', 'idle', 'break', 'lunch'].includes(bucket)) {
            return;
        }
        root._stickyRows[id] = {
            row: { ...row },
            until: nowMs + STICKY_ROW_MS,
            uid,
            ext,
        };
    });

    Object.keys(root._stickyRows).forEach((id) => {
        if ((root._stickyRows[id]?.until || 0) < nowMs) {
            delete root._stickyRows[id];
        }
    });
}

function mergeStickyRows(root, liveRows, nowMs = Date.now()) {
    if (!root._stickyRows) {
        return liveRows;
    }
    const occupied = occupyKeySet(liveRows);
    const merged = [...liveRows];
    Object.values(root._stickyRows).forEach((entry) => {
        if (!entry || (entry.until || 0) < nowMs || !entry.row) {
            return;
        }
        const stickyId = String(entry.row.id || '');
        if (merged.some((row) => String(row.id || '') === stickyId)) {
            return;
        }
        if (isOccupied(entry.row, occupied)) {
            return;
        }
        merged.push(entry.row);
        const { uid, ext } = rowIdentityKeys(entry.row);
        if (uid > 0) occupied.users.add(uid);
        if (ext) occupied.exts.add(ext);
    });
    return merged;
}

function rowStructuralKey(row) {
    return [
        String(row?.id || ''),
        String(row?.user_id || ''),
        String(row?.station || row?.extension || ''),
        String(row?.user || ''),
        String(row?.role_label || ''),
        String(row?.status_group || ''),
        bucketFor(row),
        String(row?.connected_at || ''),
        String(row?.idle_since || ''),
        String(row?.break_ends_at || row?.ends_at || ''),
        String(row?.dial_mode || ''),
        String(row?.destination || ''),
        String(row?.campaign || ''),
    ].join('|');
}

function isAdminLikeRow(row) {
    const name = String(row?.user || '').trim().toLowerCase();
    const role = String(row?.role_label || '').trim().toLowerCase();
    const local = name.includes('@') ? name.split('@')[0] : name;
    const normalized = local.replace(/[^a-z0-9]+/g, '');
    if (['admin', 'superadmin', 'administrator', 'root'].includes(normalized)) {
        return true;
    }
    return ['admin', 'super admin', 'manager'].includes(role);
}

function fillTable(root, bucket, rows, nowMs = Date.now()) {
    const tbody = root.querySelector(`[data-call-monitoring-rows="${bucket}"]`);
    const board = root.querySelector(`[data-call-monitoring-board="${bucket}"]`);
    if (!tbody) {
        return;
    }

    const list = (Array.isArray(rows) ? rows : []).filter((row) => !isAdminLikeRow(row));
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

    const structureKey = list.map((row) => rowStructuralKey(row)).join('||');
    if (tbody.dataset.structureKey === structureKey) {
        if (list.length > 0) {
            // Same roster identity — only patch timers / status cells in place.
            const byId = new Map();
            tbody.querySelectorAll('.call-monitoring-row').forEach((el) => {
                const id = String(el.dataset.rowId || '');
                if (id) {
                    byId.set(id, el);
                }
            });
            list.forEach((row) => {
                const el = byId.get(String(row.id || ''));
                if (el) {
                    patchRow(el, row, nowMs);
                }
            });
        }
        return;
    }
    tbody.dataset.structureKey = structureKey;

    if (list.length === 0) {
        if (!tbody.querySelector('[data-call-monitoring-empty]')) {
            tbody.replaceChildren();
            const wrap = document.createElement('tbody');
            wrap.innerHTML = emptyRow(emptyMessageFor(bucket));
            const empty = wrap.firstElementChild;
            if (empty) {
                tbody.appendChild(empty);
            }
        }
    } else {
        // Keyed reconciliation: patch / reorder existing <tr>s instead of wiping innerHTML.
        tbody.querySelector('[data-call-monitoring-empty]')?.remove();
        const existingById = new Map();
        [...tbody.querySelectorAll('.call-monitoring-row')].forEach((el) => {
            const id = String(el.dataset.rowId || '');
            if (id) {
                existingById.set(id, el);
            } else {
                el.remove();
            }
        });

        const keep = new Set();
        const nextNodes = [];
        list.forEach((row) => {
            const id = String(row.id || '');
            if (!id) {
                return;
            }
            keep.add(id);
            let el = existingById.get(id);
            if (!el) {
                const wrap = document.createElement('tbody');
                wrap.innerHTML = buildRowHtml(row, nowMs);
                el = wrap.firstElementChild;
                if (!el) {
                    return;
                }
                existingById.set(id, el);
            } else {
                patchRow(el, row, nowMs);
            }
            nextNodes.push(el);
        });

        existingById.forEach((el, id) => {
            if (!keep.has(id)) {
                el.remove();
            }
        });

        // Only touch DOM order when it actually changed (avoids layout thrash / flicker).
        let orderChanged = nextNodes.length !== tbody.querySelectorAll('.call-monitoring-row').length;
        if (!orderChanged) {
            const current = [...tbody.querySelectorAll('.call-monitoring-row')];
            orderChanged = nextNodes.some((el, idx) => current[idx] !== el);
        }
        if (orderChanged) {
            const frag = document.createDocumentFragment();
            nextNodes.forEach((el) => frag.appendChild(el));
            tbody.appendChild(frag);
        }
    }

    const countEl = board?.querySelector('[data-board-count]');
    if (countEl) {
        countEl.textContent = String(list.length);
    }
    const countHead = board?.querySelector('[data-board-count-head]');
    if (countHead) {
        countHead.textContent = String(list.length);
    }
}

function rowStatusClasses() {
    return ['is-waiting', 'is-incall', 'is-incall-long', 'is-idle', 'is-break', 'is-lunch', 'is-offline', 'is-queue', 'is-dead', 'is-disposition'];
}

function applyRowColorClass(el, nextClass) {
    if (!el || el.classList.contains(nextClass)) {
        return;
    }
    el.classList.remove(...rowStatusClasses());
    el.classList.add(nextClass);
}

function remainingEndsInSec(row, nowMs = Date.now()) {
    const endsMs = parseConnectedAtMs(row.break_ends_at || row.ends_at);
    if (endsMs) {
        return Math.max(0, Math.floor((endsMs - nowMs) / 1000));
    }
    return Math.max(0, Math.floor(Number(row.remaining_sec) || 0));
}

function breakDestinationLabel(row, nowMs = Date.now()) {
    const remaining = remainingEndsInSec(row, nowMs);
    const kind = (row.bucket === 'lunch' || row.status_group === 'lunch') ? 'Lunch · 30 min' : 'Break · 5 min';
    return remaining > 0 ? `${kind} · Ends in ${formatTimer(remaining)}` : `${kind.split(' · ')[0]} · Ending…`;
}

function patchRow(el, row, nowMs = Date.now()) {
    if (!el || !row) {
        return;
    }

    const idle = row.bucket === 'not_in_call' || row.status_group === 'idle';
    const onBreak = row.bucket === 'break' || row.status_group === 'break'
        || row.bucket === 'lunch' || row.status_group === 'lunch';
    const disposition = row.bucket === 'disposition' || row.status_group === 'disposition';
    const offline = row.bucket === 'not_logged_in' || row.status_group === 'not_logged_in';
    const dead = row.bucket === 'dead' || row.status_group === 'dead';
    const connected = !idle && !onBreak && !disposition && !offline && !dead && (
        row.status_group === 'incall'
        || row.bucket === 'incall_short'
        || row.bucket === 'incall_long'
    );
    let timerSec = 0;
    if (connected) {
        timerSec = timerSecForRow(row, nowMs);
    } else if (idle || disposition || onBreak) {
        const idleMs = parseConnectedAtMs(row.idle_since);
        timerSec = idleMs
            ? Math.max(0, Math.floor((nowMs - idleMs) / 1000))
            : Math.max(0, Math.floor(Number(row.timer_sec) || 0));
    }
    const bucket = offline
        ? 'not_logged_in'
        : (idle
            ? 'not_in_call'
            : (onBreak
                ? (row.bucket === 'lunch' || row.status_group === 'lunch' ? 'lunch' : 'break')
                : (disposition
                    ? 'disposition'
                    : (dead ? 'dead' : (connected ? (timerSec > 120 ? 'incall_long' : 'incall_short') : bucketFor(row, timerSec))))));

    el.dataset.statusGroup = offline
        ? 'not_logged_in'
        : (idle
            ? 'idle'
            : (onBreak
                ? bucket
                : (disposition ? 'disposition' : (dead ? 'dead' : (connected ? 'incall' : (row.status_group || 'ringing'))))));
    el.dataset.bucket = bucket;
    el.dataset.timerSec = String(timerSec);
    if (connected && row.connected_at) {
        el.dataset.connectedAt = String(row.connected_at);
    } else {
        delete el.dataset.connectedAt;
    }
    if ((idle || disposition || onBreak) && row.idle_since) {
        el.dataset.idleSince = String(row.idle_since);
    } else {
        delete el.dataset.idleSince;
    }
    if (onBreak && (row.break_ends_at || row.ends_at)) {
        el.dataset.breakEndsAt = String(row.break_ends_at || row.ends_at);
    } else {
        delete el.dataset.breakEndsAt;
    }

    applyRowColorClass(el, rowClassFor(bucket));

    const timerEl = el.querySelector('[data-row-timer]');
    if (timerEl) {
        const nextTimer = (connected || idle || disposition || onBreak) ? formatTimer(timerSec) : '00:00';
        if (timerEl.textContent !== nextTimer) {
            timerEl.textContent = nextTimer;
        }
    }
    const pill = el.querySelector('.call-monitoring-status-pill');
    if (pill) {
        const nextStatus = row.status || statusLabelFor(bucket);
        if (pill.textContent !== nextStatus) {
            pill.textContent = nextStatus;
        }
    }
    const station = el.querySelector('.call-monitoring-row__station');
    if (station) {
        const nextStation = String(row.station || row.extension || '').trim();
        if (nextStation && nextStation !== '—' && station.textContent !== nextStation) {
            station.textContent = nextStation;
        }
    }
    const name = el.querySelector('.call-monitoring-row__name');
    if (name) {
        const nextName = String(row.user || '—').trim() || '—';
        if (name.textContent !== nextName) {
            name.textContent = nextName;
        }
        if (name.getAttribute('title') !== nextName) {
            name.setAttribute('title', nextName);
        }
    }
    const userCell = el.querySelector('.call-monitoring-row__user');
    const userInner = el.querySelector('.call-monitoring-row__user-inner') || userCell;
    const roleLabel = String(row.role_label || '').trim();
    let roleEl = el.querySelector('.call-monitoring-row__role');
    if (roleLabel) {
        if (!roleEl && userInner) {
            roleEl = document.createElement('span');
            roleEl.className = 'call-monitoring-row__role';
            userInner.appendChild(roleEl);
        }
        if (roleEl) {
            roleEl.textContent = roleLabel;
            roleEl.hidden = false;
        }
    }
    const dest = el.querySelector('.call-monitoring-row__dest');
    if (dest) {
        const nextDest = onBreak
            ? escapeHtml(breakDestinationLabel(row, nowMs))
            : destinationCellHtml(row, { idle });
        if (dest.innerHTML !== nextDest) {
            dest.innerHTML = nextDest;
        }
    }
    const campaign = el.querySelector('.call-monitoring-row__campaign');
    if (campaign) {
        const nextCampaign = campaignCellHtml(row, { idle: idle || disposition, onBreak });
        if (campaign.innerHTML !== nextCampaign) {
            campaign.innerHTML = nextCampaign;
        }
    }
    const dialMeta = dialModeMeta(row, { idle });
    if (dialMeta.mode) {
        el.dataset.dialMode = dialMeta.mode;
    } else {
        delete el.dataset.dialMode;
    }
}

function sortUnifiedRows(rows) {
    const rank = {
        not_in_call: 0,
        idle: 0,
        break: 1,
        lunch: 2,
        ringing: 3,
        waiting: 3,
        queue: 3,
        incall_short: 4,
        incall_long: 5,
        disposition: 6,
        not_logged_in: 7,
        dead: 8,
    };

    return [...rows].sort((a, b) => {
        const bucketA = bucketFor(a);
        const bucketB = bucketFor(b);
        const ra = rank[bucketA] ?? 9;
        const rb = rank[bucketB] ?? 9;
        if (ra !== rb) {
            return ra - rb;
        }

        // Stable within-bucket order: name first, then id — avoids timer-driven reshuffles / flicker.
        const nameCmp = String(a.user || '').localeCompare(String(b.user || ''), undefined, { sensitivity: 'base' });
        if (nameCmp !== 0) {
            return nameCmp;
        }
        return String(a.id || '').localeCompare(String(b.id || ''));
    });
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
    const allRows = (Array.isArray(payload.rows) ? payload.rows : []).filter((row) => !isAdminLikeRow(row));
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

    const ringing = (tables.ringing
        || enriched.filter((row) => bucketFor(row) === 'ringing')).filter((row) => !isAdminLikeRow(row));
    const shortRows = (tables.incall_short || enriched.filter((row) => bucketFor(row) === 'incall_short'))
        .filter((row) => !isAdminLikeRow(row))
        .map((row) => ({ ...row, timer_sec: timerSecForRow(row, nowMs) }))
        .filter((row) => timerSecForRow(row, nowMs) <= 120);
    const longRows = (tables.incall_long || enriched.filter((row) => bucketFor(row) === 'incall_long'))
        .filter((row) => !isAdminLikeRow(row))
        .map((row) => ({ ...row, timer_sec: timerSecForRow(row, nowMs) }))
        .filter((row) => timerSecForRow(row, nowMs) > 120);

    const allConnected = [...shortRows, ...longRows];
    const shortFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) <= 120);
    const longFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) > 120);

    const notInCallRaw = (Array.isArray(tables.not_in_call) ? tables.not_in_call : []).filter((row) => !isAdminLikeRow(row));
    const notLoggedInRaw = (Array.isArray(tables.not_logged_in) ? tables.not_logged_in : []).filter((row) => !isAdminLikeRow(row));
    const deadRows = (Array.isArray(tables.dead)
        ? tables.dead
        : enriched.filter((row) => bucketFor(row) === 'dead')).filter((row) => !isAdminLikeRow(row));
    const dispositionRaw = (Array.isArray(tables.disposition)
        ? tables.disposition
        : enriched.filter((row) => bucketFor(row) === 'disposition')).filter((row) => !isAdminLikeRow(row));
    const breakRaw = (Array.isArray(tables.break)
        ? tables.break
        : enriched.filter((row) => bucketFor(row) === 'break')).filter((row) => !isAdminLikeRow(row));
    const lunchRaw = (Array.isArray(tables.lunch)
        ? tables.lunch
        : enriched.filter((row) => bucketFor(row) === 'lunch')).filter((row) => !isAdminLikeRow(row));
    const queueRows = (Array.isArray(tables.queue)
        ? tables.queue
        : enriched.filter((row) => bucketFor(row) === 'queue')).filter((row) => !isAdminLikeRow(row));

    // Pin live / idle / break rows briefly so light polls cannot flash them to NOT LOGGED IN.
    rememberStickyRows(root, [...shortFinal, ...longFinal, ...ringing, ...queueRows, ...dispositionRaw, ...breakRaw, ...lunchRaw, ...notInCallRaw], nowMs);
    const stickyLive = mergeStickyRows(
        root,
        [...shortFinal, ...longFinal, ...ringing, ...queueRows, ...dispositionRaw, ...breakRaw, ...lunchRaw, ...notInCallRaw],
        nowMs,
    );
    const stickyShort = stickyLive.filter((row) => bucketFor(row, timerSecForRow(row, nowMs)) === 'incall_short');
    const stickyLong = stickyLive.filter((row) => bucketFor(row, timerSecForRow(row, nowMs)) === 'incall_long');
    const stickyRinging = stickyLive.filter((row) => {
        const bucket = bucketFor(row);
        return bucket === 'ringing' || bucket === 'waiting' || bucket === 'queue';
    });
    const stickyDisposition = stickyLive.filter((row) => bucketFor(row) === 'disposition');
    const stickyBreak = stickyLive.filter((row) => bucketFor(row) === 'break');
    const stickyLunch = stickyLive.filter((row) => bucketFor(row) === 'lunch');
    const stickyNotInCall = stickyLive.filter((row) => {
        const bucket = bucketFor(row);
        return bucket === 'not_in_call' || bucket === 'idle';
    });

    const occupied = occupyKeySet([
        ...stickyShort,
        ...stickyLong,
        ...stickyRinging,
        ...stickyDisposition,
        ...stickyBreak,
        ...stickyLunch,
        ...stickyNotInCall,
    ]);
    const notLoggedIn = notLoggedInRaw.filter((row) => !isOccupied(row, occupied));

    const unified = sortUnifiedRows([
        ...stickyNotInCall,
        ...stickyBreak,
        ...stickyLunch,
        ...stickyRinging,
        ...stickyShort,
        ...stickyLong,
        ...stickyDisposition,
        ...notLoggedIn,
        ...deadRows,
    ]);

    if (root.querySelector('[data-call-monitoring-rows="all"]')) {
        fillTable(root, 'all', unified, nowMs);
    } else {
        fillTable(root, 'ringing', stickyRinging, nowMs);
        fillTable(root, 'incall_short', stickyShort, nowMs);
        fillTable(root, 'incall_long', stickyLong, nowMs);
        fillTable(root, 'dead', deadRows, nowMs);
        fillTable(root, 'disposition', stickyDisposition, nowMs);
        fillTable(root, 'break', stickyBreak, nowMs);
        fillTable(root, 'lunch', stickyLunch, nowMs);
        fillTable(root, 'not_in_call', stickyNotInCall, nowMs);
        fillTable(root, 'not_logged_in', notLoggedIn, nowMs);
    }

    const setStat = (key, value) => {
        root.querySelectorAll(`[data-stat="${key}"]`).forEach((el) => {
            el.textContent = String(value);
        });
    };
    setStat('in_call_short', stickyShort.length);
    setStat('in_call_long', stickyLong.length);
    setStat('in_call', stickyShort.length + stickyLong.length);
    setStat('not_in_call', stickyNotInCall.length);
    setStat('dead', deadRows.length);
    setStat('disposition', stickyDisposition.length);
    setStat('break', stickyBreak.length);
    setStat('lunch', stickyLunch.length);
    setStat(
        'logged_in',
        stickyShort.length
            + stickyLong.length
            + stickyRinging.length
            + stickyDisposition.length
            + stickyBreak.length
            + stickyLunch.length
            + stickyNotInCall.length
    );
    setStat('not_logged_in', notLoggedIn.length);
    setStat('ringing', stickyRinging.length);
    setStat('total', Number(summary.total ?? unified.length));

    syncSidebarFromBoard(root);
}

function rebucketConnectedRows(root) {
    if (root?.dataset?.callMonitoringUnified === '1' || root?.querySelector('[data-call-monitoring-rows="all"]')) {
        root.querySelectorAll('.call-monitoring-row[data-status-group="incall"]').forEach((row) => {
            const sec = Number(row.dataset.timerSec) || 0;
            const bucket = sec > 120 ? 'incall_long' : 'incall_short';
            row.dataset.bucket = bucket;
            applyRowColorClass(row, rowClassFor(bucket));
            const pill = row.querySelector('.call-monitoring-status-pill');
            if (pill) {
                pill.textContent = statusLabelFor(bucket);
            }
        });
        const shortCount = root.querySelectorAll('.call-monitoring-row[data-bucket="incall_short"]').length;
        const longCount = root.querySelectorAll('.call-monitoring-row[data-bucket="incall_long"]').length;
        root.querySelectorAll('[data-stat="in_call_short"]').forEach((el) => { el.textContent = String(shortCount); });
        root.querySelectorAll('[data-stat="in_call_long"]').forEach((el) => { el.textContent = String(longCount); });
        root.querySelectorAll('[data-stat="in_call"]').forEach((el) => { el.textContent = String(shortCount + longCount); });
        return;
    }

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
            ? Math.max(0, Math.floor((nowMs - connectedAtMs) / 1000))
            : (Number(row.dataset.timerSec) || 0) + 1;
        row.dataset.timerSec = String(next);
        const timerEl = row.querySelector('[data-row-timer]');
        if (timerEl) {
            const label = formatTimer(next);
            if (timerEl.textContent !== label) {
                timerEl.textContent = label;
            }
        }
        const bucket = next > 120 ? 'incall_long' : 'incall_short';
        row.dataset.bucket = bucket;
        applyRowColorClass(row, rowClassFor(bucket));
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill) {
            const label = statusLabelFor(bucket);
            if (pill.textContent !== label) {
                pill.textContent = label;
            }
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
            const label = formatTimer(next);
            if (timerEl.textContent !== label) {
                timerEl.textContent = label;
            }
        }
        row.dataset.bucket = 'not_in_call';
        applyRowColorClass(row, 'is-idle');
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill && pill.textContent !== 'NOT IN CALL') {
            pill.textContent = 'NOT IN CALL';
        }
    });
    root.querySelectorAll('.call-monitoring-row[data-status-group="disposition"], .call-monitoring-row[data-bucket="disposition"]').forEach((row) => {
        const sinceMs = parseConnectedAtMs(row.dataset.idleSince);
        const next = sinceMs
            ? Math.max(0, Math.floor((nowMs - sinceMs) / 1000))
            : (Number(row.dataset.timerSec) || 0) + 1;
        row.dataset.timerSec = String(next);
        const timerEl = row.querySelector('[data-row-timer]');
        if (timerEl) {
            const label = formatTimer(next);
            if (timerEl.textContent !== label) {
                timerEl.textContent = label;
            }
        }
        row.dataset.bucket = 'disposition';
        applyRowColorClass(row, 'is-disposition');
        const pill = row.querySelector('.call-monitoring-status-pill');
        if (pill && pill.textContent !== 'DISPOSITION') {
            pill.textContent = 'DISPOSITION';
        }
    });
    // Break / lunch: tick elapsed + "Ends in" without rewriting the whole row (stops flicker).
    root.querySelectorAll('.call-monitoring-row[data-status-group="break"], .call-monitoring-row[data-status-group="lunch"], .call-monitoring-row[data-bucket="break"], .call-monitoring-row[data-bucket="lunch"]').forEach((row) => {
        const bucket = row.dataset.bucket === 'lunch' || row.dataset.statusGroup === 'lunch' ? 'lunch' : 'break';
        const sinceMs = parseConnectedAtMs(row.dataset.idleSince);
        const next = sinceMs
            ? Math.max(0, Math.floor((nowMs - sinceMs) / 1000))
            : (Number(row.dataset.timerSec) || 0) + 1;
        row.dataset.timerSec = String(next);
        row.dataset.bucket = bucket;
        row.dataset.statusGroup = bucket;
        applyRowColorClass(row, rowClassFor(bucket));
        const timerEl = row.querySelector('[data-row-timer]');
        if (timerEl) {
            const label = formatTimer(next);
            if (timerEl.textContent !== label) {
                timerEl.textContent = label;
            }
        }
        const pill = row.querySelector('.call-monitoring-status-pill');
        const statusLabel = statusLabelFor(bucket);
        if (pill && pill.textContent !== statusLabel) {
            pill.textContent = statusLabel;
        }
        const dest = row.querySelector('.call-monitoring-row__dest');
        if (dest) {
            const endsMs = parseConnectedAtMs(row.dataset.breakEndsAt);
            const remaining = endsMs
                ? Math.max(0, Math.floor((endsMs - nowMs) / 1000))
                : 0;
            const kind = bucket === 'lunch' ? 'Lunch · 30 min' : 'Break · 5 min';
            const nextDest = remaining > 0
                ? `${kind} · Ends in ${formatTimer(remaining)}`
                : `${bucket === 'lunch' ? 'Lunch' : 'Break'} · Ending…`;
            if (dest.textContent !== nextDest) {
                dest.textContent = nextDest;
            }
        }
        const campaign = row.querySelector('.call-monitoring-row__campaign');
        if (campaign && campaign.textContent !== '—') {
            campaign.textContent = '—';
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
    const scope = root.querySelector('[data-call-monitoring-rows="all"]') || root;
    const shortCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="incall_short"]').length;
    const longCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="incall_long"]').length;
    const ringingCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="ringing"]').length;
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
 * Call Monitoring wallboard — WebSocket-first realtime + SSE fallback.
 * Timers tick locally every 1s from connected_at (matches dialer).
 */
const BOARD_POLL_ACTIVE_MS = 15000;
const BOARD_POLL_IDLE_MS = 60000;
const BOARD_POLL_SSE_MS = 120000;
const BOARD_FULL_EVERY = 20;
const NAV_POLL_MS = 120000;
const WS_REFRESH_DEBOUNCE_MS = 120;
const WS_REFRESH_MIN_GAP_MS = 250;

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
            const sticky = board._stickyRows;
            if (sticky && rowId && sticky[rowId]) {
                delete sticky[rowId];
            }
            row.remove();
            removed = true;
        }
    });

    if (!removed) {
        return false;
    }

    const unifiedBody = board.querySelector('[data-call-monitoring-rows="all"]');
    if (unifiedBody) {
        if (!unifiedBody.querySelector('.call-monitoring-row')) {
            unifiedBody.innerHTML = emptyRow(emptyMessageFor('all'));
        }
        const countEl = board.querySelector('[data-call-monitoring-board="all"] [data-board-count], [data-board-count]');
        if (countEl) {
            countEl.textContent = String(unifiedBody.querySelectorAll('.call-monitoring-row').length);
        }
    } else {
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
    }

    const scope = unifiedBody || board;
    const shortCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="incall_short"]').length;
    const longCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="incall_long"]').length;
    const ringingCount = scope.querySelectorAll('.call-monitoring-row[data-bucket="ringing"]').length;
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
    window.clearTimeout(monitoringRuntime.wsRefreshTimer);
    monitoringRuntime.abortController?.abort();
    if (typeof monitoringRuntime.streamGeneration === 'number') {
        monitoringRuntime.streamGeneration += 1;
    }
    try {
        monitoringRuntime.eventSource?.close();
    } catch {
        // ignore
    }
    try {
        monitoringRuntime.webSocket?.close();
    } catch {
        // ignore
    }
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

    // Communications dialer: zero /live polling — sidebar chips stay idle here.
    const onDialerPage = Boolean(document.querySelector('[data-phone-workspace], [data-auto-dial-hub]'));
    if (onDialerPage && !board) {
        stopMonitoringRuntime();
        document.documentElement.dataset.callMonitoringInit = '0';

        return;
    }

    const pollUrl = board?.dataset.callMonitoringPollUrl
        || navLinks[0]?.dataset.callMonitoringPollUrl
        || '';
    const streamUrl = board?.dataset.callMonitoringStreamUrl
        || navLinks[0]?.dataset.callMonitoringStreamUrl
        || '';
    const wsUrlBase = board?.dataset.callMonitoringWsUrl
        || navLinks[0]?.dataset.callMonitoringWsUrl
        || '';
    const workspaceId = String(
        board?.dataset.callMonitoringWorkspaceId
        || navLinks[0]?.dataset.callMonitoringWorkspaceId
        || '',
    ).trim();

    const navOnly = !board && navLinks.length > 0;

    // Skip re-init when the same wallboard/nav is already polling — a second
    // DOMContentLoaded+turbo:load boot was aborting /live as "(canceled)".
    if (
        document.documentElement.dataset.callMonitoringInit === '1'
        && monitoringRuntime
        && monitoringRuntime.pollUrl === pollUrl
        && monitoringRuntime.navOnly === navOnly
        && (navOnly ? document.contains(navLinks[0]) : document.contains(board))
    ) {
        return;
    }

    // Re-bind after Turbo navigations.
    stopMonitoringRuntime();
    document.documentElement.dataset.callMonitoringInit = '1';

    let pollTimer = null;
    let tickTimer = null;
    let fetching = false;
    let pollCount = 0;
    let abortController = null;
    let eventSource = null;
    let webSocket = null;
    let wsRefreshTimer = null;
    let lastWsRefreshAt = 0;
    let streamGeneration = 0;
    let lastAppliedPresenceVersion = -1;
    let lastAppliedVersion = -1;

    const wsConnected = () => Boolean(webSocket && webSocket.readyState === WebSocket.OPEN);

    const handlePayload = (payload) => {
        if (!payload || payload.ok === false) {
            return;
        }
        const nextVersion = Number(payload.version ?? -1);
        const nextPresence = Number(payload.presence_version ?? -1);
        if (
            nextPresence >= 0
            && lastAppliedPresenceVersion >= 0
            && nextPresence < lastAppliedPresenceVersion
            && nextVersion <= lastAppliedVersion
        ) {
            return;
        }
        if (nextPresence >= 0) {
            lastAppliedPresenceVersion = Math.max(lastAppliedPresenceVersion, nextPresence);
        }
        if (nextVersion >= 0) {
            lastAppliedVersion = Math.max(lastAppliedVersion, nextVersion);
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

    const requestWsRefresh = ({ full = false, force = false } = {}) => {
        const now = Date.now();
        if (!force && now - lastWsRefreshAt < WS_REFRESH_MIN_GAP_MS) {
            window.clearTimeout(wsRefreshTimer);
            wsRefreshTimer = window.setTimeout(() => {
                requestWsRefresh({ full, force: true });
            }, WS_REFRESH_DEBOUNCE_MS);
            if (monitoringRuntime) {
                monitoringRuntime.wsRefreshTimer = wsRefreshTimer;
            }
            return;
        }
        lastWsRefreshAt = now;
        void poll({ full });
    };

    const reschedulePoll = () => {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
        if (navOnly) {
            if (monitoringRuntime) {
                monitoringRuntime.pollTimer = null;
            }

            return;
        }
        // WebSocket or SSE live → no HTTP poll loop.
        if (wsConnected() || (eventSource && eventSource.readyState === 1)) {
            if (monitoringRuntime) {
                monitoringRuntime.pollTimer = null;
            }

            return;
        }
        const delay = boardHasLiveRows(board) ? BOARD_POLL_ACTIVE_MS : BOARD_POLL_IDLE_MS;
        pollTimer = window.setInterval(scheduleBoardPoll, delay);
        if (monitoringRuntime) {
            monitoringRuntime.pollTimer = pollTimer;
        }
    };

    const scheduleBoardPoll = () => {
        void poll({ full: pollCount === 0 || (pollCount % BOARD_FULL_EVERY) === 0 }).finally(() => {
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
        if (!wsConnected() && (!eventSource || eventSource.readyState === 2)) {
            connectRealtime();
        }
    };

    const onHangup = (event) => {
        const detail = event?.detail || event?.data || {};
        if (detail?.type && detail.type !== 'call-ended') {
            return;
        }
        // Instant local clear only — NEVER re-fetch /live on hangup (was doubling Network rows).
        if (board) {
            removeEndedCallsFromBoard(board, detail);
        }
        // WebSocket will push monitoring_refresh; soft refresh shortly if WS quiet.
        if (wsConnected()) {
            requestWsRefresh({ full: false });
        }
    };

    const connectWebSocket = () => {
        if (navOnly || !wsUrlBase || typeof WebSocket === 'undefined') {
            return false;
        }
        const generation = streamGeneration;
        let url = wsUrlBase;
        try {
            const parsed = new URL(wsUrlBase, window.location.origin);
            parsed.searchParams.set('channel', 'monitoring');
            if (workspaceId) {
                parsed.searchParams.set('workspace_id', workspaceId);
            }
            url = parsed.toString();
        } catch {
            const join = wsUrlBase.includes('?') ? '&' : '?';
            url = `${wsUrlBase}${join}channel=monitoring${workspaceId ? `&workspace_id=${encodeURIComponent(workspaceId)}` : ''}`;
        }

        try {
            webSocket?.close();
        } catch {
            // ignore
        }

        try {
            webSocket = new WebSocket(url);
        } catch (error) {
            console.warn('[call-monitoring] websocket open failed', error);
            webSocket = null;
            return false;
        }

        webSocket.onopen = () => {
            if (generation !== streamGeneration) {
                return;
            }
            if (monitoringRuntime) {
                monitoringRuntime.webSocket = webSocket;
            }
            // Immediate board sync once the socket is up.
            requestWsRefresh({ full: false, force: true });
            reschedulePoll();
        };

        webSocket.onmessage = (event) => {
            if (generation !== streamGeneration) {
                return;
            }
            try {
                const payload = JSON.parse(event.data || '{}');
                const type = String(payload.type || '');
                if (type === 'monitoring_hello' || type === 'monitoring_refresh' || payload.reason) {
                    requestWsRefresh({ full: Boolean(payload.live === false) });
                    return;
                }
                // Full snapshot pushed (future-proof).
                if (payload.summary || payload.rows || payload.tables) {
                    handlePayload(payload);
                    reschedulePoll();
                }
            } catch (error) {
                console.warn('[call-monitoring] websocket parse failed', error);
            }
        };

        webSocket.onerror = () => {
            // onclose handles reconnect / SSE fallback
        };

        webSocket.onclose = () => {
            if (generation !== streamGeneration) {
                return;
            }
            webSocket = null;
            if (monitoringRuntime) {
                monitoringRuntime.webSocket = null;
            }
            // Prefer HTTP poll over PHP SSE — SSE was pinning all php-fpm workers → site-wide 504s.
            reschedulePoll();
            const attempt = Number(monitoringRuntime?.wsReconnectAttempts || 0) + 1;
            if (monitoringRuntime) {
                monitoringRuntime.wsReconnectAttempts = attempt;
            }
            const delay = Math.min(15000, 1500 * attempt);
            window.setTimeout(() => {
                if (document.documentElement.dataset.callMonitoringInit !== '1'
                    || generation !== streamGeneration
                    || document.hidden
                    || wsConnected()) {
                    return;
                }
                if (connectWebSocket()) {
                    if (monitoringRuntime) {
                        monitoringRuntime.wsReconnectAttempts = 0;
                    }
                    reschedulePoll();

                    return;
                }
                // SSE only after repeated WS failures (last resort).
                if (attempt >= 3) {
                    connectStream();
                }
                reschedulePoll();
            }, delay);
        };

        if (monitoringRuntime) {
            monitoringRuntime.webSocket = webSocket;
        }
        return true;
    };

    const connectStream = () => {
        if (navOnly || !streamUrl || typeof EventSource === 'undefined') {
            return;
        }
        // Prefer WebSocket — skip SSE while WS is healthy.
        if (wsConnected()) {
            return;
        }
        const generation = streamGeneration;
        try {
            eventSource?.close();
        } catch {
            // ignore
        }
        eventSource = new EventSource(streamUrl, { withCredentials: true });
        eventSource.onmessage = (event) => {
            if (generation !== streamGeneration) {
                return;
            }
            try {
                const payload = JSON.parse(event.data);
                handlePayload(payload);
                reschedulePoll();
            } catch (error) {
                console.warn('[call-monitoring] stream parse failed', error);
            }
        };
        eventSource.onerror = () => {
            if (generation !== streamGeneration) {
                return;
            }
            try {
                eventSource?.close();
            } catch {
                // ignore
            }
            eventSource = null;
            if (monitoringRuntime) {
                monitoringRuntime.eventSource = null;
            }
            window.setTimeout(() => {
                if (document.documentElement.dataset.callMonitoringInit === '1'
                    && generation === streamGeneration
                    && !document.hidden
                    && !wsConnected()) {
                    connectStream();
                }
            }, 3000);
            reschedulePoll();
        };
        if (monitoringRuntime) {
            monitoringRuntime.eventSource = eventSource;
        }
    };

    const connectRealtime = () => {
        streamGeneration += 1;
        if (monitoringRuntime) {
            monitoringRuntime.streamGeneration = streamGeneration;
            monitoringRuntime.wsReconnectAttempts = 0;
        }
        // Prefer WebSocket. If unavailable, use short HTTP polls — avoid opening PHP SSE by default.
        if (!connectWebSocket()) {
            reschedulePoll();
        }
    };

    if (navOnly) {
        // Sidebar badges: ONE /live snapshot — no interval polling.
        void poll({ full: false });
        monitoringRuntime = {
            pollTimer: null,
            tickTimer: null,
            abortController,
            eventSource: null,
            webSocket: null,
            wsRefreshTimer: null,
            onVisibility: null,
            onHangup: null,
            broadcast: null,
            pollUrl,
            navOnly,
            streamGeneration,
        };
        document.addEventListener('turbo:before-cache', stopMonitoringRuntime, { once: true });

        return;
    }

    // Wallboard: ONE /live boot + WebSocket (SSE fallback).
    monitoringRuntime = {
        pollTimer: null,
        tickTimer: null,
        abortController,
        eventSource: null,
        webSocket: null,
        wsRefreshTimer: null,
        onVisibility,
        onHangup,
        broadcast: null,
        pollUrl,
        navOnly,
        streamGeneration,
    };
    scheduleBoardPoll();
    connectRealtime();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('comm:monitoring-hangup', onHangup);
    window.addEventListener('comm:call-ended', onHangup);

    tickTimer = window.setInterval(() => {
        if (board) {
            tickTimers(board);
        }
    }, 1000);
    monitoringRuntime.tickTimer = tickTimer;

    try {
        const broadcast = new BroadcastChannel('apex-call-monitoring');
        broadcast.onmessage = (event) => onHangup(event);
        monitoringRuntime.broadcast = broadcast;
    } catch {
        // ignore
    }

    document.addEventListener('turbo:before-cache', stopMonitoringRuntime, { once: true });
}

export function teardownCallMonitoring() {
    stopMonitoringRuntime();
}
