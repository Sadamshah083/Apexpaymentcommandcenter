import { placeOutboundCall, clearDialerDestinationInput } from './communications-dialer.js';
import { getWebphone } from './communications-webphone.js';
import { showToast, dismissAllToasts } from './toast.js';

const STORAGE_MODE_KEY = 'communications.dial_mode';
const STORAGE_PAUSED_KEY = 'communications.auto_dial_paused';
/** Fallback only — prefer API / data-next-call-delay-sec from the server. */
const DEFAULT_NEXT_CALL_DELAY_MS = 6000;

let state = {
    mode: 'manual',
    paused: false,
    queue: [],
    currentLead: null,
    callStartedAt: null,
    pendingDisposition: null,
    selectedDisposition: null,
    sessionActive: false,
    /** Phone last dispositioned — never redial it for the next auto-dial step. */
    lastDisposedPhone: '',
    /** Last successful disposition API delay (ms) for the next auto-dial hop. */
    nextCallDelayMs: null,
};

/** Leads/phones already dispositioned this session — exclude from queue permanently. */
const disposedLeadIds = new Set();
const disposedPhoneKeys = new Set();

let autoDialDelayTimer = null;
let autoDialCountdownTimer = null;
let autoDialUiSyncTimer = null;
let presenceTimer = null;
let presenceOnCall = false;
let presenceInDisposition = false;
/** Latest presence payload waiting while a request is in flight. */
let presenceQueuedExtra = null;
/** Deduplicate identical heartbeats (setDialMode + start + call-active stacked). */
let lastPresenceSignature = '';
let lastPresenceAt = 0;

/** Active break/lunch session from DB APIs (null when idle). */
let breakActivity = null;
let breakCountdownTimer = null;
let breakRequestInFlight = false;

let autoDialListenersBound = false;
let callSummaryModalBound = false;
/** Tracks rapid chip clicks so double-click / double-tap always saves (not just native dblclick). */
let dispositionChipTap = { value: '', at: 0 };
/** Set while Save/Redial is in flight so late hangup events cannot reopen the popup. */
let dispositionSaveInFlight = false;
/** After a successful dismiss, ignore matching call-ended echoes for a short window. */
let dispositionDismissGuard = { key: '', until: 0 };
/**
 * After disposition is saved (auto or manual), ignore ALL call-ended echoes until
 * the next real outbound call starts — late hangup cleanup must not cancel the
 * next-call countdown or reopen the popup.
 */
let suppressCallEndedUntilActive = false;
/** Prevent reopening Call Summary for the same hangup identity. */
let lastDispositionOpenKey = '';
/** Remember closed disposition call identities so late hangups cannot reopen them. */
const closedDispositionKeys = new Map();
/**
 * Hard lock once Call Summary opens — hangup API / websocket echoes a few seconds
 * later must never open a second popup until Save completes (or force reopen).
 */
let dispositionPopupLock = { locked: false, until: 0 };

function lockDispositionPopup(ms = 180000) {
    dispositionPopupLock = { locked: true, until: Date.now() + ms };
    document.body.classList.add('ch-disposition-locked');
}

function unlockDispositionPopup() {
    dispositionPopupLock = { locked: false, until: 0 };
    document.body.classList.remove('ch-disposition-locked');
}

function isDispositionPopupLocked() {
    if (!dispositionPopupLock.locked) {
        return false;
    }
    if (Date.now() > dispositionPopupLock.until) {
        dispositionPopupLock = { locked: false, until: 0 };
        document.body.classList.remove('ch-disposition-locked');

        return false;
    }

    return true;
}

function dispositionContextKey(context = {}) {
    const uuid = String(context?.callUuid || '').trim();
    if (uuid) {
        return `uuid:${uuid}`;
    }

    const phone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    return phone ? `phone:${phone}` : '';
}

function dispositionContextKeys(context = {}) {
    const keys = [];
    const uuid = String(context?.callUuid || '').trim();
    if (uuid) {
        keys.push(`uuid:${uuid}`);
    }
    const phone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    if (phone) {
        keys.push(`phone:${phone}`);
    }

    return keys;
}

function rememberClosedDisposition(context = {}) {
    const until = Date.now() + 180000;
    dispositionContextKeys(context).forEach((key) => {
        closedDispositionKeys.set(key, until);
    });
    // Prune expired entries.
    const now = Date.now();
    closedDispositionKeys.forEach((expires, key) => {
        if (expires <= now) {
            closedDispositionKeys.delete(key);
        }
    });
}

function isClosedDisposition(context = {}) {
    const now = Date.now();
    return dispositionContextKeys(context).some((key) => (closedDispositionKeys.get(key) || 0) > now);
}

function armDispositionDismissGuard(context = {}) {
    const key = dispositionContextKey(context);
    dispositionDismissGuard = {
        key,
        until: Date.now() + 45000,
    };
    rememberClosedDisposition(context);
}

function shouldSuppressDispositionReopen(context = {}) {
    // Hard lock: first hangup opened Call Summary — ignore hangup-API / SSE echoes.
    if (isDispositionPopupLocked()) {
        return true;
    }

    // Once Call Summary is open / pending, never reopen from hangup echoes.
    if (dispositionSaveInFlight || suppressCallEndedUntilActive || state.pendingDisposition || isSummaryModalVisible()) {
        return true;
    }

    if (document.body.classList.contains('ch-call-summary-open')) {
        return true;
    }

    if (isClosedDisposition(context)) {
        return true;
    }

    if (Date.now() > dispositionDismissGuard.until) {
        return false;
    }

    const key = dispositionContextKey(context);
    // If we dismissed without a stable key, suppress all echoes until the window ends.
    if (!dispositionDismissGuard.key || !key) {
        return true;
    }

    return key === dispositionDismissGuard.key;
}

/** Clear "Dialing…" / "Connected" highlight from every imported-lead row. */
function clearDialingLeadHighlight() {
    document.querySelectorAll('[data-dialer-lead-row].is-dialing, [data-dialer-lead-row].is-connected-call').forEach((row) => {
        row.classList.remove('is-dialing', 'is-connected-call');
    });
}

/** Mark the active dialing row so disposition can relocate it even after highlight clears. */
function markPendingDispositionLeadRows(context = {}) {
    const root = hubRoot() || document;
    const targetId = Number.parseInt(String(context?.lead?.id || '0'), 10) || null;
    const targetPhone = String(context?.phone || context?.lead?.phone || '').trim();

    root.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const rowId = Number.parseInt(row.dataset.leadId || '0', 10) || null;
        const rowPhone = row.dataset.leadPhone || '';
        const matchId = targetId && rowId && rowId === targetId;
        const matchPhone = targetPhone && phonesMatch(rowPhone, targetPhone);
        const dialing = row.classList.contains('is-dialing');
        if (matchId || matchPhone || dialing) {
            row.dataset.pendingDisposition = '1';
            row.classList.remove('is-dialing');
        }
    });
}

function isStaleHangupWhileLive(context = {}) {
    const phone = getWebphone();
    if (!phone || !['dialing', 'ringing', 'in-call'].includes(phone.state)) {
        return false;
    }

    const activeUuid = String(
        phone.hangupCallUuid?.() || phone.originateCallUuid || phone.morpheusCallUuid || '',
    ).trim();
    const endedUuid = String(context?.callUuid || '').trim();
    if (activeUuid && endedUuid && activeUuid !== endedUuid) {
        return true;
    }

    const activePhone = normalizePhoneDigits(phone.currentCallPeer || phone.lastDialedDestination || '');
    const endedPhone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    if (activePhone && endedPhone && activePhone !== endedPhone) {
        return true;
    }

    return false;
}

/**
 * Prefer the body-mounted live modal (with working UI), then remove Turbo orphans.
 */
function resolveSummaryModalElement() {
    const modals = Array.from(document.querySelectorAll('[data-call-summary-modal]'))
        .filter((el) => el.isConnected);
    if (!modals.length) {
        return null;
    }

    const open = modals.find((el) => !el.classList.contains('hidden'));
    const onBody = modals.find((el) => el.parentElement === document.body);
    const modal = open || onBody || modals[0];

    modals.forEach((el) => {
        if (el !== modal) {
            el.remove();
        }
    });

    return modal;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function hubRoot() {
    return document.querySelector('[data-auto-dial-hub]');
}

function breakRoot() {
    return document.querySelector('[data-break-controls], [data-break-status-url], [data-phone-workspace]');
}

function isOnBreakOrLunch() {
    return Boolean(breakActivity?.type === 'break' || breakActivity?.type === 'lunch');
}

function formatBreakCountdown(seconds) {
    const sec = Math.max(0, Math.floor(Number(seconds) || 0));
    const minutes = Math.floor(sec / 60);
    const remain = sec % 60;
    return `${String(minutes).padStart(2, '0')}:${String(remain).padStart(2, '0')}`;
}

function remainingBreakSeconds() {
    if (!breakActivity?.ends_at) {
        return Math.max(0, Math.floor(Number(breakActivity?.remaining_seconds) || 0));
    }
    const endsMs = Date.parse(String(breakActivity.ends_at));
    if (!Number.isFinite(endsMs)) {
        return Math.max(0, Math.floor(Number(breakActivity?.remaining_seconds) || 0));
    }
    return Math.max(0, Math.floor((endsMs - Date.now()) / 1000));
}

function applyBreakDialingLock(locked) {
    document.body.classList.toggle('ch-on-break', locked);
    document.querySelectorAll(
        '.ghl-dialer-originate-form button[type="submit"], .ghl-dialer-originate-form [data-morpheus-dial-btn], [id^="morpheus-dial-btn"]'
    ).forEach((btn) => {
        btn.disabled = locked;
        btn.setAttribute('aria-disabled', locked ? 'true' : 'false');
        if (locked) {
            btn.title = 'Finish break/lunch before dialing';
        } else if (btn.title === 'Finish break/lunch before dialing') {
            btn.removeAttribute('title');
        }
    });
}

function syncBreakControlsUi() {
    const roots = document.querySelectorAll('[data-break-controls]');
    const active = isOnBreakOrLunch();
    const remaining = remainingBreakSeconds();
    const isLunch = breakActivity?.type === 'lunch';
    const label = isLunch ? 'Lunch' : 'Break';

    roots.forEach((root) => {
        root.classList.toggle('is-active', active);
        root.dataset.breakType = active ? (isLunch ? 'lunch' : 'break') : '';
        root.querySelectorAll('[data-break-start]').forEach((btn) => {
            btn.classList.toggle('hidden', active);
            btn.disabled = breakRequestInFlight || active;
        });
        const endBtn = root.querySelector('[data-break-end]');
        if (endBtn) {
            endBtn.classList.toggle('hidden', !active);
            endBtn.disabled = breakRequestInFlight || !active;
            endBtn.textContent = isLunch ? 'End Lunch' : 'Break Out';
        }
        const status = root.querySelector('[data-break-status]');
        const statusLabel = root.querySelector('[data-break-status-label]');
        const countdown = root.querySelector('[data-break-countdown]');
        if (status) {
            status.classList.toggle('hidden', !active);
            status.style.background = isLunch ? '#f3e8ff' : '';
            status.style.color = isLunch ? '#581c87' : '';
        }
        if (statusLabel) {
            statusLabel.textContent = active ? `${label} · ` : '';
        }
        if (countdown) {
            countdown.textContent = active ? formatBreakCountdown(remaining) : '';
        }
    });

    applyBreakDialingLock(active);
    // Avoid re-entrancy when syncAutoDialControls is mid-flight.
    if (!window.__apexSyncingBreakUi) {
        window.__apexSyncingBreakUi = true;
        try {
            syncAutoDialControls();
        } finally {
            window.__apexSyncingBreakUi = false;
        }
    }
}

function clearBreakCountdown() {
    if (breakCountdownTimer) {
        window.clearInterval(breakCountdownTimer);
        breakCountdownTimer = null;
    }
}

function startBreakCountdown() {
    clearBreakCountdown();
    if (!isOnBreakOrLunch()) {
        return;
    }
    breakCountdownTimer = window.setInterval(() => {
        const remaining = remainingBreakSeconds();
        syncBreakControlsUi();
        if (remaining <= 0 && !breakRequestInFlight) {
            void endAgentBreak({ reason: 'auto', toast: true });
        }
    }, 1000);
}

function setBreakActivity(session) {
    if (!session || !['break', 'lunch'].includes(String(session.type || '')) || session.status !== 'active') {
        breakActivity = null;
        clearBreakCountdown();
        syncBreakControlsUi();
        return;
    }
    breakActivity = {
        id: session.id || null,
        type: session.type,
        status: session.status,
        started_at: session.started_at || null,
        ends_at: session.ends_at || null,
        remaining_seconds: session.remaining_seconds ?? null,
        planned_seconds: session.planned_seconds ?? null,
        label: session.label || (session.type === 'lunch' ? 'Lunch' : 'Break'),
    };
    startBreakCountdown();
    syncBreakControlsUi();
}

function breakApiUrls() {
    const node = breakRoot();
    return {
        status: node?.getAttribute('data-break-status-url') || '',
        start: node?.getAttribute('data-break-start-url') || '',
        end: node?.getAttribute('data-break-end-url') || '',
    };
}

async function refreshBreakStatus() {
    const { status: url } = breakApiUrls();
    if (!url) {
        return;
    }
    try {
        const res = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            return;
        }
        const data = await res.json();
        setBreakActivity(data?.session || null);
    } catch {
        // best-effort
    }
}

