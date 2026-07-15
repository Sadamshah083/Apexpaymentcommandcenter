import { placeOutboundCall, clearDialerDestinationInput } from './communications-dialer.js';
import { getWebphone } from './communications-webphone.js';
import { showToast, dismissAllToasts } from './toast.js';

const STORAGE_MODE_KEY = 'communications.dial_mode';
const STORAGE_PAUSED_KEY = 'communications.auto_dial_paused';
const AUTO_DIAL_DELAY_MS = 10000;

let state = {
    mode: 'manual',
    paused: false,
    queue: [],
    currentLead: null,
    callStartedAt: null,
    pendingDisposition: null,
    selectedDisposition: null,
    sessionActive: false,
};

let autoDialDelayTimer = null;
let autoDialCountdownTimer = null;
let presenceTimer = null;
let presenceOnCall = false;
let presenceInDisposition = false;

let autoDialListenersBound = false;
let callSummaryModalBound = false;
/** Set while Save/Redial is in flight so late hangup events cannot reopen the popup. */
let dispositionSaveInFlight = false;
/** After a successful dismiss, ignore matching call-ended echoes for a short window. */
let dispositionDismissGuard = { key: '', until: 0 };

function dispositionContextKey(context = {}) {
    const uuid = String(context?.callUuid || '').trim();
    if (uuid) {
        return `uuid:${uuid}`;
    }

    const phone = normalizePhoneDigits(context?.phone || context?.lead?.phone || '');
    return phone ? `phone:${phone}` : '';
}

function armDispositionDismissGuard(context = {}) {
    const key = dispositionContextKey(context);
    dispositionDismissGuard = {
        key,
        until: Date.now() + 12000,
    };
}

