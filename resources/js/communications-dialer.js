import { hideLoadingOverlay } from './form-loading.js';
import { showToast, usesCallSummaryFlow } from './toast.js';
import {
    cancelPendingWebphoneConnect,
    ensureWebphoneReady,
    getWebphone,
} from './communications-webphone.js';
import { initCommunicationsPhoneNotes } from './communications-phone-notes.js';
const STORAGE_KEY = 'communications.dialer_extension';
const DIAL_COOLDOWN_MS = 800;
let lastDialAt = 0;
let dialInFlight = false;

function stripCarrierPrefix(value) {
    const raw = String(value || '').trim();
    if (raw.includes('#')) {
        return raw.slice(raw.lastIndexOf('#') + 1).trim();
    }

    return raw;
}

function isValidPstnDestination(value) {
    const normalized = normalizePhone(value);
    const digits = normalized.replace(/\D/g, '');

    return digits.length >= 10;
}

function normalizePhone(value) {
    const digits = String(stripCarrierPrefix(value)).replace(/[^\d+]/g, '');
    if (!digits) {
        return '';
    }
    if (digits.startsWith('+')) {
        return digits;
    }
    const numeric = digits.replace(/^0+/, '');
    if (numeric.length === 10) {
        return `+1${numeric}`;
    }
    if (numeric.length <= 6) {
        return numeric;
    }

    return `+${numeric}`;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function applyCsrfToken(token) {
    const next = String(token || '').trim();
    if (!next) {
        return;
    }

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.setAttribute('content', next);
    }

    document.querySelectorAll('input[name="_token"]').forEach((input) => {
        input.value = next;
    });
}

function sessionPingUrl() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const action = String(form?.action || '');
    if (action.includes('/morpheus/calls/originate')) {
        return action.replace(/\/calls\/originate(?:\?.*)?$/, '/session/ping');
    }

    const release = document.querySelector('[data-webphone-panel]')?.dataset?.releaseExtensionUrl || '';
    if (release.includes('/calls/release-extension')) {
        return release.replace(/\/calls\/release-extension(?:\?.*)?$/, '/session/ping');
    }

    return '';
}

async function refreshDialerSession() {
    const url = sessionPingUrl();
    if (!url) {
        return false;
    }

    try {
        const res = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!res.ok) {
            return false;
        }
        const data = await res.json().catch(() => ({}));
        if (data.csrf_token) {
            applyCsrfToken(data.csrf_token);
        }

        return true;
    } catch {
        return false;
    }
}

function startDialerSessionHygiene() {
    if (typeof document === 'undefined' || document.documentElement.dataset.dialerHygiene === '1') {
        return;
    }
    document.documentElement.dataset.dialerHygiene = '1';

    const tick = async () => {
        if (document.hidden) {
            return;
        }

        await refreshDialerSession();

        if (dialInFlight && (Date.now() - lastDialAt) > 30000) {
            dialInFlight = false;
        }

        const phone = getWebphone();
        if (!phone) {
            return;
        }

        const live = phone.isLiveCallUiActive?.()
            || phone.isOutboundRingingUiActive?.()
            || ['dialing', 'ringing', 'in-call'].includes(phone.state || '');
        if (live) {
            return;
        }

        if (phone.state === 'registered' && typeof phone.isTransportConnected === 'function' && !phone.isTransportConnected()) {
            try {
                await phone.connect(phone.currentExtension || undefined);
            } catch {
                // best-effort reconnect
            }
        }
    };

    void tick();
    window.setInterval(() => {
        void tick();
    }, 8 * 60 * 1000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            void tick();
        }
    });
}

function resolveExtensionSelect(form) {
    if (!form) {
        return document.getElementById('dial-caller-id-global');
    }

    const inFormSelect = form.querySelector('select[name="from_extension"]');
    if (inFormSelect) {
        return inFormSelect;
    }

    if (form.id) {
        const linkedSelect = document.querySelector(`select[name="from_extension"][form="${form.id}"]`);
        if (linkedSelect) {
            return linkedSelect;
        }
    }

    return document.getElementById('dial-caller-id-global');
}

function getDialerFormNumberInput(form) {
    return form?.querySelector('[name="destination"]') || null;
}

function handleDialerNumberChange(form) {
    if (!form) {
        return;
    }

    const numberInput = getDialerFormNumberInput(form);
    const callerSelect = resolveExtensionSelect(form);
    const dialBtn = form.querySelector('button[type="submit"]');
    const backspace = form.querySelector('[data-dial-backspace]');

    // Keep End Call / hangup-mode dial button intact while Ringing/Connected UI is live.
    const phone = getWebphone();
    const liveCall = phone?.isLiveCallUiActive?.()
        || phone?.isOutboundRingingUiActive?.()
        || ['dialing', 'ringing', 'in-call'].includes(phone?.state || '');
    if (!liveCall) {
        refreshDialButton(numberInput, callerSelect, dialBtn);
    }
    refreshBackspaceVisibility(numberInput, backspace);
    scheduleRecentDialPreview(form);
}

let recentDialPreviewTimer = null;
let recentDialPreviewToken = 0;

function recentByPhoneUrl() {
    return document.querySelector('[data-phone-workspace]')?.dataset?.recentByPhoneUrl
        || document.querySelector('[data-recent-by-phone-url]')?.dataset?.recentByPhoneUrl
        || '';
}

function clearRecentDialPreview(form) {
    const root = form?.querySelector('[data-dialer-recent-preview]')
        || document.querySelector('[data-dialer-recent-preview]');
    if (!root) {
        return;
    }
    root.classList.add('hidden');
    root.querySelector('[data-dialer-recent-empty]')?.classList.add('hidden');
    root.querySelector('[data-dialer-recent-note]')?.classList.add('hidden');
}

function renderRecentDialPreview(form, log) {
    const root = form?.querySelector('[data-dialer-recent-preview]')
        || document.querySelector('[data-dialer-recent-preview]');
    if (!root) {
        return;
    }

    root.classList.remove('hidden');
    const empty = root.querySelector('[data-dialer-recent-empty]');
    const noteEl = root.querySelector('[data-dialer-recent-note]');
    const whenEl = root.querySelector('[data-dialer-recent-when]');
    const dispoEl = root.querySelector('[data-dialer-recent-disposition]');
    const durEl = root.querySelector('[data-dialer-recent-duration]');
    const resultEl = root.querySelector('[data-dialer-recent-result]');

    if (!log) {
        if (whenEl) whenEl.textContent = '—';
        if (dispoEl) dispoEl.textContent = '—';
        if (durEl) durEl.textContent = '';
        if (resultEl) resultEl.textContent = '';
        noteEl?.classList.add('hidden');
        empty?.classList.remove('hidden');
        return;
    }

    empty?.classList.add('hidden');
    if (whenEl) {
        whenEl.textContent = log.time_ago || log.time_label || '—';
    }
    if (dispoEl) {
        dispoEl.textContent = log.disposition || log.result || 'No disposition';
    }
    if (durEl) {
        durEl.textContent = log.duration_label ? `Duration ${log.duration_label}` : '';
    }
    if (resultEl) {
        const result = log.result && log.result !== log.disposition ? log.result : '';
        resultEl.textContent = result ? ` · ${result}` : '';
    }
    const note = String(log.note || '').trim();
    if (noteEl) {
        if (note) {
            noteEl.textContent = note;
            noteEl.classList.remove('hidden');
        } else {
            noteEl.textContent = '';
            noteEl.classList.add('hidden');
        }
    }
}

function scheduleRecentDialPreview(form) {
    const numberInput = getDialerFormNumberInput(form);
    const url = recentByPhoneUrl();
    if (!numberInput || !url) {
        return;
    }

    const raw = String(numberInput.value || '').trim();
    const digits = raw.replace(/\D/g, '');
    if (digits.length < 10) {
        clearRecentDialPreview(form);
        return;
    }

    const phone = normalizePhone(raw);
    if (!isValidPstnDestination(phone)) {
        clearRecentDialPreview(form);
        return;
    }

    if (recentDialPreviewTimer) {
        clearTimeout(recentDialPreviewTimer);
    }

    const token = ++recentDialPreviewToken;
    recentDialPreviewTimer = window.setTimeout(async () => {
        try {
            const endpoint = new URL(url, window.location.origin);
            endpoint.searchParams.set('phone', phone);
            const res = await fetch(endpoint.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (token !== recentDialPreviewToken) {
                return;
            }
            const data = await res.json().catch(() => ({}));
            renderRecentDialPreview(form, data?.log || null);
        } catch {
            if (token === recentDialPreviewToken) {
                clearRecentDialPreview(form);
            }
        }
    }, 320);
}

function bumpAgentTotalDials(delta = 1) {
    const workspace = document.querySelector('[data-phone-workspace]');
    const valueEl = document.querySelector('[data-agent-total-dials-value]');
    if (!workspace || !valueEl) {
        return;
    }
    const current = Number.parseInt(workspace.dataset.agentTotalDials || valueEl.textContent.replace(/\D/g, '') || '0', 10) || 0;
    const next = Math.max(0, current + delta);
    workspace.dataset.agentTotalDials = String(next);
    valueEl.textContent = next.toLocaleString();
}

/** Clear the pad number field (lead context can stay on form dataset until hangup). */
export function clearDialerDestinationInput() {
    document.querySelectorAll('.ghl-dialer-originate-form [name="destination"]').forEach((input) => {
        if (!input || input.value === '') {
            return;
        }
        input.value = '';
        const form = input.closest('.ghl-dialer-originate-form');
        if (form) {
            handleDialerNumberChange(form);
        }
    });
}

function updateDialerRouteSummary(callerSelect) {
    const summary = document.querySelector('[data-dialer-from-did]');
    const extBadge = document.querySelector('[data-dialer-line-ext]');
    const extension = callerSelect?.value || '';

    if (extBadge && extension) {
        extBadge.textContent = `Ext ${extension}`;
    }

    if (!summary) {
        return;
    }

    const selected = callerSelect?.selectedOptions?.[0];
    const raw = selected?.dataset?.outboundDid || '';
    const digits = String(raw).replace(/\D/g, '');

    if (digits.length >= 10) {
        summary.textContent = digits.length === 10 ? `+1${digits}` : `+${digits}`;

        return;
    }

    summary.textContent = raw || 'Not configured';
}

async function logDirectOutbound(form, destination) {
    const formData = new FormData(form);
    const fromExtension = formData.get('from_extension');

    try {
        await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                destination,
                from_extension: fromExtension,
                webphone_direct: true,
            }),
        });
    } catch {
        // History logging is best-effort for browser-originated calls.
    }
}