async function startAgentBreak(type) {
    const { start: url } = breakApiUrls();
    if (!url || breakRequestInFlight) {
        return;
    }
    if (presenceOnCall) {
        showToast('End the active call before starting break/lunch.', 'warning');
        return;
    }
    if (state.pendingDisposition || isSummaryModalVisible()) {
        showToast('Save disposition before starting break/lunch.', 'warning');
        return;
    }

    const kind = type === 'lunch' ? 'lunch' : 'break';
    breakRequestInFlight = true;
    syncBreakControlsUi();

    if (state.sessionActive) {
        stopAutoDialSession({ toast: false });
    }

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                type: kind,
                extension: currentExtension() || null,
                dial_mode: state.mode === 'auto' ? 'auto' : 'manual',
                auto_session_active: false,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) {
            showToast(data?.message || 'Could not start break/lunch.', 'error');
            return;
        }
        setBreakActivity(data.session || null);
        showToast(kind === 'lunch' ? 'Lunch started (30 min).' : 'Break In started (5 min).', 'success');
        sendPresenceHeartbeat({
            break_status: kind,
            break_ends_at: data.session?.ends_at || null,
            auto_paused: true,
            on_call: false,
            in_disposition: false,
            force: true,
        });
    } catch {
        showToast('Could not start break/lunch.', 'error');
    } finally {
        breakRequestInFlight = false;
        syncBreakControlsUi();
    }
}

async function endAgentBreak({ reason = 'manual', toast = true } = {}) {
    const { end: url } = breakApiUrls();
    if (!url || breakRequestInFlight) {
        return;
    }
    breakRequestInFlight = true;
    syncBreakControlsUi();
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ reason: reason === 'auto' ? 'auto' : 'manual' }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) {
            showToast(data?.message || 'Could not end break/lunch.', 'error');
            return;
        }
        setBreakActivity(null);
        if (toast) {
            showToast(reason === 'auto' ? 'Break/lunch ended automatically.' : 'Back from break/lunch.', 'info');
        }
        sendPresenceHeartbeat({
            break_status: 'none',
            break_ends_at: null,
            auto_paused: false,
            force: true,
        });
    } catch {
        showToast('Could not end break/lunch.', 'error');
    } finally {
        breakRequestInFlight = false;
        syncBreakControlsUi();
    }
}

export function initAgentBreakControls() {
    if (document.documentElement.dataset.agentBreakInit === '1') {
        return;
    }
    if (!document.querySelector('[data-break-controls], [data-break-status-url]')) {
        return;
    }
    document.documentElement.dataset.agentBreakInit = '1';

    document.querySelectorAll('[data-break-start]').forEach((btn) => {
        btn.addEventListener('click', () => {
            void startAgentBreak(btn.getAttribute('data-break-start') || 'break');
        });
    });
    document.querySelectorAll('[data-break-end]').forEach((btn) => {
        btn.addEventListener('click', () => {
            void endAgentBreak({ reason: 'manual', toast: true });
        });
    });

    void refreshBreakStatus();
}