function shouldSuppressDispositionReopen(context = {}) {
    if (dispositionSaveInFlight) {
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

function syncAutoDialControls() {
    // Recover sticky disposition state when the popup is already gone.
    if (state.pendingDisposition && !isSummaryModalVisible() && !dispositionSaveInFlight) {
        state.pendingDisposition = null;
        state.selectedDisposition = null;
        presenceInDisposition = false;
    }

    const running = state.mode === 'auto' && state.sessionActive && !state.paused;
    const waitingNext = Boolean(autoDialDelayTimer);
    const startBtns = document.querySelectorAll('[data-auto-dial-start]');
    const stopBtns = document.querySelectorAll('[data-auto-dial-stop]');
    const statusEls = document.querySelectorAll('[data-auto-dial-status]');

    startBtns.forEach((btn) => {
        btn.classList.toggle('hidden', running);
        btn.classList.toggle('is-running', false);
        btn.disabled = false;
        btn.textContent = 'Start auto dial';
    });

    stopBtns.forEach((btn) => {
        btn.classList.toggle('hidden', !running);
        btn.disabled = false;
    });

    statusEls.forEach((el) => {
        if (isSummaryModalVisible() || state.pendingDisposition) {
            el.textContent = 'Set disposition to continue';
            el.classList.remove('hidden');
            el.classList.add('is-active');
        } else if (waitingNext) {
            const countdownText = document.querySelector('[data-auto-dial-countdown-text]')?.textContent;
            el.textContent = countdownText || 'Next call in 10s…';
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
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    clearAutoDialDelay();
    hubRoot()?.querySelectorAll('[data-dialer-lead-row].is-dialing').forEach((row) => {
        row.classList.remove('is-dialing');
    });
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
    // Keep the Imported leads status in sync with the 10s timer.
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

function isDispositionBlocking() {
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
        return 'Answer Machine';
    }
    if (normalized.includes('wrong') || normalized.includes('invalid')) {
        return 'Wrong Number/Business';
    }
    if (normalized.includes('callback') || normalized.includes('call back') || normalized.includes('call later')) {
        return 'Call Back';
    }

    return 'No Answer';
}

function scheduleAutoDial(action, message = 'Next call') {
    clearAutoDialDelay();

    if (state.mode !== 'auto' || state.paused || isDispositionBlocking()) {
        return Promise.resolve(false);
    }

    let remaining = Math.ceil(AUTO_DIAL_DELAY_MS / 1000);
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
        }, AUTO_DIAL_DELAY_MS);
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

function setDialMode(mode) {
    state.mode = mode === 'auto' ? 'auto' : 'manual';
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
    sendPresenceHeartbeat();
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

    const body = {
        dial_mode: state.mode === 'auto' ? 'auto' : 'manual',
        auto_session_active: Boolean(state.sessionActive && state.mode === 'auto'),
        auto_paused: Boolean(state.paused && state.mode === 'auto'),
        on_call: Boolean(extra.on_call ?? presenceOnCall),
        in_disposition: Boolean(extra.in_disposition ?? presenceInDisposition),
        disposition_phone: extra.disposition_phone
            || state.pendingDisposition?.phone
            || state.pendingDisposition?.lead?.phone
            || null,
        extension: currentExtension() || null,
        ...extra,
    };

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
        sendPresenceHeartbeat({ on_call: true, in_disposition: false });
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
        });
    });

    // Immediate login signal → Call Monitoring moves agent from Not logged in → Not in call.
    sendPresenceHeartbeat({ on_call: false, in_disposition: false });
    presenceTimer = window.setInterval(() => {
        sendPresenceHeartbeat({
            on_call: presenceOnCall,
            in_disposition: presenceInDisposition,
        });
    }, 12000);

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

    // Same hangup can emit call-ended more than once — never reopen / restack UI.
    if (!modal.classList.contains('hidden') && state.pendingDisposition) {
        const openUuid = String(state.pendingDisposition.callUuid || '');
        const nextUuid = String(context?.callUuid || '');
        const openPhone = String(state.pendingDisposition.phone || state.pendingDisposition.lead?.phone || '');
        const nextPhone = String(context?.phone || context?.lead?.phone || '');
        if ((openUuid && nextUuid && openUuid === nextUuid) || (openPhone && nextPhone && openPhone === nextPhone)) {
            return;
        }
    }

    // Intentional reopen (failed save) clears the dismiss guard.
    if (force) {
        dispositionDismissGuard = { key: '', until: 0 };
    }

    // Never continue the queue while disposition is required.
    clearAutoDialDelay();
    clearDialerDestinationInput();
    dismissAllToasts();
    getWebphone()?.restoreDialerAfterCall?.();

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
        nextLabel.textContent = state.mode === 'auto' && state.sessionActive ? 'Save & Next' : 'Save';
    }
    if (pauseBtn) {
        pauseBtn.classList.toggle('hidden', state.mode !== 'auto');
        pauseBtn.classList.toggle('is-paused', state.paused);
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

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ch-call-summary-open');
    syncAutoDialControls();

    // Focus selected disposition so agents can confirm and continue quickly.
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
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        modal.dataset.dispositionBusy = '';
        modal.querySelector('[data-call-summary-next]')?.removeAttribute('disabled');
        modal.querySelector('[data-call-summary-redial]')?.removeAttribute('disabled');
    });
    document.body.classList.remove('ch-call-summary-open');
    state.pendingDisposition = null;
    state.selectedDisposition = null;
    presenceInDisposition = false;
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
            signal: AbortSignal.timeout(15000),
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
        if (!response.ok) {
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

function removeLeadFromQueue(leadId, phone) {
    const root = hubRoot() || document;
    const targetId = Number.parseInt(String(leadId || '0'), 10) || null;
    const targetPhone = String(phone || '').trim();

    root.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const rowId = Number.parseInt(row.dataset.leadId || '0', 10) || null;
        const rowPhone = row.dataset.leadPhone || '';
        const matchId = targetId && rowId && rowId === targetId;
        const matchPhone = targetPhone && phonesMatch(rowPhone, targetPhone);
        if (matchId || matchPhone) {
            row.classList.remove('is-dialing');
            row.remove();
        }
    });

    state.queue = state.queue.filter((lead) => {
        if (targetId && Number(lead.id) === targetId) {
            return false;
        }
        if (targetPhone && phonesMatch(lead.phone, targetPhone)) {
            return false;
        }

        return true;
    });

    if (state.currentLead) {
        const currentMatchId = targetId && Number(state.currentLead.id) === targetId;
        const currentMatchPhone = targetPhone && phonesMatch(state.currentLead.phone, targetPhone);
        if (currentMatchId || currentMatchPhone) {
            state.currentLead = null;
        }
    }

    const items = root.querySelector('[data-imported-leads-items]');
    if (items && !items.querySelector('[data-dialer-lead-row]')) {
        items.innerHTML = '<p class="ghl-dialer-recent-empty" data-imported-leads-empty>No imported leads with phone numbers yet.</p>';
    }

    syncAutoDialControls();
}