async function originateViaWebphone(form, dialBtn) {
    if (dialInFlight) {
        showToast('A call is already being placed. Please wait.', 'warning');

        return false;
    }

    const sinceLastDial = Date.now() - lastDialAt;
    if (sinceLastDial < DIAL_COOLDOWN_MS) {
        const waitSec = Math.ceil((DIAL_COOLDOWN_MS - sinceLastDial) / 1000);
        showToast(`Wait ${waitSec}s before placing another call on this line.`, 'warning');

        return false;
    }

    const destination = normalizePhone(new FormData(form).get('destination'));

    if (!destination) {
        showToast('Enter a valid phone number first.', 'error');

        return false;
    }

    if (!isValidPstnDestination(destination)) {
        showToast('Enter a full phone number with at least 10 digits (e.g. +12722001232).', 'error');

        return false;
    }

    hideLoadingOverlay();

    const phone = getWebphone();
    dialInFlight = true;
    lastDialAt = Date.now();

    try {
        await phone.dial(destination);
        await logDirectOutbound(form, destination);
        bumpAgentTotalDials(1);
        showToast(`Calling ${destination} from your browser line — the destination phone will ring.`, 'success');

        return true;
    } catch (error) {
        showToast(error.message || 'Could not place the call from your browser line.', 'error');

        return false;
    } finally {
        dialInFlight = false;
        hideLoadingOverlay();
        if (dialBtn) {
            dialBtn.removeAttribute('disabled');
            dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            dialBtn.removeAttribute('aria-disabled');
        }
    }
}

async function originateViaJson(form, dialBtn, { skipCooldown = false } = {}) {
    if (dialInFlight) {
        showToast('A call is already being placed. Please wait.', 'warning');

        return false;
    }

    const sinceLastDial = Date.now() - lastDialAt;
    if (!skipCooldown && sinceLastDial < DIAL_COOLDOWN_MS) {
        const waitSec = Math.ceil((DIAL_COOLDOWN_MS - sinceLastDial) / 1000);
        showToast(`Wait ${waitSec}s before placing another call on this line.`, 'warning');

        return false;
    }

    const formData = new FormData(form);
    const destination = normalizePhone(formData.get('destination'));

    if (!destination) {
        showToast('Enter a valid phone number first.', 'error');

        return false;
    }

    if (!isValidPstnDestination(destination)) {
        showToast('Enter a full phone number with at least 10 digits (e.g. +12722001232).', 'error');

        return false;
    }

    formData.set('destination', destination);
    hideLoadingOverlay();

    const phone = getWebphone();

    // Fast path: line already Registered → fire originate in the same click tick.
    // Slow path only when Connect line is still needed (was adding ~1.5s via prepare).
    const lineReady = () => Boolean(phone.canDirectDial?.() && phone.isTransportConnected?.());
    if (!lineReady()) {
        try {
            const ready = await ensureWebphoneReady({ silent: true });
            if (!ready || !lineReady()) {
                throw new Error('Connect your Phone line (WebSocket) and wait for Registered before calling.');
            }
        } catch (error) {
            showToast(error?.message || 'Connect your Phone line (WebSocket) before calling.', 'error');
            phone.forceTeardownForDisposition?.();
            if (dialBtn) {
                dialBtn.removeAttribute('disabled');
                dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                dialBtn.removeAttribute('aria-disabled');
            }

            return false;
        }
    }

    const attempt = phone.beginOutboundAttempt?.() || { generation: 0, signal: null };
    phone.setCustomerFirstOutbound(false);
    phone.setCallContext('outbound', destination);
    phone.clickToCallActive = true;
    phone.awaitingDestinationBridge = true;
    phone.markClickToCallPending();

    if (dialBtn) {
        dialBtn.removeAttribute('disabled');
        dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        dialBtn.removeAttribute('aria-disabled');
    }

    formData.set('webphone_transport_connected', '1');
    dialInFlight = true;
    lastDialAt = Date.now();

    // Start originate HTTP immediately — ringing UI paints after the request is in-flight.
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timeoutId = window.setTimeout(() => {
        try {
            controller?.abort('timeout');
        } catch {
            // ignore
        }
    }, 20000);
    const onHangupAbort = () => {
        try {
            controller?.abort('hangup');
        } catch {
            // ignore
        }
    };
    attempt.signal?.addEventListener?.('abort', onHangupAbort, { once: true });

    const buildOriginateBody = () => {
        const body = new FormData(form);
        body.set('destination', destination);
        body.set('webphone_transport_connected', phone.isTransportConnected?.() ? '1' : '1');
        body.set('_token', csrfToken());

        return body;
    };

    // Ringing UI after fetch started (does not delay Network originate).
    try {
        phone.showClickToCallRinging(destination, { customerFirst: false });
    } catch {
        // ignore UI errors
    }

    try {
        let response = null;
        let data = {};

        for (let attemptNo = 1; attemptNo <= 2; attemptNo += 1) {
            response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: buildOriginateBody(),
                signal: controller?.signal,
            });

            if (response?.status === 419 && attemptNo < 2) {
                await refreshDialerSession();
                continue;
            }

            data = await response.json().catch(() => ({}));

            // Agent hung up while originate was in flight — kill the late UUID and exit.
            if (phone.isOutboundAttemptCurrent && !phone.isOutboundAttemptCurrent(attempt.generation)) {
                window.clearTimeout(timeoutId);
                attempt.signal?.removeEventListener?.('abort', onHangupAbort);
                if (data.call_uuid) {
                    await phone.killLateOriginate?.(data.call_uuid, destination);
                }

                return false;
            }

            if ((!response.ok || data.ok === false) && data.extension_busy && attemptNo < 2) {
                try {
                    await phone.releaseExtensionCallsNow?.(
                        phone.currentExtension || formData.get('from_extension'),
                        destination,
                    );
                } catch {
                    // ignore
                }
                await new Promise((resolve) => window.setTimeout(resolve, 600));
                if (typeof phone.isTransportConnected === 'function' && !phone.isTransportConnected()) {
                    try {
                        await phone.connect(phone.currentExtension || undefined);
                    } catch {
                        // ignore
                    }
                }
                continue;
            }

            break;
        }

        window.clearTimeout(timeoutId);
        attempt.signal?.removeEventListener?.('abort', onHangupAbort);

        if (!response) {
            throw new Error('Could not place the call.');
        }

        if (!response.ok || data.ok === false) {
            const message = data.extension_busy
                ? (data.error || 'Your extension is busy. Click Connect line again, or try extension 1001.')
                : (data.error || 'Could not place the call. Check your extension and try again.');

            showToast(message, 'error');
            phone.cancelOutboundAttempt?.('originate-failed');
            // Manual dial still needs Call Summary after a failed/ringing attempt.
            const failedPhone = destination || phone.currentCallPeer || phone.lastDialedDestination || '';
            if (failedPhone && usesCallSummaryFlow() && !document.body.classList.contains('ch-call-summary-open')) {
                phone.dispatchCallEndedOnce?.({
                    phone: failedPhone,
                    callUuid: data.call_uuid || '',
                    result: 'No answer',
                    connected: false,
                    durationSec: 0,
                });
            }
            phone.clickToCallActive = false;
            phone.awaitingDestinationBridge = false;
            phone.forceTeardownForDisposition?.();

            return false;
        }

        if (data.call_uuid) {
            phone.setMorpheusCallUuid(data.call_uuid);
            // Open call-events WebSocket immediately after originate UUID lands.
            phone.subscribeCallEvents?.();
        }

        if (data.line_reset) {
            // Soft recover only — full connect() mid-originate drops Morpheus WSS.
            if (!phone.canDirectDial() || !phone.isTransportConnected()) {
                showToast('Line was reset — reconnecting without dropping the dial…', 'warning');
                await phone.softRecoverLine?.(phone.currentExtension || undefined).catch(() => {});
            }
            if (phone.isOutboundAttemptCurrent && !phone.isOutboundAttemptCurrent(attempt.generation)) {
                if (data.call_uuid) {
                    await phone.killLateOriginate?.(data.call_uuid, destination);
                }

                return false;
            }
            phone.markClickToCallPending();
        }

        const dialTarget =
            data.to && String(data.to).length >= 10
                ? `+${String(data.to).replace(/\D/g, '')}`
                : destination;

        // Instant hangup after UUID arrived — do not restart ringing UI.
        if (phone.isOutboundAttemptCurrent && !phone.isOutboundAttemptCurrent(attempt.generation)) {
            if (data.call_uuid) {
                await phone.killLateOriginate?.(data.call_uuid, dialTarget);
            }

            return false;
        }

        phone.setCustomerFirstOutbound(Boolean(data.customer_first));
        // Soft subtitle/peer update only — never restart ringing timer (originate ACK glitch).
        if (dialTarget && phone.timerPhase === 'ringing') {
            phone.refreshOutboundRingingUi?.(dialTarget, { restartTimer: false });
        }

        const fromExt = data.from ? String(data.from) : formData.get('from_extension');
        if (fromExt) {
            phone.syncSelectedExtension();
        }

        const successMessage =
            data.outcome === 'connected'
                ? 'Call connected.'
                : data.outcome === 'no_answer'
                  ? 'Call placed but your line did not answer in time.'
                  : data.customer_first
                    ? 'Your phone is ringing — answer within 90 seconds. Keep Connect line on.'
                    : 'Connecting your line… the destination will ring once your browser phone answers.';

        if (!usesCallSummaryFlow()) {
            showToast(data.warning || successMessage, data.warning ? 'warning' : 'success');
        }

        bumpAgentTotalDials(1);

        return true;
    } catch (error) {
        window.clearTimeout(timeoutId);
        attempt.signal?.removeEventListener?.('abort', onHangupAbort);
        const aborted = error?.name === 'AbortError'
            || String(error?.message || error || '').toLowerCase().includes('abort');
        if (aborted || (phone.isOutboundAttemptCurrent && !phone.isOutboundAttemptCurrent(attempt.generation))) {
            // Instant hangup aborted originate — Call Summary already owns the UI.
            return false;
        }

        phone.cancelOutboundAttempt?.('originate-error');
        phone.clickToCallActive = false;
        phone.awaitingDestinationBridge = false;
        phone.forceTeardownForDisposition?.();
        showToast(error.message || 'Could not place the call.', 'error');

        return false;
    } finally {
        window.clearTimeout(timeoutId);
        dialInFlight = false;
        hideLoadingOverlay();
        if (dialBtn) {
            dialBtn.removeAttribute('disabled');
            dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            dialBtn.removeAttribute('aria-disabled');
        }
    }
}

