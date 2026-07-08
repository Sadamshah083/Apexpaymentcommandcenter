import { hideLoadingOverlay } from './form-loading.js';
import { showToast } from './toast.js';
import {
    cancelPendingWebphoneConnect,
    ensureWebphoneReady,
    getWebphone,
} from './communications-webphone.js';
const STORAGE_KEY = 'communications.dialer_extension';

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

function updateDialerRouteSummary(callerSelect) {
    const summary = document.querySelector('[data-dialer-from-did]');
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

async function originateViaJson(form, dialBtn) {
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
    phone.setCallContext('outbound', destination);
    phone.markClickToCallPending();

    const panel = document.querySelector('[data-webphone-panel]');
    const wssUrl = panel?.dataset.wssUrl || phone.configuredWssUrl?.() || '';

    try {
        await phone.assertTransportForOriginate();
    } catch (error) {
        showToast(error?.message || 'Connect your Phone line (WebSocket) before calling.', 'error');
        dialBtn.removeAttribute('disabled');
        dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        dialBtn.removeAttribute('aria-disabled');

        return false;
    }

    phone.logPhone?.('info', 'Placing click-to-call via originate API', {
        destination,
        wssUrl,
        originateUrl: form.action,
        transportConnected: phone.isTransportConnected?.() ?? false,
        wssLive: panel?.dataset.wssLive === '1',
    });

    formData.set('webphone_transport_connected', '1');

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: formData,
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok === false) {
            const message = data.extension_busy
                ? (data.error || 'Your extension is busy. Click Connect line again, or try extension 1001.')
                : (data.error || 'Could not place the call. Check your extension and try again.');

            showToast(message, 'error');

            return false;
        }

        if (data.call_uuid) {
            phone.setMorpheusCallUuid(data.call_uuid);
        }

        const fromExtension = data.from ? String(data.from) : formData.get('from_extension');
        if (fromExtension) {
            await phone.ensureLiveTransport(String(fromExtension)).catch(() => {});
        }

        if (data.line_reset) {
            showToast('Line was reset to clear a busy extension — reconnecting…', 'warning');
            await phone.connect(phone.currentExtension || undefined).catch(() => {});
            phone.markClickToCallPending();
        }

        const dialTarget =
            data.to && String(data.to).length >= 10
                ? `+${String(data.to).replace(/\D/g, '')}`
                : destination;

        phone.setCustomerFirstOutbound(Boolean(data.customer_first));
        phone.showClickToCallRinging(dialTarget, { customerFirst: Boolean(data.customer_first) });

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

        showToast(data.warning || successMessage, data.warning ? 'warning' : 'success');

        return true;
    } catch (error) {
        showToast(error.message || 'Could not place the call.', 'error');

        return false;
    } finally {
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
    const hasExtension = !callerSelect || callerSelect.value !== '';

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

function attachDialerForm(form) {
    if (!form || form.dataset.dialerInit === '1') {
        return;
    }

    form.dataset.dialerInit = '1';

    const numberInput = form.querySelector('[name="destination"]');
    const callerSelect = form.querySelector('[name="from_extension"]');
    const dialBtn = form.querySelector('button[type="submit"]');
    const backspace = form.querySelector('[data-dial-backspace]');
    const keypadRoot = form.querySelector('.ghl-dialer-keypad') || form;

    if (!numberInput || !dialBtn) {
        return;
    }

    if (dialBtn.hasAttribute('disabled') && dialBtn.disabled) {
        dialBtn.dataset.serverDisabled = '1';
    }

    if (callerSelect) {
        const savedCaller = localStorage.getItem(STORAGE_KEY);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }

        callerSelect.addEventListener('change', () => {
            localStorage.setItem(STORAGE_KEY, callerSelect.value || '');
            refreshDialButton(numberInput, callerSelect, dialBtn);
            getWebphone().syncSelectedExtension();
            updateDialerRouteSummary(callerSelect);
        });
    }

    updateDialerRouteSummary(callerSelect);

    const handleNumberChange = () => refreshDialButton(numberInput, callerSelect, dialBtn);

    numberInput.addEventListener('input', handleNumberChange);
    numberInput.addEventListener('change', handleNumberChange);

    keypadRoot.querySelectorAll('[data-dial-key]').forEach((button) => {
        button.addEventListener('click', () => {
            numberInput.value += button.getAttribute('data-dial-key') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    });

    document.querySelectorAll('[data-dial-number]').forEach((button) => {
        button.addEventListener('click', () => {
            numberInput.value = button.getAttribute('data-dial-number') || '';
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    });

    if (backspace) {
        backspace.addEventListener('click', () => {
            numberInput.value = numberInput.value.slice(0, -1);
            numberInput.dispatchEvent(new Event('input', { bubbles: true }));
            handleNumberChange();
        });
    }

    form.addEventListener('submit', async (event) => {
        numberInput.value = normalizePhone(numberInput.value) || numberInput.value;

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

            const ready = await ensureWebphoneReady({ silent: false });
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
                const phone = getWebphone();

                if (!ready || !phone.canDirectDial()) {
                    showToast(
                        'Phone line not connected — wait for Registered (WebSocket), allow microphone, then try Call again.',
                        'error',
                    );
                    dialBtn.removeAttribute('disabled');
                    dialBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    dialBtn.removeAttribute('aria-disabled');

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

export function bootCommunicationsDialer() {
    initDialerOverlayCancel();
    initCommunicationsDialer();
    initDialerSubmitFeedback();
}

// Backwards compatibility for any inline callers still present in cached pages.
window.initGhlDialer = function initGhlDialer(config) {
    const numberInput = document.getElementById(config.numberInputId);
    const form = numberInput?.closest('.ghl-dialer-originate-form');
    if (form) {
        attachDialerForm(form);
    }
};