function prependCallLog(callLog) {
    if (!callLog) {
        return;
    }

    // Ensure disposition / lead labels survive even if API omit some display fields.
    const enriched = {
        ...callLog,
        disposition: callLog.disposition || callLog.result || '',
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

        const statusLike = ['', '—', '-', 'completed', 'initiated', 'connected', 'answered', 'no-answer', 'no answer', 'busy', 'failed', 'missed', 'unknown'];
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
    const leadId = context?.lead?.id || payload?.lead?.id || null;
    const phone = context?.phone || context?.lead?.phone || payload?.call_log?.phone || '';
    const callLog = {
        ...(payload?.call_log || {}),
        disposition: disposition || payload?.disposition || payload?.call_log?.disposition || '',
        lead_name: payload?.call_log?.lead_name || context?.lead?.name || '',
        lead_contact: payload?.call_log?.lead_contact || context?.lead?.contact || context?.lead?.owner_name || '',
        lead_file_name: payload?.call_log?.lead_file_name || context?.lead?.file_name || context?.lead?.workflow || '',
        phone: payload?.call_log?.phone || phone,
        phone_display: payload?.call_log?.phone_display || context?.lead?.phone_display || phone,
    };

    removeLeadFromQueue(leadId, phone);
    prependCallLog(callLog);
    window.__apexActiveCallNotes?.clear?.();
    clearDialerDestinationInput();

    // Always stay on Imported leads — never jump to Call logs after disposition.
    switchToLeadsTab();
    syncAutoDialControls();

    return { leadId, phone, disposition };
}

async function dialLead(lead) {
    if (!lead?.phone) {
        return false;
    }

    state.currentLead = lead;
    state.callStartedAt = Date.now();

    const form = document.querySelector('.ghl-dialer-originate-form');
    if (form) {
        form.dataset.dialLeadId = String(lead.id || '');
        form.dataset.dialLeadName = String(lead.name || '');
        form.dataset.dialLeadPhone = String(lead.phone || '');
    }

    return placeOutboundCall(lead.phone, lead);
}

async function dialNextInQueue({ withDelay = false, delayMessage = 'Next call' } = {}) {
    if (state.mode !== 'auto' || state.paused || isDispositionBlocking()) {
        return false;
    }

    const placeNext = async () => {
        if (isDispositionBlocking()) {
            return false;
        }

        if (state.queue.length === 0) {
            const list = hubRoot()?.querySelector('[data-imported-leads-list]');
            if (list && list.dataset.importedLeadsHasMore !== '0') {
                await fetchImportedLeads(list, false);
            }
        }

        // Always take from the front of the queue (built last→first).
        const next = state.queue.shift();
        if (!next) {
            state.sessionActive = false;
            syncAutoDialControls();
            showToast('No more callable imported leads in the queue.', 'info');

            return false;
        }

        markLeadRowActive(next);

        const dialed = await dialLead(next);
        syncAutoDialControls();

        return dialed;
    };

    if (withDelay) {
        return scheduleAutoDial(placeNext, delayMessage);
    }

    return placeNext();
}

function markLeadRowActive(lead) {
    const root = hubRoot();
    root?.querySelectorAll('[data-dialer-lead-row]').forEach((row) => {
        const match = (lead.id && Number(row.dataset.leadId) === Number(lead.id))
            || (lead.phone && row.dataset.leadPhone === lead.phone);
        row.classList.toggle('is-dialing', Boolean(match));
    });
}

/**
 * Build dial queue from the Imported leads list.
 * Starts from the TOP row and works downward so the first lead is dialed first.
 */
function loadQueueFromDom(root = hubRoot(), { rebuild = true } = {}) {
    const rows = root?.querySelectorAll('[data-dialer-lead-row]') || [];
    const skipIds = new Set();
    const skipPhones = new Set();

    if (state.currentLead?.id) {
        skipIds.add(Number(state.currentLead.id));
    }
    if (state.currentLead?.phone) {
        skipPhones.add(String(state.currentLead.phone));
    }
    if (state.pendingDisposition?.lead?.id) {
        skipIds.add(Number(state.pendingDisposition.lead.id));
    }
    if (state.pendingDisposition?.phone || state.pendingDisposition?.lead?.phone) {
        skipPhones.add(String(state.pendingDisposition.phone || state.pendingDisposition.lead.phone));
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
        if (lead.id && skipIds.has(Number(lead.id))) {
            return false;
        }
        if (skipPhones.has(String(lead.phone))) {
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
            wrapper.innerHTML = leads.map((lead) => buildLeadRow(lead)).join('');
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

async function submitDispositionAction(modal, { redial = false } = {}) {
    if (!modal || modal.dataset.dispositionBusy === '1' || dispositionSaveInFlight) {
        return;
    }

    const disposition = resolveSummaryDisposition(modal);
    if (!disposition) {
        dispositionToast(
            redial ? 'Select or write a disposition before redialing.' : 'Select or write a disposition first.',
            'warning',
        );
        return;
    }

    const context = { ...(state.pendingDisposition || {}) };
    const note = modal.querySelector('[data-call-summary-note]')?.value?.trim() || '';
    const nextBtn = modal.querySelector('[data-call-summary-next]');
    const redialBtn = modal.querySelector('[data-call-summary-redial]');
    const nextLabel = modal.querySelector('[data-call-summary-next-label]');
    const previousLabel = nextLabel?.textContent || (state.mode === 'auto' && state.sessionActive ? 'Save & Next' : 'Save');
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
    hideSummaryModal({ force: true, context });

    let payload = null;
    try {
        payload = await saveDisposition(disposition, context, note);
    } catch (error) {
        console.warn('[auto-dial] disposition save threw', error);
        payload = null;
    }

    // Clear save flag BEFORE the 10s next-call wait — otherwise status stuck on "Saving…".
    finishDispositionSaveUi(previousLabel);

    if (!payload) {
        showSummaryModal(context, { force: true });
        return;
    }

    applyDispositionSideEffects(payload, context, disposition);

    if (redial) {
        if (context?.lead) {
            await dialLead(context.lead);
        } else if (context?.phone) {
            await placeOutboundCall(context.phone, context.lead || {});
        }
        return;
    }

    // Auto dial: wait 10 seconds, then place the next call.
    if (continueAutoDial) {
        void dialNextInQueue({ withDelay: true, delayMessage: 'Next call' });
    } else {
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
            selectDispositionChip(modal, dispositionBtn);
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

    // Double-click a disposition chip → save & close (same as Save / Save & Next).
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
        selectDispositionChip(modal, dispositionBtn);
        void submitDispositionAction(modal, { redial: false });
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
    window.__apexForceDisposition = 'Answer Machine';
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
        state.callStartedAt = Date.now();
        // New live call — allow the next hangup to open disposition again.
        dispositionDismissGuard = { key: '', until: 0 };
        dispositionSaveInFlight = false;
        const phone = event.detail?.phone || '';
        if (phone && !state.currentLead) {
            state.currentLead = resolveLeadContext(phone);
        }
    });

    window.addEventListener('comm:call-ended', (event) => {
        // Capture lead/phone before clearing form context.
        const context = resolveEndedCallContext(event.detail || {});
        state.callStartedAt = null;
        // Stop any pending "next call" countdown — disposition is required first.
        clearAutoDialDelay();
        formClearLeadContext();

        // After Save, ignore hangup echoes so the popup stays closed.
        if (shouldSuppressDispositionReopen(context)) {
            return;
        }

        // Always require a disposition after every hangup (manual or auto).
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

    if (state.mode !== 'auto') {
        setDialMode('auto');
    }

    state.paused = false;
    state.sessionActive = true;
    state.currentLead = null;
    localStorage.setItem(STORAGE_PAUSED_KEY, '0');
    setDialMode('auto');
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
        sendPresenceHeartbeat();
        showToast('No callable imported leads available for auto dial.', 'info');

        return false;
    }

    syncAutoDialControls();
    sendPresenceHeartbeat();
    return dialNextInQueue({ withDelay: true, delayMessage: 'Starting call' });
}