function syncAutoDialControls() {
    // Do NOT clear pendingDisposition while the popup lock / body flag is active —
    // hangup API echoes used to wipe it and reopen Call Summary a few seconds later.
    if (
        state.pendingDisposition
        && !isSummaryModalVisible()
        && !dispositionSaveInFlight
        && !isDispositionPopupLocked()
        && !document.body.classList.contains('ch-call-summary-open')
    ) {
        state.pendingDisposition = null;
        state.selectedDisposition = null;
        presenceInDisposition = false;
    }

    const running = state.mode === 'auto' && state.sessionActive && !state.paused;
    const waitingNext = Boolean(autoDialDelayTimer);
    const onBreak = isOnBreakOrLunch();
    const startBtns = document.querySelectorAll('[data-auto-dial-start]');
    const stopBtns = document.querySelectorAll('[data-auto-dial-stop]');
    const statusEls = document.querySelectorAll('[data-auto-dial-status]');

    startBtns.forEach((btn) => {
        btn.classList.toggle('hidden', running);
        btn.classList.toggle('is-running', false);
        btn.disabled = onBreak;
        btn.textContent = onBreak ? 'On break' : 'Start auto dial';
    });

    stopBtns.forEach((btn) => {
        btn.classList.toggle('hidden', !running);
        btn.disabled = false;
    });

    statusEls.forEach((el) => {
        if (onBreak) {
            const label = breakActivity?.type === 'lunch' ? 'Lunch' : 'Break';
            const left = formatBreakCountdown(remainingBreakSeconds());
            el.textContent = `${label} · ${left} left`;
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (isSummaryModalVisible() || state.pendingDisposition) {
            el.textContent = 'Set disposition to continue';
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (waitingNext) {
            const countdownText = document.querySelector('[data-auto-dial-countdown-text]')?.textContent;
            el.textContent = countdownText || 'Starting call in 6s…';
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (running) {
            const remaining = state.queue.length + (state.currentLead ? 1 : 0);
            el.textContent = remaining > 0
                ? `Auto dial running · ${remaining} lead${remaining === 1 ? '' : 's'} left`
                : 'Auto dial running';
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (state.mode === 'auto' && state.paused) {
            el.textContent = 'Auto dial paused';
            el.classList.remove('hidden', 'is-active');
        } else if (state.mode === 'auto') {
            el.textContent = 'Auto dial ready — press Start to begin';
            el.classList.remove('hidden', 'is-active');
        } else {
            el.textContent = '';
            el.classList.add('hidden');
            el.classList.remove('is-active');
        }
    });
}

function stopAutoDialSession({ toast = true } = {}) {
    state.sessionActive = false;
    state.paused = false;
    awaitingNextCallAfterDisposition = false;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    clearAutoDialDelay();
    stopAutoDialUiSync();
    clearDialingLeadHighlight();
    syncAutoDialControls();
    sendPresenceHeartbeat();
    if (toast) {
        showToast('Auto dial stopped.', 'info');
    }
}

function clearAutoDialDelay() {
    if (autoDialDelayTimer) {
        clearTimeout(autoDialDelayTimer);
        autoDialDelayTimer = null;
    }

    if (autoDialCountdownTimer) {
        clearInterval(autoDialCountdownTimer);
        autoDialCountdownTimer = null;
    }

    const countdown = document.querySelector('[data-auto-dial-countdown]');
    countdown?.classList.add('hidden');
}

function showAutoDialCountdown(seconds, message) {
    const countdown = document.querySelector('[data-auto-dial-countdown]');
    const text = countdown?.querySelector('[data-auto-dial-countdown-text]');
    if (!countdown || !text) {
        return;
    }

    countdown.classList.remove('hidden');
    text.textContent = `${message} in ${seconds}s…`;
    // Keep the Imported leads status in sync with the countdown timer.
    document.querySelectorAll('[data-auto-dial-status]').forEach((el) => {
        if (isSummaryModalVisible() || state.pendingDisposition) {
            return;
        }
        el.textContent = text.textContent;
        el.classList.remove('hidden');
        el.classList.add('is-active');
    });
}

function isSummaryModalVisible() {
    return Array.from(document.querySelectorAll('[data-call-summary-modal]'))
        .some((el) => el.isConnected && !el.classList.contains('hidden'));
}

/**
 * After a successful disposition Save, allow the 6s next-call countdown to run
 * even if a late hangup echo briefly toggles disposition UI state.
 */
let awaitingNextCallAfterDisposition = false;

function isDispositionBlocking() {
    if (awaitingNextCallAfterDisposition) {
        return false;
    }

    // Only block while the Call Summary is actually open / pending — not during
    // background save after the popup already closed (that stuck the status text).
    return Boolean(state.pendingDisposition) || isSummaryModalVisible();
}

function dispositionToast(message, type = 'warning') {
    // Summary modal suppresses toasts by default — always show disposition feedback.
    return showToast(message, type, { suppressWhenSummary: false });
}

function suggestedDispositionFromResult(result, connected) {
    const normalized = String(result || '').trim().toLowerCase();
    if (connected || normalized === 'connected' || normalized === 'answered') {
        return '';
    }
    if (normalized.includes('busy')) {
        return 'Owner Not Available';
    }
    if (normalized.includes('voicemail') || normalized.includes('answering')) {
        return 'Answering Machine';
    }
    if (normalized.includes('wrong') || normalized.includes('invalid')) {
        return 'Wrong Number/Business';
    }
    if (normalized.includes('callback') || normalized.includes('call back') || normalized.includes('call later')) {
        return 'Call Back';
    }

    return 'No Answer';
}

function resolveNextCallDelayMs(payload = null) {
    const fromPayload = Number(payload?.next_call_delay_sec);
    if (Number.isFinite(fromPayload) && fromPayload >= 0) {
        return Math.round(fromPayload * 1000);
    }

    if (Number.isFinite(state.nextCallDelayMs) && state.nextCallDelayMs >= 0) {
        return Math.round(state.nextCallDelayMs);
    }

    const hubDelay = Number(
        hubRoot()?.dataset?.nextCallDelaySec
        || document.querySelector('[data-call-summary-modal]')?.dataset?.nextCallDelaySec
        || '',
    );
    if (Number.isFinite(hubDelay) && hubDelay >= 0) {
        return Math.round(hubDelay * 1000);
    }

    return DEFAULT_NEXT_CALL_DELAY_MS;
}

function scheduleAutoDial(action, message = 'Next call', delayMs = null) {
    clearAutoDialDelay();

    if (state.mode !== 'auto' || state.paused || isDispositionBlocking()) {
        return Promise.resolve(false);
    }

    const waitMs = Math.max(0, Number.isFinite(delayMs) ? delayMs : resolveNextCallDelayMs());
    const waitSec = Math.max(1, Math.ceil(waitMs / 1000));
    let remaining = waitSec;
    showAutoDialCountdown(remaining, message);
    syncAutoDialControls();

    return new Promise((resolve) => {
        autoDialCountdownTimer = window.setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(autoDialCountdownTimer);
                autoDialCountdownTimer = null;

                return;
            }

            showAutoDialCountdown(remaining, message);
        }, 1000);

        autoDialDelayTimer = window.setTimeout(async () => {
            autoDialDelayTimer = null;
            clearAutoDialDelay();
            syncAutoDialControls();

            if (state.mode !== 'auto' || state.paused || isDispositionBlocking()) {
                resolve(false);

                return;
            }

            const result = await action();
            syncAutoDialControls();
            resolve(result);
        }, waitMs);
    });
}

function switchToLeadsTab() {
    const workspace = hubRoot();
    if (!workspace) {
        return;
    }

    const leadsBtn = workspace.querySelector('[data-phone-panel-view="leads"]');
    leadsBtn?.click();
}

function switchToCallLogsTab() {
    const workspace = hubRoot();
    if (!workspace) {
        return;
    }

    const logsBtn = workspace.querySelector('[data-phone-panel-view="logs"]');
    logsBtn?.click();
}

function dispositionUrl() {
    return document.querySelector('[data-call-summary-modal]')?.dataset.dispositionUrl || '';
}

function formatDuration(seconds) {
    const total = Math.max(0, Number.parseInt(String(seconds || 0), 10) || 0);
    const mins = Math.floor(total / 60);
    const secs = total % 60;

    return `${String(mins).padStart(2, '0')} Min ${String(secs).padStart(2, '0')} Sec`;
}

function leadLabel(lead) {
    const name = String(lead?.name || lead?.lead_name || '').trim();
    const phone = String(lead?.phone_display || lead?.phone || '').trim();

    if (name && phone) {
        return `${name} · ${phone}`;
    }

    if (phone) {
        return phone;
    }

    return name;
}

function buildLeadRow(lead) {
    const name = String(lead.name || '').trim();
    const contact = String(lead.contact || lead.owner_name || '').trim();
    const phone = String(lead.phone_display || lead.phone || '—');
    const fileName = String(lead.file_name || lead.workflow || '').trim();
    const showFileName = fileName !== '' && !['default', 'result', 'n/a', 'none', '-'].includes(fileName.toLowerCase());
    const initial = (name || contact || 'L').charAt(0).toUpperCase();
    const escAttr = (value) => String(value || '').replace(/"/g, '&quot;');

    return `
        <div class="ghl-dialer-lead-row" data-dialer-lead-row tabindex="0"
            data-lead-id="${lead.id || ''}"
            data-lead-phone="${lead.phone || ''}"
            data-lead-name="${escAttr(name)}"
            data-lead-contact="${escAttr(contact)}"
            data-lead-file-name="${escAttr(fileName)}">
            <div class="ghl-dialer-lead-avatar" aria-hidden="true">${initial}</div>
            <div class="ghl-dialer-lead-main">
                ${name ? `<span class="ghl-dialer-lead-name">${name}</span>` : ''}
                ${contact ? `<span class="ghl-dialer-lead-contact">${contact}</span>` : ''}
                <span class="ghl-dialer-lead-number">${phone}</span>
                ${showFileName ? `<span class="ghl-dialer-lead-meta" title="${escAttr(fileName)}">${fileName}</span>` : ''}
            </div>
            <div class="ghl-dialer-lead-actions">
                <button type="button" class="ghl-dialer-lead-call-btn" data-dial-lead-call title="Call this lead">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                    <span>Call</span>
                </button>
            </div>
        </div>
    `;
}

function setDialMode(mode, { announcePresence = true } = {}) {
    const nextMode = mode === 'auto' ? 'auto' : 'manual';
    const modeChanged = state.mode !== nextMode;
    state.mode = nextMode;
    localStorage.setItem(STORAGE_MODE_KEY, state.mode);

    document.querySelectorAll('[data-dial-mode-switch] [data-dial-mode]').forEach((btn) => {
        const active = btn.dataset.dialMode === state.mode;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    const hub = hubRoot();
    hub?.classList.toggle('ch-dial-workspace--auto-mode', state.mode === 'auto');
    document.body.classList.toggle('ch-dial-auto-mode', state.mode === 'auto');

    if (state.mode !== 'auto') {
        state.sessionActive = false;
        clearAutoDialDelay();
    }

    syncAutoDialControls();
    // Only announce when the dial mode actually changed — avoids double presence on Start.
    if (announcePresence && modeChanged) {
        sendPresenceHeartbeat();
    }
}

function presenceUrl() {
    const hub = document.querySelector('[data-presence-url]');
    const fromAttr = hub?.getAttribute('data-presence-url') || '';
    if (fromAttr) {
        return fromAttr;
    }
    const bodyAttr = document.body?.getAttribute('data-presence-url') || '';
    if (bodyAttr) {
        return bodyAttr;
    }
    const path = window.location.pathname || '';
    if (path.startsWith('/portal')) {
        return '/portal/communications/monitoring/presence';
    }
    return '/admin/communications/monitoring/presence';
}

function currentExtension() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const synced = form?.querySelector('[data-dial-extension-sync], [name="from_extension"]');
    const raw = synced?.value || form?.querySelector('select[name="from_extension"]')?.value || '';
    return String(raw || '').replace(/\D/g, '');
}

async function sendPresenceHeartbeat(extra = {}) {
    const url = presenceUrl();
    if (!url) {
        return;
    }

    // Don't spam presence while the tab is hidden.
    if (document.hidden && !extra.call_ended && !extra.force) {
        return;
    }

    const onCall = Boolean(extra.on_call ?? presenceOnCall);
    const inDisposition = Boolean(extra.in_disposition ?? presenceInDisposition);
    const breakStatus = isOnBreakOrLunch()
        ? (breakActivity.type === 'lunch' ? 'lunch' : 'break')
        : (extra.break_status === 'none' ? 'none' : (extra.break_status || 'none'));
    const breakEndsAt = isOnBreakOrLunch()
        ? (breakActivity.ends_at || extra.break_ends_at || null)
        : (extra.break_status === 'none' ? null : (extra.break_ends_at || null));
    const signature = [
        state.mode === 'auto' ? 'auto' : 'manual',
        state.sessionActive ? '1' : '0',
        state.paused ? '1' : '0',
        onCall ? '1' : '0',
        inDisposition ? '1' : '0',
        breakStatus,
        breakEndsAt || '',
        currentExtension() || '',
        extra.call_ended ? 'ended' : '',
    ].join('|');

    // Same status within 2s → one Network row (mode switch + start + call-active).
    if (
        !extra.call_ended
        && signature === lastPresenceSignature
        && (Date.now() - lastPresenceAt) < 2000
    ) {
        return;
    }

    // One in-flight presence at a time — keep the newest state and send it after.
    if (window.__apexPresenceInFlight && !extra.call_ended) {
        presenceQueuedExtra = { ...extra };
        return;
    }

    const body = {
        dial_mode: state.mode === 'auto' ? 'auto' : 'manual',
        auto_session_active: Boolean(state.sessionActive && state.mode === 'auto'),
        auto_paused: Boolean((state.paused && state.mode === 'auto') || isOnBreakOrLunch()),
        on_call: onCall,
        in_disposition: inDisposition,
        break_status: breakStatus,
        break_ends_at: breakEndsAt,
        disposition_phone: extra.disposition_phone
            || state.pendingDisposition?.phone
            || state.pendingDisposition?.lead?.phone
            || null,
        extension: currentExtension() || null,
        ...extra,
    };
    // Keep DB break as source of truth when active — don't let sparse extras wipe it.
    if (isOnBreakOrLunch()) {
        body.break_status = breakActivity.type === 'lunch' ? 'lunch' : 'break';
        body.break_ends_at = breakActivity.ends_at || breakEndsAt;
        body.auto_paused = true;
        body.on_call = false;
    } else if (!Object.prototype.hasOwnProperty.call(extra, 'break_status')) {
        body.break_status = 'none';
        body.break_ends_at = null;
    }

    window.__apexPresenceInFlight = true;
    lastPresenceSignature = signature;
    lastPresenceAt = Date.now();
    try {
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = window.setTimeout(() => {
            try {
                controller?.abort();
            } catch {
                // ignore
            }
        }, 4000);
        await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
            keepalive: Boolean(extra.call_ended),
            signal: controller?.signal,
        });
        window.clearTimeout(timeoutId);
    } catch {
        // Presence is best-effort for monitoring.
    } finally {
        window.__apexPresenceInFlight = false;
        const queued = presenceQueuedExtra;
        presenceQueuedExtra = null;
        if (queued) {
            void sendPresenceHeartbeat(queued);
        }
    }
}

export function initAgentPresence() {
    if (document.documentElement.dataset.agentPresenceInit === '1') {
        return;
    }
    // Start as soon as the agent is logged in (layout presence URL) or opens the dialer.
    if (!document.querySelector('[data-presence-url], [data-phone-workspace], .ghl-dialer-originate-form, [data-call-summary-modal]')) {
        return;
    }
    if (!presenceUrl()) {
        return;
    }

    document.documentElement.dataset.agentPresenceInit = '1';

    window.addEventListener('comm:call-active', () => {
        presenceOnCall = true;
        presenceInDisposition = false;
        sendPresenceHeartbeat({ on_call: true, in_disposition: false, force: true });
    });

    window.addEventListener('comm:call-ended', (event) => {
        presenceOnCall = false;
        presenceInDisposition = true;
        const phone = event?.detail?.phone || event?.detail?.destination || '';
        sendPresenceHeartbeat({
            on_call: false,
            call_ended: true,
            in_disposition: true,
            disposition_phone: phone || null,
            force: true,
        });
    });

    // Immediate login signal → Call Monitoring moves agent from Not logged in → Not in call.
    sendPresenceHeartbeat({ on_call: false, in_disposition: false, force: true });
    // Presence every 25s is enough for monitoring; dialer must not compete with call APIs.
    presenceTimer = window.setInterval(() => {
        sendPresenceHeartbeat({
            on_call: presenceOnCall,
            in_disposition: presenceInDisposition,
        });
    }, 25000);

    window.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            sendPresenceHeartbeat({
                on_call: presenceOnCall,
                in_disposition: presenceInDisposition,
            });
        }
    });

    window.addEventListener('beforeunload', () => {
        sendPresenceHeartbeat({
            on_call: presenceOnCall,
            in_disposition: presenceInDisposition,
        });
    });
}