function refreshDialButton(numberInput, callerSelect, dialBtn) {
    if (!numberInput || !dialBtn || dialBtn.dataset.serverDisabled === '1') {
        return;
    }

    const hasNumber = isValidPstnDestination(numberInput.value);
    const extensionSelect = callerSelect || document.getElementById('dial-caller-id-global');
    const hasExtension = !extensionSelect || extensionSelect.value !== '';

    if (!hasNumber || !hasExtension) {
        dialBtn.setAttribute('disabled', 'disabled');
        dialBtn.classList.add('opacity-50', 'cursor-not-allowed');
        dialBtn.setAttribute('aria-disabled', 'true');

        return;
    }

    dialBtn.removeAttribute('disabled');
    dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    dialBtn.removeAttribute('aria-disabled');
}

function initDialerOverlayCancel() {
    if (document.documentElement.dataset.dialerOverlayCancelInit === '1') {
        return;
    }

    document.documentElement.dataset.dialerOverlayCancelInit = '1';
    document.addEventListener('app:loading-overlay-cancel', (event) => {
        const form = event.detail?.form;
        if (!(form instanceof HTMLFormElement) || !form.matches('.ghl-dialer-originate-form')) {
            return;
        }

        form.dataset.dialerConnectCancelled = '1';
        form.dataset.dialerConnectPending = '0';
        form.dataset.webphoneChecked = '';
        cancelPendingWebphoneConnect();
    });
}

function syncHiddenExtensionFields() {
    const global = document.getElementById('dial-caller-id-global');
    const value = global?.value || document.querySelector('[name="from_extension"]')?.value || '';

    document.querySelectorAll('[data-dial-extension-sync]').forEach((field) => {
        field.value = value;
    });
}

function refreshBackspaceVisibility(numberInput, backspace) {
    if (!numberInput || !backspace) {
        return;
    }

    const show = (numberInput.value || '').trim().length > 0;
    backspace.classList.toggle('is-hidden', !show);
}

function parseLineOption(select, value) {
    const option = Array.from(select.options).find((entry) => entry.value === value);
    if (!option || !value) {
        return null;
    }

    const raw = (option.textContent || '').trim();
    const parts = raw.split('·').map((part) => part.trim()).filter(Boolean);
    const did =
        option.dataset.outboundDid ||
        option.getAttribute('data-outbound-did') ||
        parts[1] ||
        '';

    return {
        extension: value,
        did,
    };
}

function syncLineDropdownFromSelect(select) {
    const wrapper = select?.closest('[data-line-dropdown]');
    if (!wrapper) {
        return;
    }

    const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
    const content = wrapper.querySelector('.ghl-line-dropdown__trigger-content');
    const selected = parseLineOption(select, select.value);

    wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
        const isSelected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', isSelected);
        button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });

    if (!content) {
        return;
    }

    if (!selected) {
        content.innerHTML = '<span class="ghl-line-dropdown__placeholder">Select line</span>';
        return;
    }

    const triggerStyle = wrapper.dataset.lineTriggerStyle || 'default';
    if (triggerStyle === 'toolbar') {
        content.innerHTML = `
            <span class="ghl-line-dropdown__ext-value">Ext ${selected.extension}</span>
            ${selected.did ? `<span class="ghl-line-dropdown__did">${selected.did}</span>` : ''}
        `;
        return;
    }

    content.innerHTML = `
        <span class="ghl-line-dropdown__ext-badge">Ext ${selected.extension}</span>
        ${selected.did ? `<span class="ghl-line-dropdown__did">${selected.did}</span>` : ''}
    `;
}

function closeLineDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
    const menu = wrapper.querySelector('.ghl-line-dropdown__menu');
    const search = wrapper.querySelector('.ghl-line-dropdown__search');

    wrapper.classList.remove('is-open');
    trigger?.setAttribute('aria-expanded', 'false');
    if (menu) {
        menu.hidden = true;
        menu.style.position = '';
        menu.style.top = '';
        menu.style.left = '';
        menu.style.right = '';
        menu.style.width = '';
        menu.style.minWidth = '';
        menu.style.maxWidth = '';
        menu.style.transform = '';
    }
    if (search) {
        search.value = '';
        wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
            button.classList.remove('is-hidden');
        });
    }
}

function positionLineDropdownMenu(wrapper) {
    const trigger = wrapper?.querySelector('.ghl-line-dropdown__trigger');
    const menu = wrapper?.querySelector('.ghl-line-dropdown__menu');
    if (!trigger || !menu || menu.hidden) {
        return;
    }

    const gap = 6;
    const edge = 8;
    const triggerRect = trigger.getBoundingClientRect();
    const preferredWidth = Math.min(Math.max(triggerRect.width, 280), window.innerWidth - edge * 2);

    menu.style.position = 'fixed';
    menu.style.zIndex = '10150';
    menu.style.width = `${preferredWidth}px`;
    menu.style.minWidth = `${Math.min(preferredWidth, 240)}px`;
    menu.style.maxWidth = `calc(100vw - ${edge * 2}px)`;
    menu.style.transform = 'none';
    menu.style.right = 'auto';

    // Prefer aligning to the right edge of the trigger so menus near the right stay on-screen.
    let left = triggerRect.right - preferredWidth;
    if (left < edge) {
        left = edge;
    }
    if (left + preferredWidth > window.innerWidth - edge) {
        left = Math.max(edge, window.innerWidth - edge - preferredWidth);
    }

    let top = triggerRect.bottom + gap;
    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;

    // After paint, flip upward if it would overflow the bottom.
    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        if (menuRect.bottom > window.innerHeight - edge) {
            const upTop = triggerRect.top - menuRect.height - gap;
            if (upTop >= edge) {
                menu.style.top = `${Math.round(upTop)}px`;
            }
        }
        if (menuRect.left < edge) {
            menu.style.left = `${edge}px`;
        }
    });
}

function closeAllLineDropdowns(except = null) {
    document.querySelectorAll('[data-line-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeLineDropdown(wrapper);
        }
    });
}

function filterLineDropdown(wrapper, query) {
    const needle = (query || '').trim().toLowerCase();

    wrapper.querySelectorAll('[data-line-option]').forEach((button) => {
        const haystack = button.dataset.search || button.textContent || '';
        button.classList.toggle('is-hidden', needle !== '' && !haystack.toLowerCase().includes(needle));
    });
}

function initLineDropdowns(root = document) {
    const scope = root === document ? document : root;

    scope.querySelectorAll('[data-line-dropdown]').forEach((wrapper) => {
        if (wrapper.dataset.lineDropdownBound === '1') {
            syncLineDropdownFromSelect(wrapper.querySelector('.ghl-line-dropdown__native'));
            return;
        }

        wrapper.dataset.lineDropdownBound = '1';

        const select = wrapper.querySelector('.ghl-line-dropdown__native');
        const trigger = wrapper.querySelector('.ghl-line-dropdown__trigger');
        const menu = wrapper.querySelector('.ghl-line-dropdown__menu');
        const search = wrapper.querySelector('.ghl-line-dropdown__search');

        if (!select || !trigger || !menu) {
            return;
        }

        syncLineDropdownFromSelect(select);

        select.addEventListener('change', () => {
            syncLineDropdownFromSelect(select);
        });

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            document.querySelector('[data-dialer-notes-drawer]')?.classList.add('hidden');
            document.querySelector('[data-dialer-notes-panel]')?.classList.remove('is-open');
            document.querySelector('[data-dialer-notes-toggle]')?.setAttribute('aria-expanded', 'false');

            const willOpen = !wrapper.classList.contains('is-open');
            closeAllLineDropdowns(willOpen ? wrapper : null);

            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;

            if (willOpen) {
                positionLineDropdownMenu(wrapper);
                if (search) {
                    search.focus();
                }
            } else {
                menu.style.position = '';
                menu.style.top = '';
                menu.style.left = '';
                menu.style.right = '';
                menu.style.width = '';
                menu.style.minWidth = '';
                menu.style.maxWidth = '';
                menu.style.transform = '';
            }
        });

        if (search) {
            search.addEventListener('input', () => {
                filterLineDropdown(wrapper, search.value);
            });

            search.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeLineDropdown(wrapper);
                    trigger.focus();
                }
            });
        }

        menu.querySelectorAll('[data-line-option]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                select.value = button.dataset.value || select.value;
                syncLineDropdownFromSelect(select);
                closeLineDropdown(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    if (document.body.dataset.lineDropdownGlobalBound === '1') {
        return;
    }

    document.body.dataset.lineDropdownGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-line-dropdown]')) {
            return;
        }

        closeAllLineDropdowns();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllLineDropdowns();
        }
    });

    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-line-dropdown].is-open').forEach((wrapper) => {
            positionLineDropdownMenu(wrapper);
        });
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('[data-line-dropdown].is-open').forEach((wrapper) => {
            positionLineDropdownMenu(wrapper);
        });
    }, true);
}

