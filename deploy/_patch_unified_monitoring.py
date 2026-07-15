from pathlib import Path

path = Path(r"c:\Users\dev\Desktop\ApexonecommandCenter\resources\js\call-monitoring.js")
text = path.read_text(encoding="utf-8")

start = text.index("function applySnapshot(root, payload) {")
end = text.index("function rebucketConnectedRows(root) {")
new_block = r'''function sortUnifiedRows(rows) {
    const rank = {
        incall_long: 0,
        incall_short: 1,
        ringing: 2,
        waiting: 2,
        queue: 3,
        disposition: 4,
        dead: 5,
        not_in_call: 6,
        idle: 6,
    };

    return [...rows].sort((a, b) => {
        const bucketA = bucketFor(a);
        const bucketB = bucketFor(b);
        const ra = rank[bucketA] ?? 9;
        const rb = rank[bucketB] ?? 9;
        if (ra !== rb) {
            return ra - rb;
        }

        return (Number(b.timer_sec) || 0) - (Number(a.timer_sec) || 0);
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

    const allConnected = [...shortRows, ...longRows];
    const shortFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) <= 120);
    const longFinal = allConnected.filter((row) => timerSecForRow(row, nowMs) > 120);

    const notInCall = Array.isArray(tables.not_in_call) ? tables.not_in_call : [];
    const deadRows = Array.isArray(tables.dead)
        ? tables.dead
        : enriched.filter((row) => bucketFor(row) === 'dead');
    const dispositionRows = Array.isArray(tables.disposition)
        ? tables.disposition
        : enriched.filter((row) => bucketFor(row) === 'disposition');
    const queueRows = Array.isArray(tables.queue)
        ? tables.queue
        : enriched.filter((row) => bucketFor(row) === 'queue');

    const unified = sortUnifiedRows([
        ...longFinal,
        ...shortFinal,
        ...ringing,
        ...queueRows,
        ...dispositionRows,
        ...deadRows,
        ...notInCall,
    ]);

    if (root.querySelector('[data-call-monitoring-rows="all"]')) {
        fillTable(root, 'all', unified, nowMs);
    } else {
        fillTable(root, 'ringing', ringing, nowMs);
        fillTable(root, 'incall_short', shortFinal, nowMs);
        fillTable(root, 'incall_long', longFinal, nowMs);
        fillTable(root, 'dead', deadRows, nowMs);
        fillTable(root, 'disposition', dispositionRows, nowMs);
        fillTable(root, 'not_in_call', notInCall, nowMs);
    }

    const shortStat = root.querySelector('[data-stat="in_call_short"]');
    const longStat = root.querySelector('[data-stat="in_call_long"]');
    const totalStat = root.querySelector('[data-stat="in_call"]');
    const idleStat = root.querySelector('[data-stat="not_in_call"]');
    const deadStat = root.querySelector('[data-stat="dead"]');
    const dispositionStat = root.querySelector('[data-stat="disposition"]');
    const agentsStat = root.querySelector('[data-stat="total"]');
    if (shortStat) shortStat.textContent = String(shortFinal.length);
    if (longStat) longStat.textContent = String(longFinal.length);
    if (totalStat) totalStat.textContent = String(shortFinal.length + longFinal.length);
    if (idleStat) idleStat.textContent = String(notInCall.length);
    if (deadStat) deadStat.textContent = String(deadRows.length);
    if (dispositionStat) dispositionStat.textContent = String(dispositionRows.length);
    if (agentsStat) {
        agentsStat.textContent = String(Number(summary.total ?? unified.length));
    }

    syncSidebarFromBoard(root);
}

'''
text = text[:start] + new_block + text[end:]

text = text.replace(
    "if (bucket === 'not_in_call') return 'No logged-in agents available (not in call).';\n    return 'No calls.';",
    "if (bucket === 'not_in_call') return 'No logged-in agents available (not in call).';\n    if (bucket === 'all') return 'No agents or calls to show right now.';\n    return 'No calls.';",
)

old_sync = """function syncSidebarFromBoard(root) {
    if (!root) {
        return;
    }
    const shortCount = root.querySelectorAll('[data-call-monitoring-rows=\"incall_short\"] .call-monitoring-row').length;
    const longCount = root.querySelectorAll('[data-call-monitoring-rows=\"incall_long\"] .call-monitoring-row').length;
    const ringingCount = root.querySelectorAll('[data-call-monitoring-rows=\"ringing\"] .call-monitoring-row').length;
    updateSidebarBadges({
        summary: {
            in_call: shortCount + longCount,
            ringing: ringingCount,
            waiting: ringingCount,
        },
    });
}"""

new_sync = """function syncSidebarFromBoard(root) {
    if (!root) {
        return;
    }
    const scope = root.querySelector('[data-call-monitoring-rows=\"all\"]') || root;
    const shortCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_short\"]').length;
    const longCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_long\"]').length;
    const ringingCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"ringing\"]').length;
    updateSidebarBadges({
        summary: {
            in_call: shortCount + longCount,
            ringing: ringingCount,
            waiting: ringingCount,
        },
    });
}"""