function showSummaryModal(context, { force = false } = {}) {
    if (!force && shouldSuppressDispositionReopen(context)) {
        return;
    }

    const modal = resolveSummaryModalElement();
    if (!modal) {
        return;
    }

    const openKey = dispositionContextKey(context);
    // Same hangup already showing — never flash/reopen the popup.
    if (
        !force
        && openKey
        && openKey === lastDispositionOpenKey
        && (isSummaryModalVisible() || state.pendingDisposition)
    ) {
        return;
    }

    // Hard rule: disposition popup must never open twice for the same hangup.
    if (!force && (isDispositionPopupLocked() || !modal.classList.contains('hidden') || state.pendingDisposition)) {
        return;
    }

    // Intentional reopen (failed save) clears the dismiss guard / lock briefly.
    if (force) {
        dispositionDismissGuard = { key: '', until: 0 };
        unlockDispositionPopup();
    }

    // Lock immediately — before any async hangup-API teardown can echo call-ended.
    lockDispositionPopup();

    // Never continue the queue while disposition is required.
    clearAutoDialDelay();
    clearDialerDestinationInput();
    clearDialingLeadHighlight();
    dismissAllToasts();
    // Kill ringing audio + active-call UI immediately (PSTN/SIP can lag behind the popup).
    // Always clear click-to-call flags; avoid double SIP kill while hangup() is already in flight.
    const phone = getWebphone();
    if (phone) {
        phone.clickToCallActive = false;
        phone.awaitingDestinationBridge = false;
        phone.pendingClickToCall = false;
        phone.outboundWaitingActive = false;
        phone.cancelOutboundAttempt?.('disposition');
        if (!phone.hangupInFlight) {
            phone.forceTeardownForDisposition?.();
        } else {
            phone.restoreDialerAfterCall?.({ force: true });
            phone.stopAllLocalRingers?.();
            phone.flushCallUiInstant?.();
        }
    }
    // Mark this hangup closed immediately so websocket/hangup API echoes cannot reopen.
    rememberClosedDisposition(context);
    armDispositionDismissGuard(context);
    if (openKey) {
        lastDispositionOpenKey = openKey;
    }

    // Keep the popup above topbar icons / floating call UI (those use z-index 10k+).
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    const activeNotes = window.__apexActiveCallNotes?.read?.() || {};
    state.pendingDisposition = {
        ...context,
        inCallNotes: String(activeNotes.notes || context.inCallNotes || '').trim(),
        comment: String(activeNotes.comment || context.comment || '').trim(),
    };
    state.selectedDisposition = null;

    const forcedDisposition = String(window.__apexForceDisposition || context.forceDisposition || '').trim();
    const suggested = forcedDisposition
        || suggestedDispositionFromResult(context.result, context.connected === true);
    if (suggested) {
        state.selectedDisposition = suggested;
    }
    if (forcedDisposition) {
        window.__apexForceDisposition = '';
    }

    const leadLine = modal.querySelector('[data-call-summary-lead]');
    const durationEl = modal.querySelector('[data-call-summary-duration]');
    const resultEl = modal.querySelector('[data-call-summary-result]');
    const noteEl = modal.querySelector('[data-call-summary-note]');
    const customDispositionEl = modal.querySelector('[data-call-summary-custom-disposition]');
    const nextLabel = modal.querySelector('[data-call-summary-next-label]');
    const pauseBtn = modal.querySelector('[data-call-summary-pause]');

    if (leadLine) {
        leadLine.textContent = leadLabel(context.lead) || leadLabel({ phone: context.phone }) || context.phone || 'Call ended';
    }
    if (durationEl) {
        durationEl.textContent = formatDuration(context.durationSec);
    }
    if (resultEl) {
        resultEl.textContent = forcedDisposition || context.result || 'Completed';
    }
    if (noteEl) {
        noteEl.value = activeNotes.comment || context.comment || '';
    }
    if (customDispositionEl) {
        customDispositionEl.value = '';
    }
    if (nextLabel) {
        nextLabel.textContent = state.mode === 'auto' && state.sessionActive && !state.paused
            ? 'Save & Next'
            : 'Save';
    }
    if (pauseBtn) {
        pauseBtn.classList.toggle('hidden', state.mode !== 'auto');
        pauseBtn.classList.toggle('is-paused', state.paused);
        pauseBtn.hidden = state.mode !== 'auto';
    }
    modal.classList.toggle('ch-call-summary--manual', state.mode !== 'auto');
    modal.dataset.dispositionBusy = '';

    presenceInDisposition = true;
    presenceOnCall = false;
    sendPresenceHeartbeat({
        on_call: false,
        in_disposition: true,
        disposition_phone: context.phone || context.lead?.phone || null,
    });

    modal.querySelectorAll('[data-disposition-value]').forEach((btn) => {
        const value = String(btn.dataset.dispositionValue || '').trim();
        const selected = Boolean(suggested) && value.toLowerCase() === suggested.toLowerCase();
        btn.classList.toggle('is-selected', selected);
    });
    if (suggested && customDispositionEl && !modal.querySelector('[data-disposition-value].is-selected')) {
        customDispositionEl.value = suggested;
    }

    modal.classList.remove('hidden', 'is-closing');
    modal.classList.add('is-opening');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ch-call-summary-open');
    syncAutoDialControls();

    window.requestAnimationFrame(() => {
        modal.classList.add('is-visible');
        modal.classList.remove('is-opening');
    });

    // Focus selected disposition so agents can confirm quickly.
    window.setTimeout(() => {
        const live = resolveSummaryModalElement() || modal;
        live.querySelector('[data-disposition-value].is-selected')?.focus?.()
            || live.querySelector('[data-disposition-value]')?.focus?.();
    }, 40);
}

function hideSummaryModal({ force = false, context = null } = {}) {
    const modals = Array.from(document.querySelectorAll('[data-call-summary-modal]'));
    const openModal = modals.find((el) => el.isConnected && !el.classList.contains('hidden'))
        || resolveSummaryModalElement()
        || modals[0]
        || null;

    if (!openModal && !document.body.classList.contains('ch-call-summary-open')) {
        return;
    }

    if (!force && state.pendingDisposition && openModal && !openModal.classList.contains('hidden')) {
        dispositionToast('Select or write a disposition to continue.', 'warning');
        return;
    }

    const closedContext = context || state.pendingDisposition || {};
    if (force) {
        armDispositionDismissGuard(closedContext);
    }

    modals.forEach((modal) => {
        modal.classList.remove('is-visible', 'is-opening');
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        modal.dataset.dispositionBusy = '';
        modal.querySelector('[data-call-summary-next]')?.removeAttribute('disabled');
        modal.querySelector('[data-call-summary-redial]')?.removeAttribute('disabled');
    });
    document.body.classList.remove('ch-call-summary-open');
    state.pendingDisposition = null;
    state.selectedDisposition = null;
    lastDispositionOpenKey = '';
    presenceInDisposition = false;
    // Keep popup lock until Save finishes — only unlock when Save/Redial explicitly ends the flow.
    // Failed save reopens with force; successful Save sets suppressCallEndedUntilActive.
    sendPresenceHeartbeat({ in_disposition: false, on_call: presenceOnCall });
    syncAutoDialControls();
}

async function saveDisposition(disposition, contextOverride = null, noteOverride = null) {
    const context = contextOverride || state.pendingDisposition;
    if (!context || !disposition) {
        return null;
    }

    const url = dispositionUrl();
    if (!url) {
        return null;
    }

    const note = noteOverride !== null
        ? String(noteOverride || '').trim()
        : (document.querySelector('[data-call-summary-note]')?.value?.trim() || '');
    const active = window.__apexActiveCallNotes?.read?.() || {};
    const inCallNotes = String(active.notes || context?.inCallNotes || '').trim();

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            signal: AbortSignal.timeout(8000),
            body: JSON.stringify({
                disposition,
                note: note || null,
                in_call_notes: inCallNotes || null,
                call_uuid: context.callUuid || null,
                phone: context.phone || context.lead?.phone || null,
                lead_id: context.lead?.id || null,
                duration_sec: context.durationSec || 0,
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.saved !== true) {
            throw new Error(payload.message || 'Could not save disposition.');
        }

        return payload;
    } catch (error) {
        console.warn('[auto-dial] disposition save failed', error);
        dispositionToast(error.message || 'Could not save disposition. Please try again.', 'error');

        return null;
    }
}

function resolveSummaryDisposition(modal) {
    const custom = modal.querySelector('[data-call-summary-custom-disposition]')?.value?.trim() || '';
    if (custom) {
        return custom;
    }

    return String(state.selectedDisposition || '').trim();
}

function normalizePhoneDigits(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    if (digits.length >= 10) {
        return digits.slice(-10);
    }

    return digits;
}

function phonesMatch(a, b) {
    const left = normalizePhoneDigits(a);
    const right = normalizePhoneDigits(b);

    return Boolean(left && right && left === right);
}

function rememberDisposedLead(leadId, phone) {
    const id = Number.parseInt(String(leadId || '0'), 10) || null;
    if (id) {
        disposedLeadIds.add(id);
    }
    const phoneKey = normalizePhoneDigits(phone);
    if (phoneKey) {
        disposedPhoneKeys.add(phoneKey);
        state.lastDisposedPhone = phoneKey;
    }
}

function isDisposedLead(lead) {
    if (!lead) {
        return false;
    }
    const id = Number.parseInt(String(lead.id || '0'), 10) || null;
    if (id && disposedLeadIds.has(id)) {
        return true;
    }
    const phoneKey = normalizePhoneDigits(lead.phone);
    if (phoneKey && disposedPhoneKeys.has(phoneKey)) {
        return true;
    }

    return false;
}