function initGlobalExtensionSync(root = document) {
    const global = root.getElementById?.('dial-caller-id-global') || document.getElementById('dial-caller-id-global');
    if (!global || global.dataset.globalExtInit === '1') {
        return;
    }

    global.dataset.globalExtInit = '1';

    const savedCaller = localStorage.getItem(STORAGE_KEY);
    if (savedCaller) {
        const match = Array.from(global.options).find((option) => option.value === savedCaller);
        if (match) {
            global.value = savedCaller;
        }
    }

    syncLineDropdownFromSelect(global);

    global.addEventListener('change', () => {
        localStorage.setItem(STORAGE_KEY, global.value || '');
        syncHiddenExtensionFields();
        updateDialerRouteSummary(global);
        getWebphone().syncSelectedExtension();
        document.querySelectorAll('.ghl-dialer-originate-form').forEach((form) => {
            const numberInput = form.querySelector('[name="destination"]');
            const dialBtn = form.querySelector('button[type="submit"]');
            const backspace = form.querySelector('[data-dial-backspace]');
            refreshDialButton(numberInput, global, dialBtn);
            refreshBackspaceVisibility(numberInput, backspace);
        });
    });

    syncHiddenExtensionFields();
    updateDialerRouteSummary(global);
}

function attachDialerForm(form) {
    if (!form || form.dataset.dialerInit === '1') {
        return;
    }

    form.dataset.dialerInit = '1';

    const numberInput = getDialerFormNumberInput(form);
    const callerSelect = resolveExtensionSelect(form);
    const dialBtn = form.querySelector('button[type="submit"]');

    if (!numberInput || !dialBtn) {
        return;
    }

    if (dialBtn.hasAttribute('disabled') && dialBtn.disabled) {
        dialBtn.dataset.serverDisabled = '1';
    }

    if (callerSelect?.tagName === 'SELECT') {
        const savedCaller = localStorage.getItem(STORAGE_KEY);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }

        callerSelect.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEY, callerSelect.value || '');
            handleDialerNumberChange(form);
            getWebphone().syncSelectedExtension();
            updateDialerRouteSummary(callerSelect);
            syncHiddenExtensionFields();
        });
    }

    updateDialerRouteSummary(callerSelect);

    const handleNumberChange = () => {
        handleDialerNumberChange(form);
    };

    numberInput.addEventListener('input', handleNumberChange);
    numberInput.addEventListener('change', handleNumberChange);

    form.addEventListener('submit', async (event) => {
        syncHiddenExtensionFields();
        numberInput.value = normalizePhone(numberInput.value) || numberInput.value;

        const webphone = getWebphone();
        const phoneState = webphone?.state || '';
        if (['dialing', 'ringing', 'in-call'].includes(phoneState)) {
            event.preventDefault();
            event.stopPropagation();
            hideLoadingOverlay();
            webphone.hangup('dialer-call-btn').catch(() => {});

            return;
        }

        // Auto dial mode: place calls via Start auto dial, not the pad phone icon.
        if (document.body.classList.contains('ch-dial-auto-mode')
            || document.querySelector('[data-auto-dial-hub]')?.classList.contains('ch-dial-workspace--auto-mode')) {
            event.preventDefault();
            event.stopPropagation();
            hideLoadingOverlay();
            showToast('Auto dial mode — use Start auto dial. Switch to Manual dial for keypad calls.', 'info');
            return;
        }

        if (form.dataset.webphoneChecked === '1') {
            form.dataset.webphoneChecked = '';
            form.dataset.dialerConnectPending = '0';
            form.dataset.dialerConnectCancelled = '0';

            return;
        }

        if (document.querySelector('[data-webphone-panel]')) {
            event.preventDefault();
            hideLoadingOverlay();

            if (form.dataset.dialerConnectPending === '1') {
                return;
            }

            form.dataset.dialerConnectPending = '1';
            form.dataset.dialerConnectCancelled = '0';

            dialBtn.setAttribute('disabled', 'disabled');
            dialBtn.classList.add('opacity-50', 'cursor-not-allowed');
            dialBtn.setAttribute('aria-disabled', 'true');

            const phone = getWebphone();
            // Skip prepare/connect when already Registered — originateViaJson fires instantly.
            let ready = Boolean(phone.canDirectDial() && phone.isTransportConnected());
            if (!ready) {
                ready = await ensureWebphoneReady({ silent: true });
            }
            const wasCancelled = form.dataset.dialerConnectCancelled === '1';
            form.dataset.dialerConnectPending = '0';
            form.dataset.dialerConnectCancelled = '0';

            if (wasCancelled) {
                hideLoadingOverlay();
                dialBtn.removeAttribute('disabled');
                dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                dialBtn.removeAttribute('aria-disabled');

                return;
            }

            if (form.dataset.originateJson === '1') {
                const destination = normalizePhone(new FormData(form).get('destination'));

                if (!ready || !phone.canDirectDial()) {
                    showToast(
                        'Click Connect first (pick your extension, then Connect). On HTTP, that prepares click-to-call; on HTTPS it registers the browser line.',
                        'error',
                    );
                    dialBtn.removeAttribute('disabled');
                    dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    dialBtn.removeAttribute('aria-disabled');

                    return;
                }

                // PSTN must use Morpheus click-to-call API (applies trunk routing + tech prefix).
                // Browser INVITE to sip:number@apexone.morpheus.cx returns 404 NO_ROUTE_DESTINATION.
                if (isValidPstnDestination(destination)) {
                    await originateViaJson(form, dialBtn);

                    return;
                }

                const dialMode = phone.config?.dial_mode || 'api';
                const preferSip = dialMode === 'sip' && form.dataset.dialViaSip !== '0';

                if (dialMode === 'api') {
                    await originateViaJson(form, dialBtn);

                    return;
                }

                if (preferSip) {
                    const sipOk = await originateViaWebphone(form, dialBtn);
                    if (sipOk) {
                        return;
                    }

                    const rejectDetail = phone.lastInviteReject || 'INVITE rejected';
                    showToast(
                        `Browser SIP dial failed (${rejectDetail}). Open DevTools → Network → Socket → wss://apexone.morpheus.cx:7443 and check the INVITE response. Server click-to-call cannot ring ext ${phone.currentExtension || 'your line'} while Connect line is active.`,
                        'error',
                    );

                    return;
                }

                await originateViaJson(form, dialBtn);

                return;
            }

            hideLoadingOverlay();
            form.dataset.webphoneChecked = '1';
            form.requestSubmit();
        }
    });

    handleNumberChange();
}

export function initCommunicationsDialer(root = document) {
    initLineDropdowns(root);
    initGlobalExtensionSync(root);
    root.querySelectorAll('.ghl-dialer-originate-form').forEach(attachDialerForm);
}

export function resetDialerButtonsForCache() {
    document.querySelectorAll('.ghl-dialer-originate-form').forEach((form) => {
        delete form.dataset.dialerInit;

        const button = form.querySelector('button[type="submit"]');
        if (!button || button.dataset.serverDisabled === '1') {
            return;
        }

        button.removeAttribute('disabled');
        button.classList.remove('opacity-50', 'cursor-not-allowed');
        button.removeAttribute('aria-disabled');
    });
}

function initDialerSubmitFeedback() {
    if (document.documentElement.dataset.dialerSubmitFeedbackInit === '1') {
        return;
    }

    document.documentElement.dataset.dialerSubmitFeedbackInit = '1';

    document.addEventListener('turbo:submit-end', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('.ghl-dialer-originate-form')) {
            return;
        }

        hideLoadingOverlay();

        if (event.detail.success) {
            return;
        }

        showToast('Could not place the call. Check your extension and try again.', 'error');
    });
}

function getActiveDialerForm() {
    return document.querySelector('.ghl-comm-phone-mode .ghl-dialer-originate-form')
        || document.querySelector('.ghl-comm-dial-rail .ghl-dialer-originate-form')
        || document.querySelector('.ghl-dialer-originate-form');
}

function initDialerKeypadDelegation() {
    if (document.documentElement.dataset.dialerKeypadDelegated === '1') {
        return;
    }

    document.documentElement.dataset.dialerKeypadDelegated = '1';

    document.addEventListener('click', (event) => {
        const keyBtn = event.target.closest('[data-dial-key]');
        if (keyBtn) {
            const form = keyBtn.closest('.ghl-dialer-originate-form');
            const numberInput = getDialerFormNumberInput(form);
            if (!form || !numberInput || numberInput.disabled) {
                return;
            }

            event.preventDefault();
            numberInput.value += keyBtn.getAttribute('data-dial-key') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleDialerNumberChange(form);

            return;
        }

        const backspaceBtn = event.target.closest('[data-dial-backspace]');
        if (backspaceBtn && !backspaceBtn.classList.contains('is-hidden')) {
            const form = backspaceBtn.closest('.ghl-dialer-originate-form');
            const numberInput = getDialerFormNumberInput(form);
            if (!form || !numberInput || numberInput.disabled) {
                return;
            }

            event.preventDefault();
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleDialerNumberChange(form);

            return;
        }

        const redialBtn = event.target.closest('[data-dial-number]');
        if (!redialBtn) {
            return;
        }

        const form = getActiveDialerForm();
        const numberInput = getDialerFormNumberInput(form);
        if (!form || !numberInput || numberInput.disabled) {
            return;
        }

        event.preventDefault();
        numberInput.value = redialBtn.getAttribute('data-dial-number') || '';
        numberInput.dispatchEvent(new Event('input', { bubbles: true }));
        handleDialerNumberChange(form);
        numberInput.focus();
    });
}

export function bootCommunicationsDialer() {
    initDialerOverlayCancel();
    initDialerKeypadDelegation();
    initCommunicationsDialer();
    initDialerSubmitFeedback();
    initPhoneLogRecording();
    initPhonePanelSwitch();
    initCallLogsInfiniteScroll();
    initCommunicationsPhoneNotes();
    startDialerSessionHygiene();
    // Theme toggle is owned by app.js (app-wide light/dark).
}