if old_sync not in text:
    raise SystemExit("syncSidebarFromBoard not found")
text = text.replace(old_sync, new_sync, 1)

marker = "function rebucketConnectedRows(root) {\n    const shortBody = root.querySelector('[data-call-monitoring-rows=\"incall_short\"]');"
replacement = """function rebucketConnectedRows(root) {
    if (root?.dataset?.callMonitoringUnified === '1' || root?.querySelector('[data-call-monitoring-rows=\"all\"]')) {
        root.querySelectorAll('.call-monitoring-row[data-status-group=\"incall\"]').forEach((row) => {
            const sec = Number(row.dataset.timerSec) || 0;
            const bucket = sec > 120 ? 'incall_long' : 'incall_short';
            row.dataset.bucket = bucket;
            row.classList.remove('is-waiting', 'is-incall', 'is-incall-long', 'is-idle', 'is-queue', 'is-dead', 'is-disposition');
            row.classList.add(rowClassFor(bucket));
            const pill = row.querySelector('.call-monitoring-status-pill');
            if (pill) {
                pill.textContent = statusLabelFor(bucket);
            }
        });
        const shortCount = root.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_short\"]').length;
        const longCount = root.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_long\"]').length;
        const shortStat = root.querySelector('[data-stat=\"in_call_short\"]');
        const longStat = root.querySelector('[data-stat=\"in_call_long\"]');
        const totalStat = root.querySelector('[data-stat=\"in_call\"]');
        if (shortStat) shortStat.textContent = String(shortCount);
        if (longStat) longStat.textContent = String(longCount);
        if (totalStat) totalStat.textContent = String(shortCount + longCount);
        return;
    }

    const shortBody = root.querySelector('[data-call-monitoring-rows=\"incall_short\"]');"""

if marker not in text:
    raise SystemExit("rebucket marker not found")
text = text.replace(marker, replacement, 1)

old_remove = """    ['ringing', 'incall_short', 'incall_long'].forEach((bucket) => {
        const tbody = board.querySelector(`[data-call-monitoring-rows=\"${bucket}\"]`);
        if (!tbody) {
            return;
        }
        if (!tbody.querySelector('.call-monitoring-row')) {
            tbody.innerHTML = emptyRow(emptyMessageFor(bucket));
        }
        const countEl = board.querySelector(`[data-call-monitoring-board=\"${bucket}\"] [data-board-count]`);
        if (countEl) {
            countEl.textContent = String(tbody.querySelectorAll('.call-monitoring-row').length);
        }
    });

    const shortCount = board.querySelectorAll('[data-call-monitoring-rows=\"incall_short\"] .call-monitoring-row').length;
    const longCount = board.querySelectorAll('[data-call-monitoring-rows=\"incall_long\"] .call-monitoring-row').length;
    const ringingCount = board.querySelectorAll('[data-call-monitoring-rows=\"ringing\"] .call-monitoring-row').length;"""

new_remove = """    const unifiedBody = board.querySelector('[data-call-monitoring-rows=\"all\"]');
    if (unifiedBody) {
        if (!unifiedBody.querySelector('.call-monitoring-row')) {
            unifiedBody.innerHTML = emptyRow(emptyMessageFor('all'));
        }
        const countEl = board.querySelector('[data-call-monitoring-board=\"all\"] [data-board-count], [data-board-count]');
        if (countEl) {
            countEl.textContent = String(unifiedBody.querySelectorAll('.call-monitoring-row').length);
        }
    } else {
        ['ringing', 'incall_short', 'incall_long'].forEach((bucket) => {
            const tbody = board.querySelector(`[data-call-monitoring-rows=\"${bucket}\"]`);
            if (!tbody) {
                return;
            }
            if (!tbody.querySelector('.call-monitoring-row')) {
                tbody.innerHTML = emptyRow(emptyMessageFor(bucket));
            }
            const countEl = board.querySelector(`[data-call-monitoring-board=\"${bucket}\"] [data-board-count]`);
            if (countEl) {
                countEl.textContent = String(tbody.querySelectorAll('.call-monitoring-row').length);
            }
        });
    }

    const scope = unifiedBody || board;
    const shortCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_short\"]').length;
    const longCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"incall_long\"]').length;
    const ringingCount = scope.querySelectorAll('.call-monitoring-row[data-bucket=\"ringing\"]').length;"""

if old_remove not in text:
    raise SystemExit("removeEndedCalls block not found")
text = text.replace(old_remove, new_remove, 1)

path.write_text(text, encoding="utf-8")
print("OK")
print("sortUnifiedRows", "sortUnifiedRows" in text)
print("fill all", 'fillTable(root, \'all\'' in text)