function removeLeadFromQueue(leadId, phone) {
    const root = hubRoot() || document;
    const targetId = Number.parseInt(String(leadId || '0'), 10) || null;
    const targetPhone = String(phone || '').trim();
    rememberDisposedLead(targetId, targetPhone);

    root.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const rowId = Number.parseInt(row.dataset.leadId || '0', 10) || null;
        const rowPhone = row.dataset.leadPhone || '';
        const matchId = targetId && rowId && rowId === targetId;
        const matchPhone = targetPhone && phonesMatch(rowPhone, targetPhone);
        const disposed = isDisposedLead({ id: rowId, phone: rowPhone });
        if (matchId || matchPhone || disposed) {
            row.classList.remove('is-dialing');
            row.remove();
        }
    });

    state.queue = state.queue.filter((lead) => !isDisposedLead(lead) && !(
        (targetId && Number(lead.id) === targetId)
        || (targetPhone && phonesMatch(lead.phone, targetPhone))
    ));

    // Always clear current after a dispositioned remove for this target.
    if (state.currentLead) {
        const currentMatchId = targetId && Number(state.currentLead.id) === targetId;
        const currentMatchPhone = targetPhone && phonesMatch(state.currentLead.phone, targetPhone);
        if (currentMatchId || currentMatchPhone || isDisposedLead(state.currentLead)) {
            state.currentLead = null;
        }
    }

    const items = root.querySelector('[data-imported-leads-items]');
    if (items && !items.querySelector('[data-dialer-lead-row]')) {
        items.innerHTML = '<p class="ghl-dialer-recent-empty" data-imported-leads-empty>No imported leads with phone numbers yet.</p>';
    }

    syncAutoDialControls();
}

/** Move the active dialing imported lead into Call logs (DOM). */
function relocateDialingLeadToCallLog(context = {}, callLog = {}, disposition = '') {
    const root = hubRoot() || document;
    const pendingRows = Array.from(root.querySelectorAll(
        '[data-dialer-lead-row].is-dialing, [data-dialer-lead-row][data-pending-disposition="1"]',
    ));
    const leadId = context?.lead?.id || callLog?.lead_id || null;
    const phone = context?.phone || context?.lead?.phone || callLog?.phone || '';

    pendingRows.forEach((row) => {
        rememberDisposedLead(row.dataset.leadId, row.dataset.leadPhone);
        row.classList.remove('is-dialing');
        delete row.dataset.pendingDisposition;
        row.remove();
    });

    removeLeadFromQueue(leadId, phone);
    clearDialingLeadHighlight();
    state.currentLead = null;

    const enriched = {
        ...callLog,
        // Never fall back disposition → CDR result (connected / no-answer) — that hid agent outcomes.
        disposition: String(disposition || callLog.disposition || '').trim(),
        lead_name: callLog.lead_name || context?.lead?.name || '',
        lead_contact: callLog.lead_contact || context?.lead?.contact || '',
        lead_file_name: callLog.lead_file_name || context?.lead?.file_name || '',
        phone: callLog.phone || phone,
        phone_display: callLog.phone_display || context?.lead?.phone_display || phone,
        time_ago: callLog.time_ago || 'just now',
        duration_label: callLog.duration_label
            || (callLog.duration_sec ? `${callLog.duration_sec}s` : '0s'),
    };

    prependCallLog(enriched);
}

function prependCallLog(callLog) {
    if (!callLog) {
        return;
    }

    // Ensure disposition / lead labels survive even if API omit some display fields.
    const disposition = String(callLog.disposition || '').trim();
    const enriched = {
        ...callLog,
        disposition,
        lead_name: callLog.lead_name || callLog.name || '',
        phone_display: callLog.phone_display || callLog.phone || '',
        time_ago: callLog.time_ago || 'just now',
        duration_label: callLog.duration_label || (callLog.duration_sec ? `${callLog.duration_sec}s` : '0s'),
    };

    document.querySelectorAll('[data-call-logs-list]').forEach((list) => {
        const items = list.querySelector('[data-call-logs-items]');
        if (!items) {
            return;
        }

        items.querySelector('[data-call-logs-empty]')?.remove();

        if (typeof window.buildCallLogRow === 'function') {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = window.buildCallLogRow(enriched);
            const row = wrapper.firstElementChild;
            if (row) {
                items.prepend(row);
            }

            return;
        }

        // CDR statuses only — keep agent disposition "No Answer".
        const statusLike = ['', '—', '-', 'completed', 'initiated', 'connected', 'answered', 'no-answer', 'busy', 'failed', 'missed', 'unknown'];
        let disposition = String(enriched.disposition || '').trim();
        if (disposition && statusLike.includes(disposition.toLowerCase())) {
            disposition = '';
        }
        const note = String(enriched.call_note || '').trim();
        const inCallNotes = String(enriched.in_call_notes || '').trim();
        const escape = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        const fields = [];
        if (inCallNotes) {
            fields.push(`<p class="ghl-dialer-recent-field"><span class="ghl-dialer-recent-field__label">Notes:</span> <span class="ghl-dialer-recent-field__value">${escape(inCallNotes)}</span></p>`);
        }
        if (note) {
            fields.push(`<p class="ghl-dialer-recent-field"><span class="ghl-dialer-recent-field__label">Comment:</span> <span class="ghl-dialer-recent-field__value">${escape(note)}</span></p>`);
        }
        if (disposition) {
            fields.push(`<p class="ghl-dialer-recent-field ghl-dialer-recent-field--disposition"><span class="ghl-dialer-recent-field__label">Disposition:</span> <span class="ghl-dialer-recent-field__value">${escape(disposition)}</span></p>`);
        }
        const fieldsHtml = fields.length ? `<div class="ghl-dialer-recent-fields">${fields.join('')}</div>` : '';
        const phone = enriched.phone_display || enriched.phone || '—';
        const leadName = enriched.lead_name || '';
        const leadContact = enriched.lead_contact || '';
        const leadFileName = enriched.lead_file_name || '';
        const row = document.createElement('div');
        row.className = 'ghl-dialer-recent-row';
        row.innerHTML = `
            <div class="ghl-dialer-recent-main">
                <div class="ghl-dialer-recent-head">
                    <span class="ghl-dialer-recent-dir">Outbound</span>
                </div>
                <div class="ghl-dialer-recent-contact">
                    ${leadName ? `<span class="ghl-dialer-recent-name">${escape(leadName)}</span>` : ''}
                    ${leadContact ? `<span class="ghl-dialer-recent-contact-name">${escape(leadContact)}</span>` : ''}
                    <span class="ghl-dialer-recent-number">${escape(phone)}</span>
                    ${leadFileName ? `<span class="ghl-dialer-recent-file" title="${escape(leadFileName)}">${escape(leadFileName)}</span>` : ''}
                </div>
                <span class="ghl-dialer-recent-meta">${escape(enriched.time_ago || 'just now')} · ${escape(enriched.duration_label || '0s')}</span>
                ${fieldsHtml}
            </div>
        `;
        items.prepend(row);
    });
}

function applyDispositionSideEffects(payload, context, disposition) {
    const leadId = context?.lead?.id
        || payload?.lead?.id
        || state.currentLead?.id
        || null;
    const phone = context?.phone
        || context?.lead?.phone
        || payload?.call_log?.phone
        || state.currentLead?.phone
        || '';
    const callLog = {
        ...(payload?.call_log || {}),
        disposition: disposition || payload?.disposition || payload?.call_log?.disposition || '',
        lead_name: payload?.call_log?.lead_name || context?.lead?.name || '',
        lead_contact: payload?.call_log?.lead_contact || context?.lead?.contact || context?.lead?.owner_name || '',
        lead_file_name: payload?.call_log?.lead_file_name || context?.lead?.file_name || context?.lead?.workflow || '',
        phone: payload?.call_log?.phone || phone,
        phone_display: payload?.call_log?.phone_display || context?.lead?.phone_display || phone,
        duration_sec: payload?.call_log?.duration_sec ?? context?.durationSec ?? 0,
        time_ago: payload?.call_log?.time_ago || 'just now',
    };

    // Remove from Imported leads + prepend to Call logs immediately.
    relocateDialingLeadToCallLog(
        { lead: context?.lead || { id: leadId, phone, name: callLog.lead_name }, phone },
        callLog,
        disposition,
    );

    clearDialerDestinationInput();
    document.querySelectorAll('.ghl-dialer-originate-form').forEach((form) => {
        delete form.dataset.dialLeadId;
        delete form.dataset.dialLeadName;
        delete form.dataset.dialLeadPhone;
        const dest = form.querySelector('[name="destination"]');
        if (dest) {
            dest.value = '';
        }
    });
    window.__apexActiveCallNotes?.clear?.();

    // Stay on Imported leads so the 6s next-call countdown is visible.
    switchToLeadsTab();
    syncAutoDialControls();

    return { leadId, phone, disposition };
}

async function dialLead(lead) {
    if (!lead?.phone || isDisposedLead(lead)) {
        return false;
    }

    // Never place the just-dispositioned number again on the next hop.
    if (state.lastDisposedPhone && phonesMatch(lead.phone, state.lastDisposedPhone)) {
        removeLeadFromQueue(lead.id, lead.phone);

        return false;
    }

    state.currentLead = lead;
    state.callStartedAt = Date.now();
    markLeadRowActive(lead, 'dialing');

    const form = document.querySelector('.ghl-dialer-originate-form');
    if (form) {
        form.dataset.dialLeadId = String(lead.id || '');
        form.dataset.dialLeadName = String(lead.name || '');
        form.dataset.dialLeadPhone = String(lead.phone || '');
    }

    const dialed = await placeOutboundCall(lead.phone, lead);
    if (!dialed) {
        state.currentLead = null;
        markLeadRowActive({ id: null, phone: '' }, '');

        return false;
    }

    // Re-assert Ringing/Connected UI if the pad was cleared while auto dial is still live.
    const phone = getWebphone();
    if (phone && !phone.hangupInFlight && !phone._outboundCancelled) {
        phone.liveCallUiActive = true;
        phone._callEndedDispatched = false;
        phone.userInitiatedHangup = false;
        phone.remoteHangupHandled = false;
        if (phone.state === 'in-call' || phone.timerPhase === 'connected') {
            phone.refreshDialerCallOverlay?.('in-call');
            markLeadRowActive(lead, 'connected');
        } else {
            phone.refreshOutboundRingingUi?.(lead.phone);
            phone.refreshDialerCallOverlay?.('dialing');
            markLeadRowActive(lead, 'dialing');
        }
    }
    startAutoDialUiSync();

    return true;
}

async function dialNextInQueue({ withDelay = false, delayMessage = 'Next call', delayMs = null } = {}) {
    if (state.mode !== 'auto' || state.paused || isDispositionBlocking()) {
        return false;
    }

    const placeNext = async (attempt = 0) => {
        if (isDispositionBlocking()) {
            return false;
        }
        if (attempt > 8) {
            state.sessionActive = false;
            syncAutoDialControls();
            showToast('No more callable imported leads in the queue.', 'info');

            return false;
        }

        if (state.queue.length === 0) {
            const list = hubRoot()?.querySelector('[data-imported-leads-list]');
            if (list && list.dataset.importedLeadsHasMore !== '0') {
                await fetchImportedLeads(list, false);
            }
        }

        // Skip disposed / just-dispositioned numbers until a fresh lead is found.
        let next = null;
        while (state.queue.length > 0) {
            const candidate = state.queue.shift();
            if (!candidate?.phone || isDisposedLead(candidate)) {
                if (candidate) {
                    removeLeadFromQueue(candidate.id, candidate.phone);
                }
                continue;
            }
            if (state.lastDisposedPhone && phonesMatch(candidate.phone, state.lastDisposedPhone)) {
                removeLeadFromQueue(candidate.id, candidate.phone);
                continue;
            }
            next = candidate;
            break;
        }

        if (!next) {
            const list = hubRoot()?.querySelector('[data-imported-leads-list]');
            if (list && list.dataset.importedLeadsHasMore !== '0') {
                await fetchImportedLeads(list, false);

                return placeNext(attempt + 1);
            }
            state.sessionActive = false;
            syncAutoDialControls();
            showToast('No more callable imported leads in the queue.', 'info');

            return false;
        }

        markLeadRowActive(next, 'dialing');

        const dialed = await dialLead(next);
        syncAutoDialControls();
        if (!dialed) {
            // Cooldown / originate miss — advance to the following lead.
            return placeNext(attempt + 1);
        }

        return dialed;
    };

    if (withDelay) {
        return scheduleAutoDial(placeNext, delayMessage, delayMs);
    }

    return placeNext();
}