function stabilizeCallLogsScroll(workspace) {
    if (!workspace) {
        return;
    }

    const pane = workspace.querySelector('.ghl-dialer-center-logs--full');
    const scroll = workspace.querySelector('[data-call-logs-list]');
    if (!pane || !scroll) {
        return;
    }

    const apply = () => {
        const header = pane.querySelector('.ghl-dialer-center-logs__header');
        const headerHeight = header?.offsetHeight ?? 0;
        const paneHeight = pane.clientHeight;

        if (paneHeight > headerHeight + 48) {
            const maxHeight = paneHeight - headerHeight;
            scroll.style.maxHeight = `${maxHeight}px`;
            scroll.style.height = `${maxHeight}px`;
        }
    };

    requestAnimationFrame(() => {
        apply();
        requestAnimationFrame(apply);
    });
}

function bindCallLogsScrollResize(workspace) {
    if (!workspace || workspace.dataset.callLogsScrollResizeBound === '1') {
        return;
    }

    workspace.dataset.callLogsScrollResizeBound = '1';

    const onResize = () => stabilizeCallLogsScroll(workspace);
    window.addEventListener('resize', onResize, { passive: true });

    if (typeof ResizeObserver !== 'undefined') {
        const pane = workspace.querySelector('.ghl-dialer-center-logs--full');
        if (pane) {
            const observer = new ResizeObserver(() => stabilizeCallLogsScroll(workspace));
            observer.observe(pane);
        }
    }
}

function setPhoneWorkspaceView(workspace, view, recordingRole = null) {
    if (!workspace) {
        return;
    }

    const isAdminSplit = workspace.classList.contains('ch-dial-workspace--admin');
    const nextView = ['logs', 'leads', 'recordings', 'dialer'].includes(view) ? view : 'dialer';
    workspace.dataset.phoneView = isAdminSplit && nextView === 'dialer' ? 'logs' : nextView;

    if (recordingRole !== null) {
        workspace.dataset.recordingRole = recordingRole;
    } else if (nextView !== 'recordings') {
        workspace.dataset.recordingRole = '';
    }

    const activeRecordingRole = workspace.dataset.recordingRole || '';

    workspace.querySelectorAll('[data-phone-panel-view]').forEach((btn) => {
        const sameView = btn.dataset.phonePanelView === nextView;
        const btnRole = btn.dataset.recordingRole ?? '';
        const exactRoleMatch = sameView && (
            nextView !== 'recordings'
            || (btn.hasAttribute('data-recording-role') && btnRole === activeRecordingRole)
        );
        btn.classList.toggle('is-active', exactRoleMatch);
        btn.setAttribute('aria-selected', exactRoleMatch ? 'true' : 'false');
    });

    const titleEl = workspace.querySelector('[data-phone-recordings-title]');
    if (titleEl) {
        titleEl.textContent = activeRecordingRole === 'agent'
            ? 'Agent recordings'
            : (activeRecordingRole === 'team_lead' ? 'Team lead recordings' : 'Call Recording');
    }

    const logsPane = workspace.querySelector('[data-phone-logs-pane]');
    const leadsPane = workspace.querySelector('[data-phone-leads-pane]');
    const recordingsPane = workspace.querySelector('[data-phone-recordings-pane]');
    const dialPaneWrap = workspace.querySelector('[data-phone-dial-pane-wrap]');

    if (isAdminSplit) {
        const showLogs = nextView === 'logs';
        const showLeads = nextView === 'leads';
        const showRecordings = nextView === 'recordings';
        logsPane?.classList.toggle('hidden', !showLogs);
        logsPane?.classList.toggle('is-visible', showLogs);
        logsPane?.setAttribute('aria-hidden', showLogs ? 'false' : 'true');
        leadsPane?.classList.toggle('hidden', !showLeads);
        leadsPane?.classList.toggle('is-visible', showLeads);
        leadsPane?.setAttribute('aria-hidden', showLeads ? 'false' : 'true');
        recordingsPane?.classList.toggle('hidden', !showRecordings);
        recordingsPane?.classList.toggle('is-visible', showRecordings);
        recordingsPane?.setAttribute('aria-hidden', showRecordings ? 'false' : 'true');
        dialPaneWrap?.classList.remove('hidden');
        dialPaneWrap?.classList.add('is-visible');
    } else {
        const showLogs = nextView === 'logs';
        const showRecordings = nextView === 'recordings';
        const showDialer = nextView === 'dialer';
        logsPane?.classList.toggle('hidden', !showLogs);
        logsPane?.classList.toggle('is-visible', showLogs);
        leadsPane?.classList.add('hidden');
        recordingsPane?.classList.toggle('hidden', !showRecordings);
        recordingsPane?.classList.toggle('is-visible', showRecordings);
        dialPaneWrap?.classList.toggle('hidden', !(showDialer || showRecordings));
        dialPaneWrap?.classList.toggle('is-visible', showDialer || showRecordings);
    }

    stabilizeCallLogsScroll(workspace);
}

function initPhonePanelSwitch(root = document) {
    const scope = root === document ? document : root;
    const workspaces = scope.querySelectorAll('[data-phone-workspace]');
    if (!workspaces.length) {
        return;
    }

    workspaces.forEach((workspace) => {
        if (workspace.dataset.phonePanelSwitchInit === '1') {
            return;
        }

        workspace.dataset.phonePanelSwitchInit = '1';

        if (workspace.dataset.phonePanelSwitchBound === '1') {
            return;
        }

        workspace.dataset.phonePanelSwitchBound = '1';

        workspace.addEventListener('click', (event) => {
            const btn = event.target.closest('[data-phone-panel-view]');
            if (!btn || !workspace.contains(btn)) {
                return;
            }

            event.preventDefault();
            const view = btn.dataset.phonePanelView;
            const recordingRole = btn.hasAttribute('data-recording-role')
                ? (btn.dataset.recordingRole || '')
                : null;
            setPhoneWorkspaceView(workspace, view, recordingRole);
            initCallLogsInfiniteScroll(workspace);
            if (view === 'leads') {
                window.initImportedLeadsList?.(workspace);
            }
            if (view === 'recordings') {
                reloadCallRecordingsList(workspace, workspace.dataset.recordingRole || '');
            }
        });

        // Always sync pane visibility on init (HTML may set data-phone-view
        // before JS runs; admin split must hide inactive left panes).
        const initialView = workspace.classList.contains('ch-dial-workspace--admin')
            ? (workspace.dataset.phoneView && workspace.dataset.phoneView !== 'dialer'
                ? workspace.dataset.phoneView
                : 'logs')
            : (workspace.dataset.phoneView || 'dialer');
        setPhoneWorkspaceView(workspace, initialView);

        bindCallLogsScrollResize(workspace);
        stabilizeCallLogsScroll(workspace);
    });
}

