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

/** Bumped on Start/Stop so in-flight Save & Next / placeNext cannot keep dialing after Stop. */
let autoDialSessionGeneration = 0;

/** Leads/phones already dispositioned this session — exclude from queue permanently. */
const disposedLeadIds = new Set();
const disposedPhoneKeys = new Set();

let autoDialDelayTimer = null;
let autoDialCountdownTimer = null;
let autoDialUiSyncTimer = null;
let presenceTimer = null;
let presenceOnCall = false;
let presenceInDisposition = false;
/** Latest presence payload waiting while a request is in flight / debounce. */
let presenceQueuedExtra = null;
/** Deduplicate identical heartbeats. */
let lastPresenceSignature = '';
let lastPresenceAt = 0;
/** Debounce timer — coalesce bursty call-active/hangup/save into ONE POST. */
let presenceFlushTimer = null;
/** AbortController unused — kept null; never abort (avoids canceled Network spam). */
let presenceAbortController = null;

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
/**
 * Armed only while a live outbound call is in progress. Cleared as soon as Call
 * Summary opens (or after a successful save) so hangup/UI echoes cannot reopen it.
 */
let dispositionArmed = false;
/**
 * Sticky per hangup cycle: once Call Summary opens (or Save completes), ALL further
 * call-ended echoes are ignored until the next real dial-started. Fixes "comes again
 * after some time" when prior-leg hangup APIs arrive after the next dial arms.
 */
let dispositionHangupConsumed = false;
let summaryFocusTimers = [];
/** UUIDs that already opened Call Summary this session — never reopen for the same leg. */
const handledHangupUuids = new Set();
/**
 * Identity of the dial currently allowed to open Call Summary.
 * Prior-leg hangup echoes (empty uuid / wrong phone) are ignored after Save & Next.
 */
let armedDial = { token: 0, phone: '', uuid: '', at: 0 };
let armedDialTokenSeq = 0;

function rememberHandledHangupUuid(context = {}) {
    const uuid = String(context?.callUuid || '').trim();
    if (!uuid) {
        return;
    }
    handledHangupUuids.add(uuid);
    // Cap memory — keep the newest ~80.
    if (handledHangupUuids.size > 80) {
        const first = handledHangupUuids.values().next().value;
        handledHangupUuids.delete(first);
    }
}

function isHandledHangupUuid(context = {}) {
    const uuid = String(context?.callUuid || '').trim();
    return Boolean(uuid && handledHangupUuids.has(uuid));
}

function setArmedDial(context = {}) {
    const incomingToken = Number(context?.dialToken || 0);
    if (incomingToken > 0) {
        armedDialTokenSeq = Math.max(armedDialTokenSeq, incomingToken);
    } else {
        armedDialTokenSeq += 1;
    }
    armedDial = {
        token: incomingToken > 0 ? incomingToken : armedDialTokenSeq,
        phone: normalizePhoneDigits(context?.phone || context?.lead?.phone || ''),
        uuid: String(context?.callUuid || '').trim(),
        at: Date.now(),
    };

    return armedDial.token;
}

function updateArmedDialUuid(uuid) {
    const next = String(uuid || '').trim();
    if (next) {
        armedDial = { ...armedDial, uuid: next };
    }
}

/**
 * Only the current outbound leg may open Call Summary.
 * Accepts uuid match OR phone match (Morpheus often uses different uuids for agent vs PSTN).
 */
function matchesArmedDial(context = {}) {
    if (!armedDial.token) {
        // Not armed yet — allow if disposition is armed via legacy path.
        return dispositionArmed;
    }

    const uuid = String(context?.callUuid || '').trim();
    const phone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    const detailToken = Number(context?.dialToken || 0);

    // Explicit prior-leg token from webphone.
    if (detailToken > 0 && armedDial.token > 0 && detailToken !== armedDial.token) {
        return false;
    }

    if (uuid && isHandledHangupUuid({ callUuid: uuid })) {
        return false;
    }

    // Prefer exact uuid match when both sides have one.
    if (armedDial.uuid && uuid && uuid === armedDial.uuid) {
        return true;
    }

    // Phone match — primary path for short no-answer legs (uuid often missing/mismatched).
    if (armedDial.phone && phone && phone === armedDial.phone) {
        return true;
    }

    // Hangup has no identity but we are mid-call for this armed dial — still accept once.
    if (!uuid && !phone && dispositionArmed && armedDial.at && (Date.now() - armedDial.at) < 180000) {
        return true;
    }

    // Hangup has identity but arm only has token (dial-started before phone resolved).
    if (!armedDial.phone && !armedDial.uuid && (uuid || phone)) {
        return true;
    }

    // Uuid present on both but different: fall through already failed phone — reject only if
    // we also have no phone to corroborate. (Handled above.)
    return false;
}

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
    // Block hangup echoes for this leg (~45s). Cleared on next dial-started.
    const until = Date.now() + 45000;
    dispositionContextKeys(context).forEach((key) => {
        closedDispositionKeys.set(key, until);
    });
    const now = Date.now();
    closedDispositionKeys.forEach((expires, key) => {
        if (expires <= now) {
            closedDispositionKeys.delete(key);
        }
    });
}