function markLeadRowActive(lead, phase = 'dialing') {
    const root = hubRoot();
    root?.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const match = Boolean(lead)
            && (
                (lead.id && Number(row.dataset.leadId) === Number(lead.id))
                || (lead.phone && row.dataset.leadPhone === lead.phone)
            );
        row.classList.toggle('is-dialing', Boolean(match) && phase === 'dialing');
        row.classList.toggle('is-connected-call', Boolean(match) && phase === 'connected');
    });
}

/**
 * Keep Ringing/Connected pad UI aligned with auto-dial lead state while a session runs.
 */
function syncAutoDialLiveCallUi() {
    if (state.mode !== 'auto' || !state.sessionActive || state.paused) {
        return;
    }
    if (isDispositionBlocking() || dispositionSaveInFlight) {
        return;
    }

    const phone = getWebphone();
    if (!phone || phone.hangupInFlight) {
        return;
    }

    const phoneLive = ['dialing', 'ringing', 'in-call'].includes(phone.state)
        || phone.liveCallUiActive
        || phone.awaitingDestinationBridge
        || phone.clickToCallActive
        || phone.timerPhase === 'ringing'
        || phone.timerPhase === 'connected';

    const layer = document.querySelector('[data-dialer-call-layer]');
    const layerHidden = !layer || layer.classList.contains('hidden');

    if (phoneLive && state.currentLead) {
        const connected = phone.state === 'in-call'
            || phone.timerPhase === 'connected'
            || phone._serverConfirmedDestination;
        markLeadRowActive(state.currentLead, connected ? 'connected' : 'dialing');

        if (layerHidden && !phone._outboundCancelled) {
            phone.liveCallUiActive = true;
            phone._callEndedDispatched = false;
            if (connected) {
                phone.refreshDialerCallOverlay?.('in-call');
            } else {
                phone.refreshOutboundRingingUi?.(state.currentLead.phone);
                phone.refreshDialerCallOverlay?.('dialing');
            }
        }

        return;
    }

    // Pad idle + lead stuck Dialing… after the call really ended → require disposition.
    if (
        !phoneLive
        && state.currentLead
        && !autoDialDelayTimer
        && !awaitingNextCallAfterDisposition
        && (phone.state === 'registered' || phone.state === 'offline')
        && !isSummaryModalVisible()
        && !isDispositionPopupLocked()
    ) {
        const context = {
            phone: state.currentLead.phone,
            lead: state.currentLead,
            callUuid: phone.hangupCallUuid?.() || phone._lastEndedUuid || '',
            durationSec: 0,
            connected: false,
            result: 'No answer',
        };
        clearDialingLeadHighlight();
        showSummaryModal(context);
    }
}

function startAutoDialUiSync() {
    if (autoDialUiSyncTimer) {
        return;
    }
    autoDialUiSyncTimer = window.setInterval(() => {
        syncAutoDialLiveCallUi();
    }, 800);
}

function stopAutoDialUiSync() {
    if (!autoDialUiSyncTimer) {
        return;
    }
    window.clearInterval(autoDialUiSyncTimer);
    autoDialUiSyncTimer = null;
}

/**
 * Build dial queue from the Imported leads list.
 * Starts from the TOP row and works downward so the first lead is dialed first.
 */
function loadQueueFromDom(root = hubRoot(), { rebuild = true } = {}) {
    const rows = root?.querySelectorAll('[data-dialer-lead-row]') || [];
    const skipIds = new Set(disposedLeadIds);
    const skipPhoneKeys = new Set(disposedPhoneKeys);

    if (state.currentLead?.id) {
        skipIds.add(Number(state.currentLead.id));
    }
    if (state.currentLead?.phone) {
        const key = normalizePhoneDigits(state.currentLead.phone);
        if (key) {
            skipPhoneKeys.add(key);
        }
    }
    if (state.pendingDisposition?.lead?.id) {
        skipIds.add(Number(state.pendingDisposition.lead.id));
    }
    if (state.pendingDisposition?.phone || state.pendingDisposition?.lead?.phone) {
        const key = normalizePhoneDigits(
            state.pendingDisposition.phone || state.pendingDisposition.lead.phone,
        );
        if (key) {
            skipPhoneKeys.add(key);
        }
    }
    if (state.lastDisposedPhone) {
        skipPhoneKeys.add(state.lastDisposedPhone);
    }

    const leads = Array.from(rows).map((row) => ({
        id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
        name: row.dataset.leadName || '',
        contact: row.dataset.leadContact || '',
        phone: row.dataset.leadPhone || '',
        phone_display: row.querySelector('.ghl-dialer-lead-number')?.textContent?.trim() || row.dataset.leadPhone || '',
    })).filter((lead) => {
        if (!lead.phone) {
            return false;
        }
        if (isDisposedLead(lead)) {
            return false;
        }
        if (lead.id && skipIds.has(Number(lead.id))) {
            return false;
        }
        const phoneKey = normalizePhoneDigits(lead.phone);
        if (phoneKey && skipPhoneKeys.has(phoneKey)) {
            return false;
        }

        return true;
    });

    // Top of list first, then next rows downward.
    if (rebuild || !state.sessionActive) {
        state.queue = leads;

        return;
    }

    const existing = new Set(
        state.queue.map((lead) => String(lead.id || lead.phone)),
    );
    leads.forEach((lead) => {
        const key = String(lead.id || lead.phone);
        if (!existing.has(key)) {
            state.queue.push(lead);
            existing.add(key);
        }
    });
}