function initPhoneLogRecording(root = document) {
    const scope = root === document ? document : root;
    const dialPane = scope.querySelector('[data-phone-dial-pane]');
    const recordingPane = scope.querySelector('[data-phone-recording-pane]');
    if (!dialPane || !recordingPane) {
        return;
    }

    if (dialPane.dataset.phoneLogRecordingInit === '1') {
        return;
    }
    dialPane.dataset.phoneLogRecordingInit = '1';

    const metaEl = recordingPane.querySelector('[data-phone-recording-meta]');
    const audioEl = recordingPane.querySelector('[data-phone-recording-audio]');
    const playerEl = recordingPane.querySelector('[data-phone-recording-player]');
    const emptyEl = recordingPane.querySelector('[data-phone-recording-empty]');
    const actionsEl = recordingPane.querySelector('[data-phone-recording-actions]');
    const downloadEl = recordingPane.querySelector('[data-phone-recording-download]');
    const playBtnEl = recordingPane.querySelector('[data-phone-recording-play]');
    const backBtn = recordingPane.querySelector('[data-phone-back-dialer]');
    let objectUrl = null;
    let activeRow = null;
    let activeDownloadUrl = '';

    const revokeObjectUrl = () => {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    };

    const bindDownloadHref = (url) => {
        activeDownloadUrl = String(url || '').trim();
        if (!downloadEl) {
            return;
        }
        if (activeDownloadUrl) {
            downloadEl.href = activeDownloadUrl;
            downloadEl.setAttribute('download', '');
            downloadEl.classList.remove('is-disabled');
            downloadEl.removeAttribute('aria-disabled');
        } else {
            downloadEl.href = '#';
            downloadEl.classList.add('is-disabled');
            downloadEl.setAttribute('aria-disabled', 'true');
        }
    };

    const triggerDownload = async (event) => {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        const url = activeDownloadUrl || downloadEl?.getAttribute('href') || '';
        if (!url || url === '#') {
            return false;
        }

        // Prefer already-loaded blob so download works even if streaming URL is flaky.
        if (objectUrl) {
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = `call-recording-${Date.now()}.mp3`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            return true;
        }

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'audio/*,*/*' },
            });
            if (!response.ok) {
                throw new Error(`Download failed (${response.status})`);
            }
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = `call-recording-${Date.now()}.mp3`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1500);
            return true;
        } catch (error) {
            console.warn('[dialer] recording download failed', error);
            // Fallback: navigate with cookies.
            window.location.assign(url);
            return false;
        }
    };

    const showDialer = () => {
        recordingPane.classList.add('hidden');
        recordingPane.hidden = true;
        dialPane.classList.remove('hidden');
        if (audioEl) {
            audioEl.pause();
            audioEl.removeAttribute('src');
        }
        playerEl?.classList.add('hidden');
        revokeObjectUrl();
        bindDownloadHref('');
        activeRow?.classList.remove('is-active');
        activeRow = null;
    };

    const showRecording = async (row, autoPlay = false) => {
        activeRow?.classList.remove('is-active');
        activeRow = row;
        row.classList.add('is-active');

        const direction = row.dataset.logDirection || 'call';
        const phone = row.dataset.logPhone || '—';
        const extension = row.dataset.logExtension || '';
        const result = row.dataset.logResult || '—';
        const time = row.dataset.logTime || '—';
        let hasRecording = row.dataset.hasRecording === '1';
        let playUrl = row.dataset.playUrl || '';
        let downloadUrl = row.dataset.downloadUrl || '';
        let recordingStatus = row.dataset.recordingStatus || 'none';
        const callLogRef = row.dataset.logCallRef || '';
        const directionLabel = direction.charAt(0).toUpperCase() + direction.slice(1);

        if (metaEl) {
            metaEl.innerHTML = `
                <p class="ghl-phone-recording-meta__line">${directionLabel}${extension ? ` · Ext ${extension}` : ''}</p>
                <p class="ghl-phone-recording-meta__number">${phone}</p>
                <p class="ghl-phone-recording-meta__sub">${time} · ${result}</p>
            `;
        }

        // Prefer staying on Call Recording tab when opened from that list.
        const workspace = dialPane.closest('[data-phone-workspace]');
        if (row.closest('[data-phone-recordings-pane]')) {
            setPhoneWorkspaceView(workspace, 'recordings');
        } else if (workspace?.classList.contains('ch-dial-workspace--admin')) {
            setPhoneWorkspaceView(workspace, 'logs');
        } else {
            setPhoneWorkspaceView(workspace, 'dialer');
        }

        dialPane.classList.add('hidden');
        recordingPane.classList.remove('hidden');
        recordingPane.hidden = false;
        bindDownloadHref(downloadUrl);

        const setEmptyMessage = (message) => {
            if (emptyEl) {
                emptyEl.textContent = message;
            }
        };

        const updateRecordingUi = () => {
            const showPlayer = hasRecording && playUrl;
            emptyEl?.classList.toggle('hidden', showPlayer);
            actionsEl?.classList.toggle('hidden', !showPlayer);
            playerEl?.classList.toggle('hidden', !showPlayer);
            bindDownloadHref(downloadUrl || row.dataset.downloadUrl || '');

            if (!showPlayer) {
                if (recordingStatus === 'pending' || recordingStatus === 'none') {
                    setEmptyMessage('Saving call recording… Morpheus may take a minute to finalize analog recordings.');
                } else if (recordingStatus === 'unavailable') {
                    setEmptyMessage('No recording is available for this call.');
                } else {
                    setEmptyMessage('No recording is available for this call yet.');
                }
            }
        };

        updateRecordingUi();

        const syncUrl = dialPane.closest('[data-phone-workspace]')?.dataset.recordingSyncUrl
            || scope.querySelector('[data-recording-sync-url]')?.dataset.recordingSyncUrl
            || document.querySelector('[data-recording-sync-url]')?.getAttribute('data-recording-sync-url');

        if ((!hasRecording || !playUrl) && callLogRef && syncUrl) {
            try {
                const syncResponse = await fetch(syncUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ call_log_ref: callLogRef }),
                });

                if (syncResponse.ok) {
                    const payload = await syncResponse.json();
                    hasRecording = Boolean(payload.has_recording);
                    playUrl = payload.play_url || '';
                    downloadUrl = payload.download_url || '';
                    row.dataset.hasRecording = hasRecording ? '1' : '0';
                    row.dataset.playUrl = playUrl;
                    row.dataset.downloadUrl = downloadUrl;
                    row.dataset.recordingStatus = payload.recording_status || recordingStatus;
                    recordingStatus = row.dataset.recordingStatus;
                    updateRecordingUi();
                }
            } catch {
                // Keep empty-state messaging.
            }
        }

        if (!hasRecording || !playUrl || !audioEl) {
            return;
        }

        revokeObjectUrl();
        audioEl.pause();
        audioEl.removeAttribute('src');

        try {
            const response = await fetch(playUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'audio/*,*/*' },
            });

            if (!response.ok) {
                emptyEl?.classList.remove('hidden');
                audioEl.classList.add('hidden');
                // Keep download available if we still have a URL.
                actionsEl?.classList.toggle('hidden', !downloadUrl);
                playerEl?.classList.add('hidden');
                bindDownloadHref(downloadUrl);
                return;
            }

            const blob = await response.blob();
            if (!blob.size) {
                emptyEl?.classList.remove('hidden');
                audioEl.classList.add('hidden');
                actionsEl?.classList.toggle('hidden', !downloadUrl);
                playerEl?.classList.add('hidden');
                bindDownloadHref(downloadUrl);
                return;
            }

            objectUrl = URL.createObjectURL(blob);
            audioEl.autoplay = false;
            audioEl.src = objectUrl;
            audioEl.classList.remove('hidden');
            playerEl?.classList.remove('hidden');
            emptyEl?.classList.add('hidden');
            actionsEl?.classList.remove('hidden');
            bindDownloadHref(downloadUrl || playUrl);
            // Load only — autoplay only when requested via Play / Rec button.
            audioEl.pause();
            try {
                audioEl.currentTime = 0;
            } catch {
                // Ignore if metadata not ready yet.
            }
            audioEl.load();

            if (autoPlay) {
                try {
                    await audioEl.play();
                } catch {
                    // Browser blocked play without gesture; controls remain available.
                }
            }
        } catch {
            emptyEl?.classList.remove('hidden');
            audioEl.classList.add('hidden');
            actionsEl?.classList.toggle('hidden', !downloadUrl);
            playerEl?.classList.add('hidden');
            bindDownloadHref(downloadUrl);
        }
    };

    scope.querySelectorAll('[data-call-logs-list], [data-call-recordings-list]').forEach((list) => {
        list.addEventListener('click', (event) => {
            const playBtn = event.target.closest('[data-recording-play]');
            const row = event.target.closest('[data-phone-log-row]');
            if (!row || !list.contains(row)) {
                return;
            }
            if (event.target.closest('[data-dial-number]')) {
                return;
            }
            if (event.target.closest('[data-log-notes-toggle], [data-log-notes-panel], [data-log-notes-save]')) {
                return;
            }
            if (event.target.closest('a[download], .ghl-dialer-recording-download, [data-phone-recording-download]')) {
                return;
            }
            if (list.matches('[data-call-recordings-list]') && !playBtn && !row.dataset.playUrl) {
                return;
            }
            // Rec / Play button starts audio; plain row click opens panel without autoplay.
            showRecording(row, Boolean(playBtn));
        });

        list.addEventListener('keydown', (event) => {
            const row = event.target.closest('[data-phone-log-row]');
            if (!row || !list.contains(row)) {
                return;
            }
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            // Let agents type spaces and newlines inside note fields.
            if (event.target.closest('textarea, input, [contenteditable="true"]')) {
                return;
            }
            if (event.target.closest('[data-dial-number]')) {
                return;
            }
            if (event.target.closest('[data-log-notes-toggle], [data-log-notes-panel], [data-log-notes-save]')) {
                return;
            }

            event.preventDefault();
            const playBtn = event.target.closest('[data-recording-play]');
            showRecording(row, Boolean(playBtn));
        });
    });

    downloadEl?.addEventListener('click', (event) => {
        void triggerDownload(event);
    });

    playBtnEl?.addEventListener('click', async () => {
        if (!audioEl?.src) {
            if (activeRow) {
                await showRecording(activeRow, true);
            }
            return;
        }
        try {
            await audioEl.play();
        } catch {
            // Controls remain available if autoplay is blocked.
        }
    });

    backBtn?.addEventListener('click', showDialer);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function buildRecordingRow(log) {
    const direction = escapeHtml(log.direction || 'call');
    const directionLabel = direction.charAt(0).toUpperCase() + direction.slice(1);
    const phone = escapeHtml(log.phone_display || log.phone || '—');
    const leadName = escapeHtml(String(log.lead_name || '').trim());
    const agentName = escapeHtml(String(log.agent_name || '').trim());
    const agentRoleLabel = escapeHtml(String(log.agent_role_label || '').trim());
    const result = escapeHtml(log.disposition || log.result || '—');
    const timeAgo = escapeHtml(log.time_ago || '—');
    const durationLabel = escapeHtml(log.duration_label || '0s');
    const callLogRef = escapeHtml(log.call_log_ref || log.id || '');
    const hasRecording = Boolean(log.has_recording);
    const recordingStatus = escapeHtml(log.recording_status || (hasRecording ? 'ready' : 'none'));
    const playUrl = escapeHtml(log.play_url || '');
    const downloadUrl = escapeHtml(log.download_url || '');
    const agentMeta = agentName ? ` · ${agentName}` : '';
    const roleMeta = agentRoleLabel ? ` · <span class="ghl-dialer-recording-role">${agentRoleLabel}</span>` : '';
    const metaExtra = result && result !== '—' ? ` · ${result}` : '';

    return `
        <div class="ghl-dialer-recording-row" data-phone-log-row data-recording-row tabindex="0"
            data-log-direction="${direction}"
            data-log-phone="${escapeHtml(log.phone || '')}"
            data-log-result="${result}"
            data-log-time="${escapeHtml(log.time_label || '—')}"
            data-log-call-ref="${callLogRef}"
            data-log-lead-name="${leadName}"
            data-log-phone-display="${phone}"
            data-has-recording="${hasRecording ? '1' : '0'}"
            data-recording-status="${recordingStatus}"
            data-play-url="${playUrl}"
            data-download-url="${downloadUrl}">
            <div class="ghl-dialer-recording-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <line x1="12" y1="19" x2="12" y2="23"/>
                    <line x1="8" y1="23" x2="16" y2="23"/>
                </svg>
            </div>
            <div class="ghl-dialer-recording-main">
                ${leadName ? `<span class="ghl-dialer-recording-name">${leadName}</span>` : ''}
                <span class="ghl-dialer-recording-number">${phone}</span>
                <span class="ghl-dialer-recording-meta">${directionLabel} · ${timeAgo} · ${durationLabel}${agentMeta}${roleMeta}${metaExtra}</span>
            </div>
            <div class="ghl-dialer-recording-actions">
                ${hasRecording
                    ? `<button type="button" class="ghl-dialer-recording-play" data-recording-play title="Play recording">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        Play
                    </button>
                    ${downloadUrl ? `<a class="ghl-dialer-recording-download" href="${downloadUrl}" download title="Download recording">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        <span>Download</span>
                    </a>` : ''}`
                    : `<span class="ghl-dialer-recording-pending">${recordingStatus === 'pending' ? 'Saving…' : 'Unavailable'}</span>`}
            </div>
        </div>
    `;
}