function clearClosedDisposition(context = {}) {
    dispositionContextKeys(context).forEach((key) => {
        closedDispositionKeys.delete(key);
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

/** New outbound attempt — allow one disposition on hangup; clear prior-leg suppressors. */
function armDispositionForNewCall(context = {}) {
    // Never re-arm while Call Summary is still on screen.
    if (
        isSummaryModalVisible()
        || document.body.classList.contains('ch-call-summary-open')
        || dispositionSaveInFlight
    ) {
        return;
    }

    // Stale pending after Save & Next (modal already closed) — drop it so this dial can arm.
    if (state.pendingDisposition && !isSummaryModalVisible()) {
        state.pendingDisposition = null;
        state.selectedDisposition = null;
    }

    // Bind Call Summary to THIS dial only (uuid/phone/token). Prior-leg echoes won't match.
    setArmedDial(context);
    clearClosedDisposition(context);
    dispositionDismissGuard = { key: '', until: 0 };
    lastDispositionOpenKey = '';
    dispositionSaveInFlight = false;
    // New dial may open disposition once when THIS call ends.
    dispositionHangupConsumed = false;
    dispositionArmed = true;
    unlockDispositionPopup();
    // Keep prior-leg hangup echoes suppressed briefly while originate starts.
    // Cleared on call-active / matched hangup so short calls still get Call Summary.
    suppressCallEndedUntilActive = true;
    const suppressMs = state.mode === 'auto' ? 2000 : 1500;
    const armToken = armedDial.token;
    window.setTimeout(() => {
        if (!dispositionHangupConsumed && dispositionArmed && armedDial.token === armToken) {
            suppressCallEndedUntilActive = false;
        }
    }, suppressMs);
}

function shouldSuppressDispositionReopen(context = {}) {
    // Same Morpheus leg already dispositioned — never reopen (auto-dial next-call race).
    if (isHandledHangupUuid(context)) {
        return true;
    }

    // Already handled this hangup cycle (popup open / saved / echo after next dial).
    if (dispositionHangupConsumed) {
        return true;
    }

    // No live call armed → never open (blocks 800ms UI sync + late hangup echoes).
    if (!dispositionArmed) {
        return true;
    }

    // Prior-leg hangup echoes after Save & Next must never open Call Summary.
    if (!matchesArmedDial(context)) {
        return true;
    }

    // Hard lock: first hangup opened Call Summary — ignore hangup-API / SSE echoes.
    if (isDispositionPopupLocked()) {
        return true;
    }

    // Once Call Summary is open / pending / saving, never reopen from hangup echoes.
    if (dispositionSaveInFlight || state.pendingDisposition || isSummaryModalVisible()) {
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

    // Delay break status so it does not compete with dialer first paint.
    window.setTimeout(() => {
        void refreshBreakStatus();
    }, 1200);
}

function syncAutoDialControls() {
    document.body.classList.toggle('ch-dial-auto-session', state.mode === 'auto' && state.sessionActive);

    // Do NOT clear pendingDisposition while a hangup cycle is still consumed / suppressed —
    // auto-dial Save & Next used to unlock + sync, wipe pending, then late hangups reopened Call Summary.
    if (
        state.pendingDisposition
        && !isSummaryModalVisible()
        && !dispositionSaveInFlight
        && !isDispositionPopupLocked()
        && !document.body.classList.contains('ch-call-summary-open')
        && !dispositionHangupConsumed
        && !suppressCallEndedUntilActive
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
        btn.textContent = onBreak ? 'On break' : 'Start Auto Dial';
    });

    stopBtns.forEach((btn) => {
        btn.classList.toggle('hidden', !running);
        btn.disabled = false;
        if (!btn.dataset.autoDialStopLabelLocked) {
            btn.textContent = 'Stop Auto Dial';
        }
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
    // Invalidate any in-flight countdown / Save & Next / placeNext loops.
    autoDialSessionGeneration += 1;
    state.sessionActive = false;
    state.paused = false;
    awaitingNextCallAfterDisposition = false;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    clearAutoDialDelay();
    stopAutoDialUiSync();
    clearDialingLeadHighlight();

    // Cancel ringing originate so Stop feels immediate; leave connected calls alone
    // so the agent can finish and disposition the live conversation.
    const phone = getWebphone();
    if (phone) {
        const connected = phone.state === 'in-call' || phone.timerPhase === 'connected';
        if (!connected) {
            phone.cancelOutboundAttempt?.('auto-dial-stop');
            phone.clickToCallActive = false;
            phone.awaitingDestinationBridge = false;
            phone.outboundWaitingActive = false;
            phone.liveCallUiActive = false;
            if (!phone.hangupInFlight) {
                void phone.hangup?.('auto-dial-stop')?.catch?.(() => {});
            }
        }
    }

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

    if (state.mode !== 'auto' || !state.sessionActive || state.paused || isDispositionBlocking()) {
        return Promise.resolve(false);
    }

    const sessionGen = autoDialSessionGeneration;
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

            if (
                state.mode !== 'auto'
                || !state.sessionActive
                || state.paused
                || sessionGen !== autoDialSessionGeneration
                || isDispositionBlocking()
            ) {
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
    const location = [lead.city, lead.state].map((part) => String(part || '').trim()).filter(Boolean).join(', ');
    const initial = (name || contact || 'L').charAt(0).toUpperCase();
    const escAttr = (value) => String(value || '').replace(/"/g, '&quot;');
    const dispositionRaw = String(lead.last_disposition || '').trim();
    const disposition = ['new', 'open', 'pending', 'assigned', 'enriched', 'approved', 'ready']
        .includes(dispositionRaw.toLowerCase())
        ? ''
        : dispositionRaw;
    const lastDialed = String(lead.last_dialed_label || '').trim();
    const tags = Array.isArray(lead.tags) ? lead.tags.filter(Boolean).slice(0, 4) : [];
    const extras = Array.isArray(lead.extra_fields) ? lead.extra_fields.filter(Boolean).slice(0, 3) : [];
    const dialMeta = [
        disposition ? `Disp: ${disposition}` : null,
        lastDialed ? `Last dial: ${lastDialed}` : null,
    ].filter(Boolean).join(' · ');
    const hasSide = Boolean(location || tags.length || extras.length || showFileName);
    const sideHtml = hasSide
        ? `<div class="ghl-dialer-lead-side">
                ${location ? `<span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--state" title="${escAttr(location)}">${location}</span>` : ''}
                ${tags.map((tag) => `<span class="ghl-dialer-lead-tag">${escAttr(tag)}</span>`).join('')}
                ${extras.map((field) => `<span class="ghl-dialer-lead-meta" title="${escAttr(field)}">${escAttr(field)}</span>`).join('')}
                ${showFileName ? `<span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--file" title="${escAttr(fileName)}">${fileName}</span>` : ''}
            </div>`
        : '';

    return `
        <div class="ghl-dialer-lead-row${hasSide ? ' has-side' : ''}" data-dialer-lead-row tabindex="0"
            data-lead-id="${lead.id || ''}"
            data-lead-phone="${lead.phone || ''}"
            data-lead-name="${escAttr(name)}"
            data-lead-contact="${escAttr(contact)}"
            data-lead-file-name="${escAttr(fileName)}"
            data-lead-state="${escAttr(lead.state || '')}"
            data-lead-disposition="${escAttr(disposition)}"
            data-lead-last-dialed="${escAttr(lastDialed)}">
            <div class="ghl-dialer-lead-avatar" aria-hidden="true">${initial}</div>
            <div class="ghl-dialer-lead-body">
                <div class="ghl-dialer-lead-main">
                    ${name ? `<span class="ghl-dialer-lead-name">${name}</span>` : ''}
                    ${contact ? `<span class="ghl-dialer-lead-contact">${contact}</span>` : ''}
                    <span class="ghl-dialer-lead-number">${phone}</span>
                    ${dialMeta ? `<span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--dial" title="${escAttr(dialMeta)}">${dialMeta}</span>` : ''}
                </div>
                ${sideHtml}
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
    document.body.classList.toggle('ch-dial-auto-session', state.mode === 'auto' && state.sessionActive);

    if (state.mode !== 'auto') {
        autoDialSessionGeneration += 1;
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
        return '/portal/communications/call-monitoring/presence';
    }
    return '/admin/communications/call-monitoring/presence';
}

function currentExtension() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const synced = form?.querySelector('[data-dial-extension-sync], [name="from_extension"]');
    const raw = synced?.value || form?.querySelector('select[name="from_extension"]')?.value || '';
    return String(raw || '').replace(/\D/g, '');
}

function isDialerPresenceQuietPage() {
    return Boolean(document.querySelector('[data-phone-workspace], .ghl-dialer-originate-form, [data-auto-dial-hub]'));
}

/**
 * Queue presence updates and flush once after a short quiet window.
 * Dialer pages: boot + hangup only (no call-active / disposition / visibility spam).
 */
function sendPresenceHeartbeat(extra = {}) {
    if (Object.prototype.hasOwnProperty.call(extra, 'on_call')) {
        presenceOnCall = Boolean(extra.on_call);
    }
    if (Object.prototype.hasOwnProperty.call(extra, 'in_disposition')) {
        presenceInDisposition = Boolean(extra.in_disposition);
    }

    // Quiet dialer mode: only boot (force) / hangup (call_ended) / break / unload hit the network.
    if (isDialerPresenceQuietPage()) {
        const allow = Boolean(
            extra.force
            || extra.call_ended
            || extra.break_status
            || extra.allow_network,
        );
        if (!allow) {
            return;
        }
    }

    presenceQueuedExtra = { ...(presenceQueuedExtra || {}), ...extra };

    // Hangup / forced login: flush soon. Everything else: wait so bursts collapse.
    const delayMs = (extra.call_ended || extra.force) ? 120 : 900;
    if (presenceFlushTimer) {
        window.clearTimeout(presenceFlushTimer);
    }
    presenceFlushTimer = window.setTimeout(() => {
        presenceFlushTimer = null;
        void flushPresenceHeartbeat();
    }, delayMs);
}

async function flushPresenceHeartbeat() {
    const url = presenceUrl();
    if (!url) {
        presenceQueuedExtra = null;
        return;
    }

    const extra = presenceQueuedExtra || {};
    presenceQueuedExtra = null;

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

    // Do NOT include call_ended in signature — hangup + Call Summary must share one row.
    const signature = [
        state.mode === 'auto' ? 'auto' : 'manual',
        state.sessionActive ? '1' : '0',
        state.paused ? '1' : '0',
        onCall ? '1' : '0',
        inDisposition ? '1' : '0',
        breakStatus,
        breakEndsAt || '',
        currentExtension() || '',
    ].join('|');

    // Identical presence — skip hard (hangup may update within 3s).
    const dedupeMs = (extra.call_ended || extra.force) ? 3000 : 60000;
    if (signature === lastPresenceSignature && (Date.now() - lastPresenceAt) < dedupeMs) {
        return;
    }

    if (window.__apexPresenceInFlight) {
        presenceQueuedExtra = { ...(presenceQueuedExtra || {}), ...extra };
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
    };

    if (isOnBreakOrLunch()) {
        body.break_status = breakActivity.type === 'lunch' ? 'lunch' : 'break';
        body.break_ends_at = breakActivity.ends_at || breakEndsAt;
        body.auto_paused = true;
        body.on_call = false;
    } else if (!Object.prototype.hasOwnProperty.call(extra, 'break_status')) {
        body.break_status = 'none';
        body.break_ends_at = null;
    }

    if (extra.call_ended) {
        body.call_ended = true;
    }

    window.__apexPresenceInFlight = true;
    lastPresenceSignature = signature;
    lastPresenceAt = Date.now();
    try {
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
        });
    } catch {
        // Presence is best-effort for monitoring.
    } finally {
        presenceAbortController = null;
        window.__apexPresenceInFlight = false;
        if (presenceQueuedExtra) {
            void flushPresenceHeartbeat();
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
    let presenceBooted = false;

    const bootPresenceOnce = () => {
        if (presenceBooted) {
            return;
        }
        presenceBooted = true;
        // One login / registered signal. Dialer: no interval polling (SIP WS owns realtime).
        sendPresenceHeartbeat({ on_call: false, in_disposition: false, force: true });
        if (presenceTimer) {
            window.clearInterval(presenceTimer);
            presenceTimer = null;
        }
        // Non-dialer pages keep a slow keepalive; dialer stays event-only (hangup).
        if (!isDialerPresenceQuietPage()) {
            presenceTimer = window.setInterval(() => {
                sendPresenceHeartbeat({
                    on_call: presenceOnCall,
                    in_disposition: presenceInDisposition,
                    allow_network: true,
                });
            }, 120000);
        }
    };

    window.addEventListener('comm:call-active', () => {
        // Local state only on dialer — monitoring already sees LIVE from originate.
        if (
            presenceInDisposition
            || document.body.classList.contains('ch-call-summary-open')
            || document.body.classList.contains('ch-disposition-locked')
        ) {
            return;
        }
        presenceOnCall = true;
        presenceInDisposition = false;
        // Non-dialer pages may still announce on-call.
        if (!isDialerPresenceQuietPage()) {
            sendPresenceHeartbeat({ on_call: true, in_disposition: false });
        }
    });

    window.addEventListener('comm:call-ended', (event) => {
        presenceOnCall = false;
        presenceInDisposition = true;
        const phone = event?.detail?.phone || event?.detail?.destination || '';
        // ONE hangup presence for the whole call cycle (Call Summary / Save do not POST again).
        sendPresenceHeartbeat({
            on_call: false,
            call_ended: true,
            in_disposition: true,
            disposition_phone: phone || null,
        });
    });

    // Prefer one presence after SIP Registered; fall back if no webphone panel.
    window.addEventListener('apex:webphone-state', (event) => {
        const state = String(event?.detail?.state || '').toLowerCase();
        if (state === 'registered' || state === 'ready' || state === 'online') {
            bootPresenceOnce();
        }
    });

    const hasWebphone = Boolean(document.querySelector('[data-webphone-panel]'));
    if (!hasWebphone) {
        bootPresenceOnce();
    } else {
        window.setTimeout(() => {
            try {
                if (document.documentElement.dataset.webphoneRegistered === '1') {
                    bootPresenceOnce();
                }
            } catch {
                // ignore
            }
            if (!presenceBooted) {
                bootPresenceOnce();
            }
        }, 4000);
    }

    // Visibility must not spam /presence on dialer.
    window.addEventListener('visibilitychange', () => {
        if (!document.hidden && presenceBooted && !isDialerPresenceQuietPage()) {
            sendPresenceHeartbeat({
                on_call: presenceOnCall,
                in_disposition: presenceInDisposition,
            });
        }
    });

    window.addEventListener('beforeunload', () => {
        if (!presenceBooted) {
            return;
        }
        sendPresenceHeartbeat({
            on_call: presenceOnCall,
            in_disposition: presenceInDisposition,
            force: true,
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

    // Consume only once we know Call Summary will actually open.
    if (!force) {
        dispositionArmed = false;
        dispositionHangupConsumed = true;
        suppressCallEndedUntilActive = true;
    }

    // Intentional reopen (failed save) clears the dismiss guard / lock briefly.
    if (force) {
        dispositionHangupConsumed = false;
        dispositionArmed = true;
        dispositionDismissGuard = { key: '', until: 0 };
        unlockDispositionPopup();
    }

    // Lock immediately — before any async hangup-API teardown can echo call-ended.
    lockDispositionPopup();
    dispositionHangupConsumed = true;
    rememberHandledHangupUuid(context);

    // Never continue the queue while disposition is required.
    clearAutoDialDelay();
    clearDialerDestinationInput();
    clearDialingLeadHighlight();
    dismissAllToasts();
    // Kill ringing + call-events WS immediately so old uuid sockets do not linger.
    const phone = getWebphone();
    if (phone) {
        phone.clickToCallActive = false;
        phone.awaitingDestinationBridge = false;
        phone.pendingClickToCall = false;
        phone.outboundWaitingActive = false;
        phone.cancelOutboundAttempt?.('disposition');
        phone.stopCallEventsStream?.();
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

    // Preserve sheet/file name into Call Summary so Call logs show it after Save.
    if (context?.lead && !String(context.lead.file_name || context.lead.workflow || '').trim()) {
        const fromForm = document.querySelector('.ghl-dialer-originate-form')?.dataset?.dialLeadFileName || '';
        const fromRow = context.lead.id
            ? document.querySelector(`[data-dialer-lead-row][data-lead-id="${context.lead.id}"]`)?.dataset?.leadFileName
            : (context.phone
                ? document.querySelector(`[data-dialer-lead-row][data-lead-phone="${CSS.escape?.(context.phone) || context.phone}"]`)?.dataset?.leadFileName
                : '');
        const fileName = String(fromForm || fromRow || '').trim();
        if (fileName) {
            context.lead.file_name = fileName;
            context.lead.workflow = fileName;
        }
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

    // Flags only — presence already posted from comm:call-ended (avoid duplicate Network rows).
    presenceInDisposition = true;
    presenceOnCall = false;

    modal.querySelectorAll('[data-disposition-value]').forEach((btn) => {
        const value = String(btn.dataset.dispositionValue || '').trim();
        const selected = Boolean(suggested) && value.toLowerCase() === suggested.toLowerCase();
        btn.classList.toggle('is-selected', selected);
    });
    if (suggested && customDispositionEl && !modal.querySelector('[data-disposition-value].is-selected')) {
        customDispositionEl.value = suggested;
    }

    // Cancel any prior focus timers so we never focus a hidden/aria-hidden modal.
    summaryFocusTimers.forEach((id) => window.clearTimeout(id));
    summaryFocusTimers = [];

    modal.classList.remove('hidden', 'is-closing');
    modal.classList.add('is-opening');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ch-call-summary-open', 'ch-disposition-locked');
    syncAutoDialControls();

    try {
        modal.style.zIndex = '2147483000';
        if (!modal.hasAttribute('tabindex')) {
            modal.setAttribute('tabindex', '-1');
        }
    } catch {
        // ignore
    }

    const focusSummarySafe = (targetModal) => {
        if (
            !targetModal
            || !targetModal.isConnected
            || targetModal.classList.contains('hidden')
            || targetModal.getAttribute('aria-hidden') === 'true'
        ) {
            return;
        }
        const focusEl = targetModal.querySelector('[data-disposition-value].is-selected')
            || targetModal.querySelector('[data-disposition-value]')
            || targetModal.querySelector('[data-call-summary-next]')
            || targetModal;
        try {
            focusEl?.focus?.({ preventScroll: true });
        } catch {
            // ignore
        }
    };

    window.requestAnimationFrame(() => {
        modal.classList.add('is-visible');
        modal.classList.remove('is-opening');
        focusSummarySafe(modal);
    });

    summaryFocusTimers.push(window.setTimeout(() => {
        focusSummarySafe(resolveSummaryModalElement() || modal);
    }, 120));
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

    summaryFocusTimers.forEach((id) => window.clearTimeout(id));
    summaryFocusTimers = [];

    modals.forEach((modal) => {
        // Blur focused chips BEFORE aria-hidden — Chrome blocks aria-hidden on focused descendants.
        const active = document.activeElement;
        if (active && modal.contains(active) && typeof active.blur === 'function') {
            active.blur();
        }
        modal.classList.remove('is-visible', 'is-opening');
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        modal.dataset.dispositionBusy = '';
        modal.querySelector('[data-call-summary-next]')?.removeAttribute('disabled');
        modal.querySelector('[data-call-summary-redial]')?.removeAttribute('disabled');
    });
    document.body.classList.remove('ch-call-summary-open');
    try {
        document.body.focus?.({ preventScroll: true });
    } catch {
        // ignore
    }
    // Keep pendingDisposition / lastDispositionOpenKey until Save finishes successfully
    // when force-closing for save — clearing them early let hangup echoes reopen the popup.
    if (!force) {
        state.pendingDisposition = null;
        state.selectedDisposition = null;
        lastDispositionOpenKey = '';
    } else {
        state.selectedDisposition = null;
    }
    presenceInDisposition = false;
    // Dialer: hangup already posted presence — do not POST again on modal close.
    if (!dispositionSaveInFlight && !isDialerPresenceQuietPage()) {
        sendPresenceHeartbeat({ in_disposition: false, on_call: presenceOnCall });
    }
    syncAutoDialControls();
}

/** In-flight disposition POSTs keyed by call/phone — prevents duplicate messy saves. */
const dispositionSaveLocks = new Map();

function dispositionSaveLockKey(context = {}, disposition = '') {
    const uuid = String(context?.callUuid || '').trim();
    const phone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    const label = String(disposition || '').trim().toLowerCase();

    return `${uuid}|${phone}|${label}`;
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

    const lockKey = dispositionSaveLockKey(context, disposition);
    if (dispositionSaveLocks.has(lockKey)) {
        return dispositionSaveLocks.get(lockKey);
    }

    const note = noteOverride !== null
        ? String(noteOverride || '').trim()
        : (document.querySelector('[data-call-summary-note]')?.value?.trim() || '');
    const active = window.__apexActiveCallNotes?.read?.() || {};
    const inCallNotes = String(active.notes || context?.inCallNotes || '').trim();
    const durationSec = Number.isFinite(context.durationSec)
        ? Math.max(0, Number(context.durationSec))
        : 0;
    const connected = context.connected === true
        || ['connected', 'answered', 'initiated'].includes(
            String(context.result || '').trim().toLowerCase(),
        );
    const callResult = connected
        ? (String(context.result || '').trim().toLowerCase() === 'initiated' ? 'initiated' : 'connected')
        : (String(context.result || '').trim() || 'no-answer');

    const body = JSON.stringify({
        disposition,
        note: note || null,
        in_call_notes: inCallNotes || null,
        call_uuid: context.callUuid || null,
        phone: context.phone || context.lead?.phone || null,
        lead_id: context.lead?.id || null,
        duration_sec: durationSec,
        connected,
        call_result: callResult,
        dial_mode: state.mode === 'auto' ? 'auto' : 'manual',
    });

    // No AbortController — UI already closed optimistically; never cancel this POST.
    const request = (async () => {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                keepalive: true,
                body,
            });

            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.saved !== true) {
                throw new Error(payload.message || 'Could not save disposition.');
            }

            return payload;
        } finally {
            dispositionSaveLocks.delete(lockKey);
        }
    })();

    dispositionSaveLocks.set(lockKey, request);

    try {
        return await request;
    } catch (error) {
        console.warn('[auto-dial] disposition save background failed', error);
        dispositionToast(error.message || 'Could not save disposition. Please try again.', 'error');

        return null;
    }
}

function buildOptimisticDispositionPayload(disposition, context = {}, note = '') {
    const phone = context?.phone || context?.lead?.phone || '';
    const leadId = context?.lead?.id || null;
    const durationSec = Number.isFinite(context?.durationSec)
        ? Math.max(0, Number(context.durationSec))
        : 0;
    const connected = context?.connected === true
        || ['connected', 'answered', 'initiated'].includes(
            String(context?.result || '').trim().toLowerCase(),
        );
    const callResult = connected
        ? (String(context?.result || '').trim().toLowerCase() === 'initiated' ? 'initiated' : 'connected')
        : (String(context?.result || '').trim() || 'no-answer');

    return {
        saved: true,
        async: true,
        disposition,
        next_call_delay_sec: Math.max(0, Math.round((resolveNextCallDelayMs() || DEFAULT_NEXT_CALL_DELAY_MS) / 1000)),
        lead: leadId ? { id: leadId, removed: true, marked_ids: [leadId] } : null,
        lead_removed: Boolean(leadId),
        call_log: {
            id: context?.callUuid || `local:optimistic:${Date.now()}`,
            direction: 'outbound',
            phone,
            phone_display: context?.lead?.phone_display || phone,
            disposition,
            result: callResult,
            note: note || null,
            call_note: note || null,
            duration: durationSec,
            duration_sec: durationSec,
            duration_label: durationSec > 0 ? `${durationSec}s` : '0s',
            time_ago: 'just now',
            lead_id: leadId,
            lead_name: context?.lead?.name || '',
            lead_contact: context?.lead?.contact || context?.lead?.owner_name || '',
            lead_file_name: context?.lead?.file_name || context?.lead?.workflow || null,
            source: 'optimistic',
        },
    };
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

function isPipelineStatusOnly(value) {
    const normalized = String(value || '').trim().toLowerCase().replace(/[\s_-]+/g, '_');
    return ['', 'new', 'open', 'pending', 'assigned', 'enriched', 'approved', 'ready'].includes(normalized);
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

function shouldSkipImportedLead(lead) {
    if (!lead) {
        return true;
    }
    // Only hide leads disposed in this browser session. The imported-leads API already
    // returns undialed assigned rows — re-filtering by disposition/attempts emptied the
    // list while the count stayed high.
    return isDisposedLead(lead);
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
        lead_file_name: callLog.lead_file_name
            || context?.lead?.file_name
            || context?.lead?.workflow
            || document.querySelector('.ghl-dialer-originate-form')?.dataset?.dialLeadFileName
            || '',
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
    const markedIds = Array.isArray(payload?.lead?.marked_ids) ? payload.lead.marked_ids : [];
    markedIds.forEach((id) => rememberDisposedLead(id, phone));

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
        delete form.dataset.dialLeadFileName;
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
        const fileName = String(lead.file_name || lead.workflow || '').trim();
        if (fileName) {
            form.dataset.dialLeadFileName = fileName;
        } else {
            delete form.dataset.dialLeadFileName;
        }
    }

    // Clear prior-leg hangup leftovers before originate so the call can connect.
    const prepPhone = getWebphone();
    if (prepPhone && !document.querySelector('[data-call-summary-modal]:not(.hidden)')) {
        prepPhone.hangupInFlight = false;
        prepPhone._callEndedDispatched = false;
        prepPhone.userInitiatedHangup = false;
        prepPhone.remoteHangupHandled = false;
        prepPhone._outboundCancelled = false;
        document.body.classList.remove('ch-disposition-locked', 'ch-call-summary-open');
    }

    const dialed = await placeOutboundCall(lead.phone, lead);
    if (!dialed) {
        state.currentLead = null;
        markLeadRowActive({ id: null, phone: '' }, '');

        return false;
    }

    // Re-assert Ringing/Connected UI if the pad was cleared while auto dial is still live.
    // NEVER reset hangup-dispatch flags here — that re-armed prior-leg echoes on Save & Next.
    const phone = getWebphone();
    if (
        phone
        && !phone.hangupInFlight
        && !phone._outboundCancelled
        && !dispositionHangupConsumed
        && !document.body.classList.contains('ch-call-summary-open')
    ) {
        phone.liveCallUiActive = true;
        if (phone.state === 'in-call' || phone.timerPhase === 'connected') {
            phone.refreshDialerCallOverlay?.('in-call');
            markLeadRowActive(lead, 'connected');
        } else {
            phone.refreshOutboundRingingUi?.(lead.phone, { restartTimer: false });
            phone.refreshDialerCallOverlay?.('dialing');
            markLeadRowActive(lead, 'dialing');
        }
    }
    startAutoDialUiSync();

    return true;
}

async function dialNextInQueue({ withDelay = false, delayMessage = 'Next call', delayMs = null } = {}) {
    if (state.mode !== 'auto' || !state.sessionActive || state.paused || isDispositionBlocking()) {
        return false;
    }

    const sessionGen = autoDialSessionGeneration;

    const placeNext = async (attempt = 0) => {
        if (
            !state.sessionActive
            || sessionGen !== autoDialSessionGeneration
            || state.paused
            || state.mode !== 'auto'
            || isDispositionBlocking()
        ) {
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
            if (!state.sessionActive || sessionGen !== autoDialSessionGeneration) {
                return false;
            }
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

        if (!state.sessionActive || sessionGen !== autoDialSessionGeneration) {
            return false;
        }

        markLeadRowActive(next, 'dialing');

        const dialed = await dialLead(next);
        syncAutoDialControls();
        if (!dialed) {
            if (!state.sessionActive || sessionGen !== autoDialSessionGeneration) {
                return false;
            }
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
            // Never reset _callEndedDispatched here — that re-armed hangup echoes
            // and caused the disposition popup to open again and again.
            if (connected) {
                phone.refreshDialerCallOverlay?.('in-call');
            } else {
                phone.refreshOutboundRingingUi?.(state.currentLead.phone, { restartTimer: false });
                phone.refreshDialerCallOverlay?.('dialing');
            }
        }

        return;
    }

    // Do NOT open Call Summary from the 800ms sync loop — that caused popup loops.
    // Disposition opens only from the single comm:call-ended handler.
}

function startAutoDialUiSync() {
    if (autoDialUiSyncTimer) {
        return;
    }
    autoDialUiSyncTimer = window.setInterval(() => {
        syncAutoDialLiveCallUi();
    }, 1500);
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
        file_name: row.dataset.leadFileName || '',
        workflow: row.dataset.leadFileName || '',
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
    const fileChecks = list.closest('[data-phone-leads-pane]')?.querySelector('[data-dialer-leads-files]');
    const selectedFiles = [...(fileChecks?.querySelectorAll('[data-dialer-file-id]:checked') || [])]
        .map((input) => input.value)
        .filter(Boolean);
    const params = new URLSearchParams({ offset: String(offset), per_page: '25', pool });
    if (campaign) {
        params.set('campaign_id', campaign);
    }
    selectedFiles.forEach((id) => params.append('workflow_ids[]', id));

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

        const leads = Array.isArray(payload.leads) ? payload.leads : [];
        const emptyMessage = list.closest('[data-auto-dial-hub]')?.dataset.agentDialer === '1'
            ? 'No leads assigned to you with phone numbers yet.'
            : 'No imported leads with phone numbers yet.';
        if (leads.length === 0 && offset === 0) {
            items.innerHTML = `<p class="ghl-dialer-recent-empty" data-imported-leads-empty>${emptyMessage}</p>`;
        } else {
            items.querySelector('[data-imported-leads-empty]')?.remove();
            const wrapper = document.createElement('div');
            const freshLeads = leads.filter((lead) => !shouldSkipImportedLead(lead));
            wrapper.innerHTML = freshLeads.map((lead) => buildLeadRow(lead)).join('');
            wrapper.childNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    items.appendChild(node);
                }
            });
            if (reset && freshLeads.length === 0) {
                const total = Number(payload.total || 0);
                items.innerHTML = total > 0
                    ? `<p class="ghl-dialer-recent-empty" data-imported-leads-empty>Leads are loading — try shrinking filters or click Start auto dial again.</p>`
                    : `<p class="ghl-dialer-recent-empty" data-imported-leads-empty>${emptyMessage}</p>`;
            }
        }

        list.dataset.importedLeadsOffset = String(payload.next_offset ?? offset + leads.length);
        list.dataset.importedLeadsHasMore = payload.has_more ? '1' : '0';
        if (typeof payload.total === 'number') {
            list.dataset.importedLeadsTotal = String(payload.total);
        }
        updateImportedLeadsCount(list, payload);
        if (reset) {
            hydrateDialerLeadFilters(list, payload);
        }
        loadQueueFromDom(hubRoot(), { rebuild: reset || !state.sessionActive });
    } catch (error) {
        console.warn('[auto-dial] imported leads fetch failed', error);
        showToast(error.message || 'Could not load imported leads.', 'error');
    } finally {
        loading?.classList.add('hidden');
    }
}

function updateImportedLeadsCount(list, payload = {}) {
    const pane = list?.closest('[data-phone-leads-pane]');
    const countEl = pane?.querySelector('[data-imported-leads-count]');
    if (!countEl) {
        return;
    }

    const total = Number.parseInt(String(payload.total ?? list.dataset.importedLeadsTotal ?? '0'), 10);
    const rendered = list.querySelectorAll('[data-dialer-lead-row]').length;
    const hasMore = payload.has_more === true || list.dataset.importedLeadsHasMore === '1';
    const display = Number.isFinite(total) && total > 0 ? total : rendered;
    countEl.textContent = `${display.toLocaleString()}${hasMore && display > 0 ? '+' : ''}`;
}

function hydrateDialerLeadFilters(list, payload = {}) {
    const pane = list?.closest('[data-phone-leads-pane]');
    if (!pane) {
        return;
    }

    const campaignSelect = pane.querySelector('[data-dialer-leads-campaign]');
    const campaigns = Array.isArray(payload.campaigns) ? payload.campaigns : [];
    if (campaignSelect && campaigns.length > 0) {
        const current = campaignSelect.value || '';
        let added = false;
        campaigns.forEach((campaign) => {
            const id = String(campaign?.id ?? '');
            if (!id || [...campaignSelect.options].some((opt) => opt.value === id)) {
                return;
            }
            const option = document.createElement('option');
            option.value = id;
            option.textContent = String(campaign?.name || `Campaign #${id}`);
            campaignSelect.appendChild(option);
            added = true;
        });
        if (current) {
            campaignSelect.value = current;
        }
        if (added || campaigns.length > 0) {
            pane.querySelector('[data-dialer-campaign-field]')?.classList.remove('hidden');
        }
        const wrapper = campaignSelect.closest('[data-leads-select]');
        if (wrapper) {
            syncLeadsSelect(wrapper);
        }
    }

    const filesWrap = pane.querySelector('[data-dialer-leads-files]');
    const files = Array.isArray(payload.files) ? payload.files : [];
    if (!filesWrap) {
        return;
    }

    const allBox = filesWrap.querySelector('[data-dialer-file-all]');
    const previouslyChecked = new Set(
        [...filesWrap.querySelectorAll('[data-dialer-file-id]:checked')].map((input) => String(input.value)),
    );
    const allWasChecked = Boolean(allBox?.checked);
    filesWrap.querySelectorAll('[data-dialer-file-id]').forEach((input) => {
        input.closest('label')?.remove();
    });

    files.forEach((file) => {
        const id = String(file?.id ?? '');
        if (!id) {
            return;
        }
        let label = String(file?.name || `Import #${id}`);
        if (Number(file?.total_leads || 0) > 0) {
            label += ` (${Number(file.total_leads).toLocaleString()})`;
        }
        const row = document.createElement('label');
        row.className = 'ghl-dialer-file-check';
        const checked = !allWasChecked && previouslyChecked.has(id);
        row.innerHTML = `<span title="${label.replace(/"/g, '&quot;')}">${label}</span>
            <input type="checkbox" value="${id}" data-dialer-file-id${checked ? ' checked' : ''}>`;
        filesWrap.appendChild(row);
    });

    if (allBox) {
        allBox.checked = allWasChecked || previouslyChecked.size === 0;
    }

    const field = filesWrap.closest('.ghl-dialer-leads-field--files')
        || pane.querySelector('[data-dialer-files-field]');
    if (files.length === 0) {
        field?.classList.add('hidden');
        return;
    }

    if (field) {
        field.classList.remove('hidden');
        field.classList.add('is-files-expanded');
        field.classList.remove('is-files-shrunk');
    }
    filesWrap.classList.add('is-expanded');
    const toggle = pane.querySelector('[data-dialer-files-size-toggle]');
    const label = toggle?.querySelector('[data-dialer-files-size-label]');
    toggle?.setAttribute('aria-expanded', 'true');
    toggle?.setAttribute('title', 'Shrink file list');
    if (label) {
        label.textContent = 'Shrink';
    }
    toggle?.querySelector('[data-dialer-files-size-icon-expand]')?.classList.add('hidden');
    toggle?.querySelector('[data-dialer-files-size-icon-shrink]')?.classList.remove('hidden');
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

    const filesWrap = pane?.querySelector('[data-dialer-leads-files]');
    const filesField = filesWrap?.closest('.ghl-dialer-leads-field--files') || filesWrap?.parentElement;
    const allFilesBox = filesWrap?.querySelector('[data-dialer-file-all]');
    const fileBoxes = () => [...(filesWrap?.querySelectorAll('[data-dialer-file-id]') || [])];
    const filesSizeToggle = pane?.querySelector('[data-dialer-files-size-toggle]');
    const filesSizeLabel = filesSizeToggle?.querySelector('[data-dialer-files-size-label]');
    const expandIcon = filesSizeToggle?.querySelector('[data-dialer-files-size-icon-expand]');
    const shrinkIcon = filesSizeToggle?.querySelector('[data-dialer-files-size-icon-shrink]');
    const syncFilesSizeUi = (expanded) => {
        filesWrap?.classList.toggle('is-expanded', expanded);
        filesField?.classList.toggle('is-files-expanded', expanded);
        filesField?.classList.toggle('is-files-shrunk', !expanded);
        filesSizeToggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        filesSizeToggle?.setAttribute('title', expanded ? 'Shrink file list' : 'Expand file list');
        if (filesSizeLabel) {
            filesSizeLabel.textContent = expanded ? 'Shrink' : 'Expand';
        }
        expandIcon?.classList.toggle('hidden', expanded);
        shrinkIcon?.classList.toggle('hidden', !expanded);
    };
    const isAgentDialer = hubRoot()?.dataset?.agentDialer === '1'
        || Boolean(document.querySelector('[data-auto-dial-hub][data-agent-dialer="1"]'));
    // Agents need uploaded sheets visible so they can choose which list to dial.
    const hasFileChoices = fileBoxes().length > 0;
    syncFilesSizeUi(Boolean(isAgentDialer && hasFileChoices));
    filesSizeToggle?.addEventListener('click', () => {
        const expanded = !filesWrap?.classList.contains('is-expanded');
        syncFilesSizeUi(expanded);
    });

    const filtersWrap = pane?.querySelector('[data-dialer-leads-filters]');
    const filtersSizeToggle = pane?.querySelector('[data-dialer-filters-size-toggle]');
    const filtersSizeLabel = filtersSizeToggle?.querySelector('[data-dialer-filters-size-label]');
    const syncFiltersSizeUi = (expanded) => {
        filtersWrap?.classList.toggle('is-expanded', expanded);
        filtersWrap?.classList.toggle('is-shrunk', !expanded);
        filtersSizeToggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        filtersSizeToggle?.setAttribute('title', expanded ? 'Shrink filters' : 'Expand filters');
        if (filtersSizeLabel) {
            filtersSizeLabel.textContent = expanded ? 'Shrink' : 'Expand';
        }
    };
    // Keep filters open for agents so My lead sheets stay usable.
    syncFiltersSizeUi(true);
    filtersSizeToggle?.addEventListener('click', () => {
        const expanded = !filtersWrap?.classList.contains('is-expanded');
        syncFiltersSizeUi(expanded);
    });

    const leadsPane = pane;
    const listSizeToggle = pane?.querySelector('[data-dialer-leads-list-size-toggle]');
    const listSizeLabel = listSizeToggle?.querySelector('[data-dialer-leads-list-size-label]');
    const syncListSizeUi = (expanded) => {
        leadsPane?.classList.toggle('is-list-expanded', expanded);
        leadsPane?.classList.toggle('is-list-shrunk', !expanded);
        listSizeToggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        listSizeToggle?.setAttribute('title', expanded ? 'Shrink leads list' : 'Expand leads list');
        if (listSizeLabel) {
            listSizeLabel.textContent = expanded ? 'Shrink list' : 'Expand list';
        }
    };
    // Agents start with an expanded lead list so they can pick who to dial.
    syncListSizeUi(Boolean(isAgentDialer));
    listSizeToggle?.addEventListener('click', () => {
        const expanded = !leadsPane?.classList.contains('is-list-expanded');
        syncListSizeUi(expanded);
    });

    const reloadForFiles = () => {
        if (state.sessionActive) {
            showToast('Stop auto dial before changing the uploaded file filter.', 'info');
            return;
        }
        list.dataset.importedLeadsOffset = '0';
        list.dataset.importedLeadsHasMore = '1';
        void fetchImportedLeads(list, true);
    };
    allFilesBox?.addEventListener('change', () => {
        if (allFilesBox.checked) {
            fileBoxes().forEach((box) => { box.checked = false; });
        }
        reloadForFiles();
    });
    filesWrap?.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-dialer-file-id]')) {
            return;
        }
        if (target.checked && allFilesBox) {
            allFilesBox.checked = false;
        }
        if (fileBoxes().every((box) => !box.checked) && allFilesBox) {
            allFilesBox.checked = true;
        }
        reloadForFiles();
    });

    // Hydrate leads + sheet filters immediately so agents can pick uploaded files.
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
    const continueGen = autoDialSessionGeneration;
    const continueAutoDial = state.mode === 'auto' && state.sessionActive && !state.paused && !redial;

    dispositionSaveInFlight = true;
    modal.dataset.dispositionBusy = '1';
    nextBtn?.setAttribute('disabled', 'disabled');
    redialBtn?.setAttribute('disabled', 'disabled');

    // Instant close — never wait on the network for disposition.
    clearDialerDestinationInput();
    suppressCallEndedUntilActive = !redial;
    dispositionArmed = false;
    rememberClosedDisposition(context);
    armDispositionDismissGuard(context);
    hideSummaryModal({ force: true, context });
    state.currentLead = null;

    removeLeadFromQueue(
        context?.lead?.id || null,
        context?.phone || context?.lead?.phone || '',
    );

    const payload = buildOptimisticDispositionPayload(disposition, context, note);
    applyDispositionSideEffects(payload, context, disposition);
    finishDispositionSaveUi(previousLabel);

    // Successful UX path — stay consumed until the next dial starts.
    dispositionHangupConsumed = true;
    dispositionArmed = false;
    suppressCallEndedUntilActive = true;

    // Persist in background. Never reopen the modal if the POST is slow/canceled.
    void saveDisposition(disposition, context, note).then((saved) => {
        if (saved?.call_log) {
            applyDispositionSideEffects(saved, context, disposition);
        }
    });

    if (redial) {
        suppressCallEndedUntilActive = false;
        dispositionHangupConsumed = false;
        unlockDispositionPopup();
        lastDispositionOpenKey = '';
        if (context?.lead) {
            await dialLead(context.lead);
        } else if (context?.phone) {
            await placeOutboundCall(context.phone, context.lead || {});
        }
        return;
    }

    const stillAuto = continueAutoDial
        && state.mode === 'auto'
        && state.sessionActive
        && !state.paused
        && continueGen === autoDialSessionGeneration;

    if (stillAuto) {
        const delayMs = resolveNextCallDelayMs(payload);
        state.nextCallDelayMs = delayMs;
        const delaySec = Math.max(1, Math.ceil(delayMs / 1000));
        showToast(`Disposition saved: ${disposition}. Next call in ${delaySec}s…`, 'success');
        suppressCallEndedUntilActive = true;
        dispositionHangupConsumed = true;
        dispositionArmed = false;
        rememberHandledHangupUuid(context);
        state.pendingDisposition = null;
        state.selectedDisposition = null;
        lastDispositionOpenKey = '';
        unlockDispositionPopup();
        const phoneApi = getWebphone();
        if (phoneApi) {
            phoneApi.hangupInFlight = false;
            phoneApi._callEndedDispatched = false;
            phoneApi.userInitiatedHangup = false;
            phoneApi.remoteHangupHandled = false;
            phoneApi.liveCallUiActive = false;
            phoneApi._outboundCancelled = false;
        }
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
        state.pendingDisposition = null;
        awaitingNextCallAfterDisposition = false;
        suppressCallEndedUntilActive = true;
        dispositionArmed = false;
        dispositionHangupConsumed = true;
        presenceInDisposition = false;
        presenceOnCall = false;
        showToast(
            continueAutoDial && !state.sessionActive
                ? `Disposition saved: ${disposition}. Auto dial stopped.`
                : `Disposition saved: ${disposition}`,
            'success',
        );
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
    const leadFileName = form?.dataset.dialLeadFileName || '';

    if (state.currentLead && (state.currentLead.phone === phone || !phone)) {
        if (!state.currentLead.file_name && leadFileName) {
            state.currentLead.file_name = leadFileName;
            state.currentLead.workflow = leadFileName;
        }
        return state.currentLead;
    }

    if (leadId || leadName || leadPhone) {
        return {
            id: leadId,
            name: leadName,
            phone: leadPhone || phone,
            file_name: leadFileName,
            workflow: leadFileName,
        };
    }

    const row = Array.from(document.querySelectorAll('[data-dialer-lead-row]'))
        .find((el) => el.dataset.leadPhone === phone);

    if (row) {
        const fileName = row.dataset.leadFileName || '';
        return {
            id: Number.parseInt(row.dataset.leadId || '0', 10) || null,
            name: row.dataset.leadName || '',
            contact: row.dataset.leadContact || '',
            phone: row.dataset.leadPhone || phone,
            file_name: fileName,
            workflow: fileName,
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

        // While auto dial session is running, Start owns the queue — block per-lead Call.
        // When stopped (or Manual), agents can expand the list and pick a lead to dial.
        if (state.mode === 'auto' && state.sessionActive) {
            event.preventDefault();
            showToast('Auto dial is running — press Stop auto dial first to pick a lead.', 'info');
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
            file_name: row.dataset.leadFileName || '',
            workflow: row.dataset.leadFileName || '',
        };

        await dialLead(lead);
    });

    window.addEventListener('comm:call-active', (event) => {
        // Bind the live Morpheus uuid once known so prior-leg hangups cannot match.
        updateArmedDialUuid(event.detail?.callUuid || getWebphone()?.originateCallUuid || '');
        // Call is live — stop originate-window suppress so hangup can open Call Summary.
        suppressCallEndedUntilActive = false;

        // Never re-arm disposition from call-active — late "active" echoes after hangup
        // used to unlock Call Summary and make the popup open again.
        if (
            isSummaryModalVisible()
            || state.pendingDisposition
            || isDispositionPopupLocked()
            || document.body.classList.contains('ch-call-summary-open')
            || document.body.classList.contains('ch-disposition-locked')
            || !dispositionArmed
        ) {
            // Still refresh dialing UI if a live call is already armed.
            const detailPhone = event.detail?.phone || '';
            if (dispositionArmed && (state.currentLead || detailPhone)) {
                const phone = getWebphone();
                if (phone?.state === 'in-call' || phone?.timerPhase === 'connected') {
                    markLeadRowActive(state.currentLead || resolveLeadContext(detailPhone), 'connected');
                }
            }
            return;
        }

        const detailPhone = event.detail?.phone || '';
        state.callStartedAt = Date.now();
        startAutoDialUiSync();
        const phone = getWebphone();
        if (phone?.state === 'in-call' || phone?.timerPhase === 'connected') {
            markLeadRowActive(state.currentLead || resolveLeadContext(detailPhone), 'connected');
        } else if (state.currentLead || detailPhone) {
            markLeadRowActive(state.currentLead || resolveLeadContext(detailPhone), 'dialing');
        }
        if (detailPhone && !state.currentLead) {
            state.currentLead = resolveLeadContext(detailPhone);
        }
    });

    window.addEventListener('comm:dial-started', (event) => {
        // Never unlock / re-arm while Call Summary is still open.
        if (isSummaryModalVisible() || document.body.classList.contains('ch-call-summary-open')) {
            return;
        }
        // After Save & Next, pendingDisposition is cleared; allow arming even if lock briefly remains.
        if (dispositionSaveInFlight) {
            return;
        }
        const detailPhone = event.detail?.phone || '';
        const phoneApi = getWebphone();
        // Only dial-started arms disposition (covers failed originate too).
        armDispositionForNewCall({
            phone: detailPhone || state.currentLead?.phone || '',
            lead: state.currentLead || null,
            callUuid: event.detail?.callUuid || phoneApi?.originateCallUuid || phoneApi?.morpheusCallUuid || '',
            dialToken: event.detail?.dialToken || phoneApi?._outboundDialToken || 0,
        });
        // Close any leftover call-events socket from a prior leg before this dial.
        phoneApi?.stopCallEventsStream?.();
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

        // Sticky consume — ignore every hangup echo after Call Summary already owned this cycle.
        if (dispositionHangupConsumed && !dispositionSaveInFlight) {
            clearDialingLeadHighlight();
            return;
        }

        const leadBeforeClear = state.currentLead;
        const context = resolveEndedCallContext(detail);
        if (!context.lead && leadBeforeClear) {
            context.lead = leadBeforeClear;
        }
        if (!context.phone && leadBeforeClear?.phone) {
            context.phone = leadBeforeClear.phone;
        }
        // Prefer armed dial phone when hangup payload is empty (common on auto no-answer).
        if (!context.phone && armedDial.phone) {
            context.phone = armedDial.phone;
            if (!context.lead) {
                context.lead = resolveLeadContext(armedDial.phone) || leadBeforeClear || null;
            }
        }
        if (!context.callUuid && armedDial.uuid) {
            context.callUuid = armedDial.uuid;
        }
        if (!context.dialToken && detail.dialToken) {
            context.dialToken = detail.dialToken;
        }
        // Current matched hangup must open Call Summary — clear originate suppress.
        if (dispositionArmed && matchesArmedDial(context)) {
            suppressCallEndedUntilActive = false;
        }

        if (isHandledHangupUuid(context)) {
            clearDialingLeadHighlight();
            return;
        }

        // Drop duplicate hangup echoes before any UI teardown.
        if (shouldSuppressDispositionReopen(context) || isStaleHangupWhileLive(context)) {
            clearDialingLeadHighlight();
            return;
        }

        // Call Summary already open for this hangup — never stack another popup.
        if (isSummaryModalVisible() || state.pendingDisposition) {
            dispositionHangupConsumed = true;
            rememberHandledHangupUuid(context);
            clearDialingLeadHighlight();
            return;
        }

        // Never open disposition / tear UI while a call is still live (premature ended echoes).
        // Destination hangup / user hangup / ringing end MUST still open Call Summary instantly.
        if (
            phone?.isLiveCallUiActive?.()
            && !phone.hangupInFlight
            && !phone.userInitiatedHangup
            && !phone.remoteHangupHandled
            && !phone._callEndedDispatched
        ) {
            clearDialingLeadHighlight();

            return;
        }

        // Instant once: clear live UI lock so Call Summary is never blocked after hangup.
        if (phone) {
            phone.liveCallUiActive = false;
        }

        // Capture lead/phone before clearing form context.
        state.callStartedAt = null;
        // Stop any pending "next call" countdown — disposition is required first.
        clearAutoDialDelay();
        // Drop Dialing… immediately on hangup (lead stays until disposition moves it).
        markPendingDispositionLeadRows(context);
        clearDialingLeadHighlight();
        formClearLeadContext();

        // showSummaryModal sets dispositionHangupConsumed as soon as it commits to open.
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
                btn.textContent = 'Start Auto Dial';
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

    autoDialSessionGeneration += 1;
    state.paused = false;
    state.sessionActive = true;
    state.currentLead = null;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    switchToLeadsTab();

    // Clear leftover hangup flags so the first originate is not killed as "late".
    const phoneApi = getWebphone();
    if (phoneApi) {
        phoneApi.hangupInFlight = false;
        phoneApi._callEndedDispatched = false;
        phoneApi.userInitiatedHangup = false;
        phoneApi.remoteHangupHandled = false;
        phoneApi._outboundCancelled = false;
        phoneApi.liveCallUiActive = false;
        document.body.classList.remove('ch-disposition-locked');
    }

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