async function fetchImportedLeads(list, reset = false) {
    const url = list?.dataset.importedLeadsUrl;
    if (!url) {
        return;
    }

    const items = list.querySelector('[data-imported-leads-items]');
    const loading = list.querySelector('[data-imported-leads-loading]');
    const offset = reset ? 0 : Number.parseInt(list.dataset.importedLeadsOffset || '0', 10) || 0;
    const hasMore = list.dataset.importedLeadsHasMore !== '0';

    if (!reset && !hasMore) {
        return;
    }

    const pool = list.closest('[data-phone-leads-pane]')?.querySelector('[data-dialer-leads-pool]')?.value || 'assigned';
    const campaign = list.closest('[data-phone-leads-pane]')?.querySelector('[data-dialer-leads-campaign]')?.value || '';
    const params = new URLSearchParams({ offset: String(offset), per_page: '25', pool });
    if (campaign) {
        params.set('campaign_id', campaign);
    }

    loading?.classList.remove('hidden');

    try {
        const response = await fetch(`${url}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        if (!response.ok) {
            throw new Error(payload.message || 'Could not load imported leads.');
        }

        if (reset) {
            items.innerHTML = '';
        }

        const leads = payload.leads || [];
        const emptyMessage = list.closest('[data-auto-dial-hub]')?.dataset.agentDialer === '1'
            ? 'No leads assigned to you with phone numbers yet.'
            : 'No imported leads with phone numbers yet.';
        if (leads.length === 0 && offset === 0) {
            items.innerHTML = `<p class="ghl-dialer-recent-empty" data-imported-leads-empty>${emptyMessage}</p>`;
        } else {
            items.querySelector('[data-imported-leads-empty]')?.remove();
            const wrapper = document.createElement('div');
            const freshLeads = leads.filter((lead) => !isDisposedLead(lead));
            wrapper.innerHTML = freshLeads.map((lead) => buildLeadRow(lead)).join('');
            wrapper.childNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    items.appendChild(node);
                }
            });
        }

        list.dataset.importedLeadsOffset = String(payload.next_offset ?? offset + leads.length);
        list.dataset.importedLeadsHasMore = payload.has_more ? '1' : '0';
        loadQueueFromDom(hubRoot(), { rebuild: reset || !state.sessionActive });
    } catch (error) {
        console.warn('[auto-dial] imported leads fetch failed', error);
        showToast(error.message || 'Could not load imported leads.', 'error');
    } finally {
        loading?.classList.add('hidden');
    }
}

function syncLeadsSelect(wrapper) {
    const select = wrapper.querySelector('.ghl-leads-select__native');
    const valueEl = wrapper.querySelector('[data-leads-select-value]');
    const menu = wrapper.querySelector('.ghl-leads-select__menu');
    if (!select || !valueEl || !menu) {
        return;
    }

    const selected = select.options[select.selectedIndex];
    valueEl.textContent = selected?.textContent?.trim() || 'Select';

    menu.innerHTML = '';
    Array.from(select.options).forEach((option) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `ghl-leads-select__option${option.selected ? ' is-selected' : ''}`;
        btn.setAttribute('role', 'option');
        btn.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        btn.dataset.value = option.value;
        btn.textContent = option.textContent?.trim() || option.value;
        menu.appendChild(btn);
    });
}

function closeLeadsSelect(wrapper) {
    if (!wrapper) {
        return;
    }
    const trigger = wrapper.querySelector('.ghl-leads-select__trigger');
    const menu = wrapper.querySelector('.ghl-leads-select__menu');
    wrapper.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (menu) {
        menu.hidden = true;
    }
}

function closeAllLeadsSelects(except = null) {
    document.querySelectorAll('[data-leads-select].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeLeadsSelect(wrapper);
        }
    });
}

function initLeadsSelects(root = document) {
    const scope = root === document ? document : root;

    scope.querySelectorAll('[data-leads-select]').forEach((wrapper) => {
        if (wrapper.dataset.leadsSelectBound === '1') {
            syncLeadsSelect(wrapper);
            return;
        }

        wrapper.dataset.leadsSelectBound = '1';
        const select = wrapper.querySelector('.ghl-leads-select__native');
        const trigger = wrapper.querySelector('.ghl-leads-select__trigger');
        const menu = wrapper.querySelector('.ghl-leads-select__menu');
        if (!select || !trigger || !menu) {
            return;
        }

        syncLeadsSelect(wrapper);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const willOpen = !wrapper.classList.contains('is-open');
            closeAllLeadsSelects(wrapper);
            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;
        });

        menu.addEventListener('click', (event) => {
            const option = event.target.closest('[data-value]');
            if (!option || !menu.contains(option)) {
                return;
            }
            event.preventDefault();
            select.value = option.dataset.value ?? '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncLeadsSelect(wrapper);
            closeLeadsSelect(wrapper);
        });

        select.addEventListener('change', () => syncLeadsSelect(wrapper));
    });

    if (document.documentElement.dataset.leadsSelectDocBound !== '1') {
        document.documentElement.dataset.leadsSelectDocBound = '1';
        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-leads-select]')) {
                closeAllLeadsSelects();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAllLeadsSelects();
            }
        });
    }
}

function initImportedLeadsList(root = document) {
    const list = (root === document ? document : root).querySelector('[data-imported-leads-list]');
    if (!list || list.dataset.importedLeadsInit === '1') {
        return;
    }

    list.dataset.importedLeadsInit = '1';
    loadQueueFromDom(hubRoot());
    initLeadsSelects(list.closest('[data-phone-leads-pane]') || root);

    const pane = list.closest('[data-phone-leads-pane]');
    const campaignSelect = pane?.querySelector('[data-dialer-leads-campaign]');
    // Prefer All campaigns so agents don't get stuck on an empty campaign filter.
    if (campaignSelect && campaignSelect.value !== '') {
        campaignSelect.value = '';
        const campaignWrap = campaignSelect.closest('[data-leads-select]');
        if (campaignWrap) {
            syncLeadsSelect(campaignWrap);
        }
    }

    pane?.querySelector('[data-dialer-leads-pool]')?.addEventListener('change', () => {
        if (state.sessionActive) {
            showToast('Stop auto dial before changing the lead pool.', 'info');
            return;
        }
        list.dataset.importedLeadsOffset = '0';
        list.dataset.importedLeadsHasMore = '1';
        void fetchImportedLeads(list, true);
    });
    campaignSelect?.addEventListener('change', () => {
        if (state.sessionActive) {
            showToast('Stop auto dial before changing the campaign filter.', 'info');
            return;
        }
        list.dataset.importedLeadsOffset = '0';
        list.dataset.importedLeadsHasMore = '1';
        void fetchImportedLeads(list, true);
    });

    // Refresh once so agents get dialable assigned leads (skips junk phone placeholders).
    list.dataset.importedLeadsOffset = '0';
    list.dataset.importedLeadsHasMore = '1';
    void fetchImportedLeads(list, true);

    const sentinel = list.querySelector('[data-imported-leads-sentinel]');
    if (sentinel && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                void fetchImportedLeads(list, false);
            }
        }, { root: list, rootMargin: '120px' });
        observer.observe(sentinel);
    }
}

function selectDispositionChip(modal, dispositionBtn) {
    if (!modal || !dispositionBtn) {
        return;
    }

    modal.querySelectorAll('[data-disposition-value]').forEach((el) => el.classList.remove('is-selected'));
    dispositionBtn.classList.add('is-selected');
    state.selectedDisposition = dispositionBtn.dataset.dispositionValue || '';
    const customEl = modal.querySelector('[data-call-summary-custom-disposition]');
    if (customEl) {
        customEl.value = '';
    }
}

function finishDispositionSaveUi(previousLabel = 'Save') {
    dispositionSaveInFlight = false;
    const live = resolveSummaryModalElement();
    if (live) {
        live.dataset.dispositionBusy = '';
        live.querySelector('[data-call-summary-next]')?.removeAttribute('disabled');
        live.querySelector('[data-call-summary-redial]')?.removeAttribute('disabled');
        const label = live.querySelector('[data-call-summary-next-label]');
        if (label) {
            label.textContent = previousLabel;
        }
    }
    syncAutoDialControls();
}

async function submitDispositionAction(modal, { redial = false, dispositionOverride = null } = {}) {
    if (!modal || modal.dataset.dispositionBusy === '1' || dispositionSaveInFlight) {
        return;
    }

    const disposition = String(dispositionOverride || resolveSummaryDisposition(modal) || '').trim();
    if (!disposition) {
        dispositionToast(
            redial ? 'Select or write a disposition before redialing.' : 'Select or write a disposition first.',
            'warning',
        );
        return;
    }

    state.selectedDisposition = disposition;

    const context = { ...(state.pendingDisposition || {}) };
    const note = modal.querySelector('[data-call-summary-note]')?.value?.trim() || '';
    const nextBtn = modal.querySelector('[data-call-summary-next]');
    const redialBtn = modal.querySelector('[data-call-summary-redial]');
    const nextLabel = modal.querySelector('[data-call-summary-next-label]');
    const previousLabel = nextLabel?.textContent
        || (state.mode === 'auto' && state.sessionActive && !state.paused ? 'Save & Next' : 'Save');
    const continueAutoDial = state.mode === 'auto' && state.sessionActive && !state.paused && !redial;

    dispositionSaveInFlight = true;
    modal.dataset.dispositionBusy = '1';
    nextBtn?.setAttribute('disabled', 'disabled');
    redialBtn?.setAttribute('disabled', 'disabled');
    if (!redial && nextLabel) {
        nextLabel.textContent = 'Saving…';
    }

    // Instant close so agents never wait on the popup.
    clearDialerDestinationInput();
    // Block hangup echoes before modal close / SIP cleanup races.
    suppressCallEndedUntilActive = !redial;
    rememberClosedDisposition(context);
    armDispositionDismissGuard(context);
    hideSummaryModal({ force: true, context });

    let payload = null;
    try {
        payload = await saveDisposition(disposition, context, note);
    } catch (error) {
        console.warn('[auto-dial] disposition save threw', error);
        payload = null;
    }

    finishDispositionSaveUi(previousLabel);

    if (!payload) {
        suppressCallEndedUntilActive = false;
        unlockDispositionPopup();
        showSummaryModal(context, { force: true });
        return;
    }

    applyDispositionSideEffects(payload, context, disposition);

    if (redial) {
        suppressCallEndedUntilActive = false;
        unlockDispositionPopup();
        lastDispositionOpenKey = '';
        if (context?.lead) {
            await dialLead(context.lead);
        } else if (context?.phone) {
            await placeOutboundCall(context.phone, context.lead || {});
        }
        return;
    }

    // Keep auto dial running: wait after successful Save (delay from API / config), then dial next.
    if (continueAutoDial) {
        const delayMs = resolveNextCallDelayMs(payload);
        state.nextCallDelayMs = delayMs;
        const delaySec = Math.max(1, Math.ceil(delayMs / 1000));
        showToast(`Disposition saved: ${disposition}. Next call in ${delaySec}s…`, 'success');
        // Re-assert session + suppress hangup echoes through the countdown.
        state.sessionActive = true;
        state.paused = false;
        suppressCallEndedUntilActive = true;
        // Unlock popup lock (Save done) but keep call-ended suppress until next dial starts.
        unlockDispositionPopup();
        lastDispositionOpenKey = '';
        // Ensure disposed lead/phone cannot sit at the front of the rebuilt queue.
        state.currentLead = null;
        clearDialingLeadHighlight();
        switchToLeadsTab();
        loadQueueFromDom(hubRoot(), { rebuild: true });
        awaitingNextCallAfterDisposition = true;
        void dialNextInQueue({ withDelay: true, delayMessage: 'Next call', delayMs })
            .finally(() => {
                awaitingNextCallAfterDisposition = false;
            });
    } else {
        unlockDispositionPopup();
        lastDispositionOpenKey = '';
        awaitingNextCallAfterDisposition = false;
        showToast(`Disposition saved: ${disposition}`, 'success');
    }
}

function initCallSummaryModal() {
    if (callSummaryModalBound || document.documentElement.dataset.callSummaryInit === '1') {
        return;
    }

    // Bind once on document so Turbo remounts / body moves never drop Save handlers.
    callSummaryModalBound = true;
    document.documentElement.dataset.callSummaryInit = '1';

    document.addEventListener('click', (event) => {
        const closeEl = event.target.closest('[data-call-summary-close]');
        if (closeEl) {
            const modal = closeEl.closest('[data-call-summary-modal]');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            dispositionToast('Select or write a disposition to continue.', 'warning');
            modal.querySelector('[data-disposition-value]')?.focus?.();
            return;
        }

        const dispositionBtn = event.target.closest('[data-disposition-value]');
        if (dispositionBtn) {
            const modal = dispositionBtn.closest('[data-call-summary-modal]');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            selectDispositionChip(modal, dispositionBtn);

            const value = String(dispositionBtn.dataset.dispositionValue || '').trim();
            const now = Date.now();
            // Double-click / double-tap same chip → save immediately (more reliable than native dblclick).
            if (
                value
                && dispositionChipTap.value === value
                && (now - dispositionChipTap.at) > 0
                && (now - dispositionChipTap.at) <= 500
            ) {
                dispositionChipTap = { value: '', at: 0 };
                void submitDispositionAction(modal, { redial: false, dispositionOverride: value });

                return;
            }

            dispositionChipTap = { value, at: now };
            return;
        }

        const pauseBtn = event.target.closest('[data-call-summary-pause]');
        if (pauseBtn) {
            const modal = pauseBtn.closest('[data-call-summary-modal]');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }
            event.preventDefault();
            state.paused = !state.paused;
            localStorage.setItem(STORAGE_PAUSED_KEY, state.paused ? '1' : '0');
            modal.querySelector('[data-call-summary-pause]')?.classList.toggle('is-paused', state.paused);
            if (state.paused) {
                clearAutoDialDelay();
            }
            showToast(state.paused ? 'Auto dial paused.' : 'Auto dial resumed.', 'info');
            syncAutoDialControls();
            return;
        }

        const redialBtn = event.target.closest('[data-call-summary-redial]');
        if (redialBtn) {
            const modal = redialBtn.closest('[data-call-summary-modal]');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }
            event.preventDefault();
            void submitDispositionAction(modal, { redial: true });
            return;
        }

        const nextBtn = event.target.closest('[data-call-summary-next]');
        if (nextBtn) {
            const modal = nextBtn.closest('[data-call-summary-modal]');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }
            event.preventDefault();
            void submitDispositionAction(modal, { redial: false });
        }
    });

    // Double-click a disposition chip → save & close (same as Save).
    document.addEventListener('dblclick', (event) => {
        const dispositionBtn = event.target.closest('[data-disposition-value]');
        if (!dispositionBtn) {
            return;
        }
        const modal = dispositionBtn.closest('[data-call-summary-modal]');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const value = String(dispositionBtn.dataset.dispositionValue || '').trim();
        selectDispositionChip(modal, dispositionBtn);
        dispositionChipTap = { value: '', at: 0 };
        void submitDispositionAction(modal, { redial: false, dispositionOverride: value });
    });

    document.addEventListener('input', (event) => {
        const customEl = event.target.closest?.('[data-call-summary-custom-disposition]');
        if (!customEl) {
            return;
        }
        const modal = customEl.closest('[data-call-summary-modal]');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }
        const value = String(customEl.value || '').trim();
        if (!value) {
            return;
        }
        modal.querySelectorAll('[data-disposition-value]').forEach((el) => el.classList.remove('is-selected'));
        state.selectedDisposition = null;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const modal = resolveSummaryModalElement();
        if (!state.pendingDisposition || !modal || modal.classList.contains('hidden')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        dispositionToast('Select or write a disposition to continue.', 'warning');
    }, true);
}

function resolveEndedCallContext(detail = {}) {
    const phone =
        detail.phone
        || state.currentLead?.phone
        || document.querySelector('.ghl-dialer-form--phone [name="destination"]')?.value
        || document.querySelector('[name="destination"]')?.value
        || '';
    const callUuid = detail.callUuid || '';
    const connected = detail.connected === true;
    const durationSec = Number.isFinite(detail.durationSec)
        ? Math.max(0, Number(detail.durationSec))
        : (state.callStartedAt
            ? Math.max(0, Math.round((Date.now() - state.callStartedAt) / 1000))
            : 0);
    const lead = resolveLeadContext(phone) || state.currentLead || null;
    const result = String(detail.result || '').trim()
        || (connected ? 'Connected' : 'No answer');

    return {
        phone: String(phone || '').trim(),
        callUuid,
        durationSec: connected ? durationSec : 0,
        lead,
        result,
        connected,
    };
}

function initDialModeSwitch() {
    const saved = localStorage.getItem(STORAGE_MODE_KEY);
    setDialMode(saved === 'auto' ? 'auto' : 'manual');
    state.paused = localStorage.getItem(STORAGE_PAUSED_KEY) === '1';

    document.querySelectorAll('[data-dial-mode-switch]').forEach((switcher) => {
        if (switcher.dataset.dialModeInit === '1') {
            return;
        }

        switcher.dataset.dialModeInit = '1';
        switcher.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-dial-mode]');
            if (!btn) {
                return;
            }

            setDialMode(btn.dataset.dialMode);
            if (state.mode === 'auto') {
                switchToLeadsTab();
                loadQueueFromDom(hubRoot());
                if (state.queue.length === 0) {
                    showToast('Loading imported leads for auto dial…', 'info');
                }
            } else {
                clearAutoDialDelay();
            }
        });
    });
}

export function markAnsweringMachineDisposition() {
    window.__apexForceDisposition = 'Answering Machine';
}

function resolveLeadContext(phone) {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const leadId = Number.parseInt(form?.dataset.dialLeadId || '0', 10) || null;
    const leadName = form?.dataset.dialLeadName || '';
    const leadPhone = form?.dataset.dialLeadPhone || phone || '';

    if (state.currentLead && (state.currentLead.phone === phone || !phone)) {
        return state.currentLead;
    }

    if (leadId || leadName || leadPhone) {
        return {
            id: leadId,
            name: leadName,
            phone: leadPhone || phone,
        };
    }

    const row = Array.from(document.querySelectorAll('[data-dialer-lead-row]'))
        .find((el) => el.dataset.leadPhone === phone);

    if (row) {
        return {
            id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
            name: row.dataset.leadName || '',
            contact: row.dataset.leadContact || '',
            phone: row.dataset.leadPhone || phone,
        };
    }

    return phone ? { phone, name: '' } : null;
}

function initAutoDialListeners() {
    if (autoDialListenersBound || document.documentElement.dataset.autoDialListenersInit === '1') {
        return;
    }

    autoDialListenersBound = true;
    document.documentElement.dataset.autoDialListenersInit = '1';

    document.addEventListener('click', async (event) => {
        const callBtn = event.target.closest('[data-dial-lead-call]');
        if (!callBtn) {
            return;
        }

        // Auto dial queues calls via Start auto dial — no per-lead phone dialing.
        if (state.mode === 'auto') {
            event.preventDefault();
            showToast(state.sessionActive
                ? 'Auto dial is running — use Stop auto dial first.'
                : 'In Auto dial mode, use Start auto dial. Switch to Manual dial to call a single lead.', 'info');
            return;
        }

        const row = callBtn.closest('[data-dialer-lead-row]');
        if (!row) {
            return;
        }

        event.preventDefault();
        const lead = {
            id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
            name: row.dataset.leadName || '',
            contact: row.dataset.leadContact || '',
            phone: row.dataset.leadPhone || '',
            phone_display: row.querySelector('.ghl-dialer-lead-number')?.textContent?.trim() || '',
        };

        await dialLead(lead);
    });

    window.addEventListener('comm:call-active', (event) => {
        // Do not unlock while disposition is still required.
        if (!isSummaryModalVisible() && !document.body.classList.contains('ch-disposition-locked')) {
            unlockDispositionPopup();
        }
        state.callStartedAt = Date.now();
        startAutoDialUiSync();
        const phone = getWebphone();
        if (phone?.state === 'in-call' || phone?.timerPhase === 'connected') {
            markLeadRowActive(state.currentLead || resolveLeadContext(event.detail?.phone || ''), 'connected');
        } else if (state.currentLead || event.detail?.phone) {
            markLeadRowActive(state.currentLead || resolveLeadContext(event.detail?.phone || ''), 'dialing');
        }
        // Keep prior-leg hangup echoes suppressed a few seconds after the next dial starts.
        window.setTimeout(() => {
            suppressCallEndedUntilActive = false;
        }, 10000);
        dispositionSaveInFlight = false;
        const detailPhone = event.detail?.phone || '';
        if (detailPhone && !state.currentLead) {
            state.currentLead = resolveLeadContext(detailPhone);
        }
    });

    window.addEventListener('comm:dial-started', (event) => {
        // Never unlock while Call Summary is still open (late echoes used to reopen it).
        if (isSummaryModalVisible() || document.body.classList.contains('ch-disposition-locked') || state.pendingDisposition) {
            return;
        }
        unlockDispositionPopup();
        lastDispositionOpenKey = '';
        dispositionSaveInFlight = false;
        suppressCallEndedUntilActive = false;
        const detailPhone = event.detail?.phone || '';
        if (detailPhone && !state.currentLead) {
            state.currentLead = resolveLeadContext(detailPhone);
        }
        if (state.currentLead || detailPhone) {
            markLeadRowActive(state.currentLead || resolveLeadContext(detailPhone), 'dialing');
        }
    });

    window.addEventListener('comm:call-ended', (event) => {
        const detail = event.detail || {};
        const phone = getWebphone();

        const leadBeforeClear = state.currentLead;
        const context = resolveEndedCallContext(detail);
        if (!context.lead && leadBeforeClear) {
            context.lead = leadBeforeClear;
        }
        if (!context.phone && leadBeforeClear?.phone) {
            context.phone = leadBeforeClear.phone;
        }

        // Drop duplicate hangup echoes before any UI teardown.
        if (shouldSuppressDispositionReopen(context) || isStaleHangupWhileLive(context)) {
            clearDialingLeadHighlight();
            return;
        }

        // Call Summary already open for this hangup — never stack another popup.
        if (isSummaryModalVisible() || state.pendingDisposition) {
            clearDialingLeadHighlight();
            return;
        }

        // Never open disposition / tear UI while a call is still live (premature ended echoes).
        // Destination hangup sets remoteHangupHandled — that MUST still open Call Summary.
        if (
            phone?.isLiveCallUiActive?.()
            && !phone.hangupInFlight
            && !phone.userInitiatedHangup
            && !phone.remoteHangupHandled
        ) {
            clearDialingLeadHighlight();

            return;
        }

        // Capture lead/phone before clearing form context.
        state.callStartedAt = null;
        // Stop any pending "next call" countdown — disposition is required first.
        clearAutoDialDelay();
        // Drop Dialing… immediately on hangup (lead stays until disposition moves it).
        markPendingDispositionLeadRows(context);
        clearDialingLeadHighlight();
        formClearLeadContext();

        // Always require a disposition after every hangup (manual or auto) — once only.
        showSummaryModal(context);
    });
}

function formClearLeadContext() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    clearDialerDestinationInput();
    if (!form) {
        state.currentLead = null;
        return false;
    }

    delete form.dataset.dialLeadId;
    delete form.dataset.dialLeadName;
    delete form.dataset.dialLeadPhone;
    state.currentLead = null;

    return true;
}

export function initAutoDialHub(root = document) {
    const hasHub = Boolean(root.querySelector('[data-auto-dial-hub]'));
    const hasSummary = Boolean(root.querySelector('[data-call-summary-modal]'));
    const hasPhone = Boolean(root.querySelector('[data-phone-workspace], .ghl-dialer-originate-form'));
    if (!hasHub && !hasSummary && !hasPhone) {
        return;
    }

    initDialModeSwitch();
    initAgentPresence();
    initAgentBreakControls();

    if (!hasHub && !hasSummary) {
        return;
    }

    initCallSummaryModal();
    initAutoDialListeners();

    if (!hasHub) {
        return;
    }

    initImportedLeadsList(root);

    document.querySelectorAll('[data-auto-dial-start]').forEach((btn) => {
        if (btn.dataset.autoDialStartBound === '1') {
            return;
        }
        btn.dataset.autoDialStartBound = '1';
        btn.addEventListener('click', async () => {
            if (isOnBreakOrLunch()) {
                showToast('Finish break/lunch before starting auto dial.', 'warning');
                return;
            }
            if (isDispositionBlocking()) {
                dispositionToast('Save the pending disposition before starting auto dial.', 'warning');
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Starting…';
            setDialMode('auto');
            const started = await startAutoDialSession();
            if (!started) {
                btn.disabled = false;
                btn.textContent = 'Start auto dial';
            }
            syncAutoDialControls();
        });
    });

    document.querySelectorAll('[data-auto-dial-stop]').forEach((btn) => {
        if (btn.dataset.autoDialStopBound === '1') {
            return;
        }
        btn.dataset.autoDialStopBound = '1';
        btn.addEventListener('click', () => {
            stopAutoDialSession();
        });
    });

    syncAutoDialControls();
}

window.initImportedLeadsList = initImportedLeadsList;

export async function startAutoDialSession() {
    if (isDispositionBlocking()) {
        dispositionToast('Save the pending disposition before starting auto dial.', 'warning');
        return false;
    }

    // UI only — don't spam presence here; call-active announces on_call when dialing starts.
    setDialMode('auto', { announcePresence: false });

    state.paused = false;
    state.sessionActive = true;
    state.currentLead = null;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    switchToLeadsTab();

    loadQueueFromDom(hubRoot(), { rebuild: true });
    if (state.queue.length === 0) {
        const list = hubRoot()?.querySelector('[data-imported-leads-list]');
        if (list) {
            await fetchImportedLeads(list, true);
            loadQueueFromDom(hubRoot(), { rebuild: true });
        }
    }

    if (state.queue.length === 0) {
        state.sessionActive = false;
        syncAutoDialControls();
        sendPresenceHeartbeat({ on_call: false });
        showToast('No callable imported leads available for auto dial.', 'info');

        return false;
    }

    syncAutoDialControls();
    // Tell monitoring "auto dial running" once. Dialing fires call-active → on_call
    // with a different signature (not a duplicate of this row).
    sendPresenceHeartbeat({ on_call: false, in_disposition: false });
    startAutoDialUiSync();
    return dialNextInQueue({
        withDelay: true,
        delayMessage: 'Starting call',
        delayMs: resolveNextCallDelayMs(),
    });
}