function reloadCallRecordingsList(workspace, recordingRole = '') {
    const list = workspace?.querySelector?.('[data-call-recordings-list]');
    if (!list) {
        return;
    }

    const items = list.querySelector('[data-call-recordings-items]');
    if (items) {
        items.innerHTML = '';
    }
    list.dataset.recordingRole = recordingRole || '';
    list.dataset.callRecordingsOffset = '0';
    list.dataset.callRecordingsHasMore = '1';
    list.dataset.callRecordingsInit = '0';
    initCallRecordingsList(workspace);
}

function initCallRecordingsList(root = document) {
    const scope = root === document ? document : root;
    const lists = scope.querySelectorAll('[data-call-recordings-list]');

    lists.forEach((list) => {
        if (list.dataset.callRecordingsInit === '1') {
            return;
        }
        list.dataset.callRecordingsInit = '1';

        const items = list.querySelector('[data-call-recordings-items]');
        const loading = list.querySelector('[data-call-recordings-loading]');
        const sentinel = list.querySelector('[data-call-recordings-sentinel]');
        const url = list.dataset.callLogsUrl;
        if (!url || !items) {
            return;
        }

        let fetching = false;

        const fetchMore = async ({ reset = false } = {}) => {
            if (fetching || (!reset && list.dataset.callRecordingsHasMore === '0')) {
                return;
            }

            fetching = true;
            loading?.classList.remove('hidden');

            try {
                const offset = reset
                    ? 0
                    : (Number.parseInt(list.dataset.callRecordingsOffset || '0', 10) || 0);
                const recordingRole = list.dataset.recordingRole || '';
                const params = new URLSearchParams({
                    offset: String(offset),
                    per_page: '20',
                    recordings_only: '1',
                });
                if (recordingRole) {
                    params.set('recording_role', recordingRole);
                }
                const response = await fetch(`${url}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.message || 'Could not load recordings.');
                }

                const logs = payload.logs || [];
                if (reset) {
                    items.innerHTML = '';
                }
                items.querySelector('[data-call-recordings-empty]')?.remove();

                if (logs.length === 0 && offset === 0) {
                    const emptyLabel = recordingRole === 'agent'
                        ? 'No agent recordings yet.'
                        : (recordingRole === 'team_lead'
                            ? 'No team lead recordings yet.'
                            : 'No call recordings yet.');
                    items.innerHTML = `<p class="ghl-dialer-recent-empty" data-call-recordings-empty>${emptyLabel}</p>`;
                } else {
                    const wrapper = document.createElement('div');
                    wrapper.innerHTML = logs.map((log) => buildRecordingRow(log)).join('');
                    wrapper.childNodes.forEach((node) => {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            items.appendChild(node);
                        }
                    });
                }

                list.dataset.callRecordingsOffset = String(payload.next_offset ?? offset + logs.length);
                list.dataset.callRecordingsHasMore = payload.has_more ? '1' : '0';
            } catch (error) {
                console.warn('[dialer] recordings fetch failed', error);
            } finally {
                loading?.classList.add('hidden');
                fetching = false;
            }
        };

        if (items.children.length === 0 || list.dataset.callRecordingsOffset === '0') {
            void fetchMore({ reset: true });
        }

        if (sentinel && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    void fetchMore();
                }
            }, { root: list, rootMargin: '120px' });
            observer.observe(sentinel);
        }
    });
}

function buildCallLogRow(log) {
    const direction = escapeHtml(log.direction || 'call');
    const directionLabel = direction.charAt(0).toUpperCase() + direction.slice(1);
    const extension = log.extension ? `<span class="ghl-dialer-recent-ext">Ext ${escapeHtml(log.extension)}</span>` : '';
    const agentName = escapeHtml(String(log.agent_name || '').trim());
    const agent = agentName ? `<span class="ghl-dialer-recent-agent">${agentName}</span>` : '';
    const phone = escapeHtml(log.phone_display || log.phone || '—');
    const leadName = escapeHtml(String(log.lead_name || '').trim());
    const leadContact = escapeHtml(String(log.lead_contact || '').trim());
    const leadFileNameRaw = String(log.lead_file_name || '').trim();
    const leadFileName = escapeHtml(leadFileNameRaw);
    const leadNameBlock = leadName
        ? `<span class="ghl-dialer-recent-name">${leadName}</span>${leadContact ? `<span class="ghl-dialer-recent-contact-name">${leadContact}</span>` : ''}`
        : '';
    const leadFileBlock = leadFileName
        ? `<span class="ghl-dialer-recent-file" title="${leadFileName}">${leadFileName}</span>`
        : '';
    // CDR statuses only — agent disposition "No Answer" must still render.
    const statusLikeDispositions = ['', '—', '-', 'completed', 'initiated', 'connected', 'answered', 'no-answer', 'busy', 'failed', 'missed', 'unknown', 'hangup', 'canceled', 'cancelled'];
    let rawDisposition = String(log.disposition || '').trim();
    if (rawDisposition && statusLikeDispositions.includes(rawDisposition.toLowerCase())) {
        rawDisposition = '';
    }
    // Prefer explicit agent disposition for the call-log row body.
    const disposition = escapeHtml(rawDisposition);
    const result = escapeHtml(rawDisposition);
    const timeAgo = escapeHtml(log.time_ago || '—');
    const durationLabel = escapeHtml(log.duration_label || '0s');
    const callLogRef = escapeHtml(log.call_log_ref || log.id || '');
    const callNote = escapeHtml(log.call_note || '');
    const phoneNote = escapeHtml(log.phone_note || '');
    let displayNoteRaw = String(log.call_note || '').trim().replace(/[ \t]+/g, ' ');
    const compactNote = displayNoteRaw.replace(/\s+/g, '');
    if (/^(NoAnswer)+$/i.test(compactNote)) {
        displayNoteRaw = 'No Answer';
    } else if (rawDisposition) {
        const compactDispo = rawDisposition.replace(/\s+/g, '');
        if (compactDispo && new RegExp(`^(${compactDispo.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&')})+$`, 'i').test(compactNote)) {
            displayNoteRaw = rawDisposition;
        }
    }
    const displayNote = escapeHtml(displayNoteRaw);
    const hasNotes = Boolean(log.call_note && String(log.call_note).trim());
    const hasRecording = Boolean(log.has_recording);
    const recordingStatus = escapeHtml(log.recording_status || (hasRecording ? 'ready' : 'none'));
    const statusLikeNotes = ['no answer', 'no-answer', 'busy', 'connected', 'answered', 'failed', 'missed', 'completed', 'initiated'];
    const noteLooksLikeStatus = displayNoteRaw && statusLikeNotes.includes(displayNoteRaw.toLowerCase());
    const notePreview = displayNote && !noteLooksLikeStatus && displayNoteRaw.toLowerCase() !== rawDisposition.toLowerCase()
        ? `<p class="ghl-dialer-recent-field" data-log-comment-line><span class="ghl-dialer-recent-field__label">Comment:</span> <span class="ghl-dialer-recent-field__value" data-log-note-preview>${displayNote}</span></p>`
        : '';
    const inCallNotesRaw = String(log.in_call_notes || '').trim();
    const inCallNotes = escapeHtml(inCallNotesRaw);
    const notesPreview = inCallNotesRaw
        ? `<p class="ghl-dialer-recent-field" data-log-notes-line><span class="ghl-dialer-recent-field__label">Notes:</span> <span class="ghl-dialer-recent-field__value" data-log-notes-preview>${inCallNotes}</span></p>`
        : '';
    const dispositionPreview = disposition
        ? `<p class="ghl-dialer-recent-field ghl-dialer-recent-field--disposition" data-log-disposition-line><span class="ghl-dialer-recent-field__label">Disposition:</span> <span class="ghl-dialer-recent-field__value" data-log-disposition>${disposition}</span></p>`
        : '';
    const fieldsBlock = (notesPreview || notePreview || dispositionPreview)
        ? `<div class="ghl-dialer-recent-fields" data-log-fields>${notesPreview}${notePreview}${dispositionPreview}</div>`
        : '';
    const notesBtn = log.phone
        ? `<button type="button" class="ghl-dialer-recent-notes-btn ${hasNotes ? 'has-notes' : ''}"
            data-log-notes-toggle aria-expanded="false" title="Notes" aria-label="Toggle notes">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
            </svg>
        </button>`
        : '';
    const callBtn = log.phone
        ? `<button type="button" class="ghl-dialer-recent-call-btn" data-dial-number="${escapeHtml(log.phone)}">Call</button>`
        : '';
    const notesPanel = log.phone
        ? `<div class="ghl-dialer-recent-notes hidden" data-log-notes-panel>
            <textarea id="call-log-note-${callLogRef || 'row'}" name="call_log_note"
                class="ghl-dialer-recent-notes-input" data-log-notes-input rows="4"
                placeholder="Notes for this call (${escapeHtml(log.time_label || '—')})…" maxlength="5000">${displayNote}</textarea>
            <div class="ghl-dialer-recent-notes-actions">
                <span class="ghl-dialer-recent-notes-status" data-log-notes-status aria-live="polite"></span>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-log-notes-save>Save</button>
            </div>
        </div>`
        : '';
    const recBadge = hasRecording
        ? `<button type="button" class="ghl-dialer-recent-rec" data-recording-play title="Play recording" aria-label="Play call recording">Rec</button>`
        : '';

    return `
        <div class="ghl-dialer-recent-row" data-phone-log-row tabindex="0"
            data-log-direction="${direction}"
            data-log-phone="${escapeHtml(log.phone || '')}"
            data-log-extension="${escapeHtml(log.extension || '')}"
            data-log-agent="${agentName}"
            data-log-result="${result}"
            data-log-time="${escapeHtml(log.time_label || '—')}"
            data-log-call-ref="${callLogRef}"
            data-log-lead-name="${leadName}"
            data-log-phone-display="${phone}"
            data-log-call-note="${callNote}"
            data-log-phone-note="${phoneNote}"
            data-has-notes="${hasNotes ? '1' : '0'}"
            data-has-recording="${hasRecording ? '1' : '0'}"
            data-recording-status="${recordingStatus}"
            data-play-url="${escapeHtml(log.play_url || '')}"
            data-download-url="${escapeHtml(log.download_url || '')}">
            <div class="ghl-dialer-recent-main">
                <div class="ghl-dialer-recent-head">
                    <span class="ghl-dialer-recent-dir">${directionLabel}</span>
                    ${extension}
                    ${agent}
                    ${recBadge}
                </div>
                <div class="ghl-dialer-recent-contact">
                    ${leadNameBlock}
                    <span class="ghl-dialer-recent-number">${phone}</span>
                    ${leadFileBlock}
                </div>
                <div class="ghl-dialer-recent-meta-row">
                    <span class="ghl-dialer-recent-meta">
                        <span class="ghl-dialer-recent-meta__time">${timeAgo}</span>
                        <span class="ghl-dialer-recent-meta__sep" aria-hidden="true">·</span>
                        <span class="ghl-dialer-recent-duration">${durationLabel}</span>
                        ${disposition ? `<span class="ghl-dialer-recent-meta__sep" aria-hidden="true">·</span><span class="ghl-dialer-recent-result ghl-dialer-recent-result--disposition">${disposition}</span>` : ''}
                    </span>
                </div>
                ${fieldsBlock}
            </div>
            <div class="ghl-dialer-recent-actions">
                ${notesBtn}
                ${callBtn}
            </div>
            ${notesPanel}
        </div>
    `;
}

function appendCallLogRows(itemsContainer, logs) {
    if (!itemsContainer || !Array.isArray(logs) || logs.length === 0) {
        return;
    }

    itemsContainer.querySelector('[data-call-logs-empty]')?.remove();

    const seen = new Set(
        [...itemsContainer.querySelectorAll('[data-phone-log-row]')].map((row) => {
            return row.getAttribute('data-log-call-ref')
                || `${row.getAttribute('data-log-phone') || ''}|${row.getAttribute('data-log-time') || ''}`;
        }),
    );

    const uniqueLogs = logs.filter((log) => {
        const key = String(log.id || log.call_log_ref || `${log.phone || ''}|${log.time_label || ''}`);
        if (seen.has(key)) {
            return false;
        }
        seen.add(key);
        return true;
    });

    if (uniqueLogs.length === 0) {
        return;
    }

    const fragment = document.createDocumentFragment();
    const wrapper = document.createElement('div');
    wrapper.innerHTML = uniqueLogs.map((log) => buildCallLogRow(log)).join('');
    wrapper.childNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) {
            fragment.appendChild(node);
        }
    });
    itemsContainer.appendChild(fragment);
}

function initCallLogsInfiniteScroll(root = document) {
    const scope = root === document ? document : root;
    const lists = scope.querySelectorAll('[data-call-logs-list]');

    lists.forEach((list) => {
        if (list.dataset.callLogsInit === '1') {
            return;
        }

        list.dataset.callLogsInit = '1';

        const itemsContainer = list.querySelector('[data-call-logs-items]');
        const loadingEl = list.querySelector('[data-call-logs-loading]');
        const sentinel = list.querySelector('[data-call-logs-sentinel]');
        const apiUrl = list.dataset.callLogsUrl || '';
        let offset = Number.parseInt(list.dataset.callLogsOffset || '0', 10) || 0;
        let hasMore = list.dataset.callLogsHasMore === '1';
        let loading = false;

        const loadMore = async () => {
            if (!hasMore || loading || !apiUrl || !itemsContainer) {
                return;
            }

            loading = true;
            loadingEl?.classList.remove('hidden');

            try {
                const url = new URL(apiUrl, window.location.origin);
                url.searchParams.set('offset', String(offset));
                url.searchParams.set('per_page', '30');

                const fetchOnce = async (timeoutMs) => {
                    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                    const timeoutId = window.setTimeout(() => {
                        try {
                            controller?.abort('timeout');
                        } catch {
                            // ignore
                        }
                    }, timeoutMs);

                    try {
                        return await fetch(url.toString(), {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            signal: controller?.signal,
                        });
                    } finally {
                        window.clearTimeout(timeoutId);
                    }
                };

                // First paint used to cancel at 12s — leave enough room + one retry.
                let response;
                try {
                    response = await fetchOnce(30000);
                } catch (firstError) {
                    const aborted = firstError?.name === 'AbortError'
                        || String(firstError?.message || '').toLowerCase().includes('abort');
                    if (!aborted) {
                        throw firstError;
                    }
                    response = await fetchOnce(30000);
                }

                if (!response.ok) {
                    throw new Error(`Call logs request failed (${response.status})`);
                }

                const payload = await response.json();
                appendCallLogRows(itemsContainer, payload.logs || []);
                offset = Number.parseInt(payload.next_offset ?? offset, 10) || offset;
                hasMore = Boolean(payload.has_more);
                list.dataset.callLogsOffset = String(offset);
                list.dataset.callLogsHasMore = hasMore ? '1' : '0';
            } catch (error) {
                console.warn('[communications-dialer] call logs scroll failed', error);
                // Keep hasMore true on timeout so the next scroll/tab switch can retry.
                const aborted = error?.name === 'AbortError'
                    || String(error?.message || '').toLowerCase().includes('abort');
                if (!aborted) {
                    hasMore = false;
                    list.dataset.callLogsHasMore = '0';
                }
            } finally {
                loading = false;
                loadingEl?.classList.add('hidden');
                const emptyEl = itemsContainer?.querySelector('[data-call-logs-empty]');
                if (
                    emptyEl
                    && !itemsContainer?.querySelector('[data-phone-log-row], .ghl-dialer-recent-row')
                ) {
                    emptyEl.textContent = 'No recent calls yet.';
                }
            }
        };

        const maybeLoadMore = () => {
            if (!hasMore || loading) {
                return;
            }

            const threshold = 72;
            const distanceFromBottom = list.scrollHeight - list.scrollTop - list.clientHeight;
            if (distanceFromBottom <= threshold) {
                void loadMore();
            }
        };

        list.addEventListener('scroll', maybeLoadMore, { passive: true });

        if (sentinel && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver(
                (entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) {
                        void loadMore();
                    }
                },
                {
                    root: list,
                    rootMargin: '120px',
                    threshold: 0,
                },
            );
            observer.observe(sentinel);
        }

        // SSR may already include the first 30. Only hydrate when the list is empty.
        const hasRows = Boolean(itemsContainer?.querySelector('[data-phone-log-row], .ghl-dialer-recent-row'));
        if (apiUrl && !hasRows) {
            hasMore = true;
            list.dataset.callLogsHasMore = '1';
            offset = 0;
            list.dataset.callLogsOffset = '0';
            window.setTimeout(() => {
                void loadMore().then(() => {
                    const emptyEl = itemsContainer?.querySelector('[data-call-logs-empty]');
                    if (itemsContainer?.querySelector('[data-phone-log-row], .ghl-dialer-recent-row')) {
                        emptyEl?.remove();
                    } else if (emptyEl) {
                        emptyEl.textContent = 'No recent calls yet.';
                    }
                });
            }, 120);
        }

        const workspace = list.closest('[data-phone-workspace]');
        if (workspace) {
            bindCallLogsScrollResize(workspace);
            stabilizeCallLogsScroll(workspace);
        }
    });
}

// Backwards compatibility for any inline callers still present in cached pages.
export { buildCallLogRow };
if (typeof window !== 'undefined') {
    window.buildCallLogRow = buildCallLogRow;
}

export async function placeOutboundCall(phone, meta = {}) {
    const form = getActiveDialerForm();
    const numberInput = getDialerFormNumberInput(form);
    const dialBtn = form?.querySelector('button[type="submit"]');

    if (!form || !numberInput) {
        showToast('Dialer is not ready yet.', 'error');

        return false;
    }

    const destination = normalizePhone(phone);
    if (!destination || !isValidPstnDestination(destination)) {
        showToast('This lead does not have a valid phone number.', 'error');

        return false;
    }

    numberInput.value = destination;
    if (meta?.id) {
        form.dataset.dialLeadId = String(meta.id);
    }
    if (meta?.name) {
        form.dataset.dialLeadName = String(meta.name);
    }
    form.dataset.dialLeadPhone = destination;
    const metaFile = String(meta?.file_name || meta?.workflow || '').trim();
    if (metaFile) {
        form.dataset.dialLeadFileName = metaFile;
    }
    handleDialerNumberChange(form);

    // Auto-dial queue hops already waited 6s — skip the pad cooldown so calls connect.
    const skipCooldown = Boolean(meta?.skipCooldown)
        || document.body.classList.contains('ch-dial-auto-session');

    let placed = false;
    if (document.querySelector('[data-webphone-panel]')) {
        placed = await originateViaJson(form, dialBtn, { skipCooldown });
    } else {
        form.requestSubmit?.();
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        placed = true;
    }

    // Keep pad empty while call card owns the number — do not touch lead dataset or hangup UI.
    if (placed) {
        clearDialerDestinationInput();
    }

    return placed;
}

window.initGhlDialer = function initGhlDialer(config) {
    const numberInput = document.getElementById(config.numberInputId);
    const form = numberInput?.closest('.ghl-dialer-originate-form');
    if (form) {
        attachDialerForm(form);
    }
};
