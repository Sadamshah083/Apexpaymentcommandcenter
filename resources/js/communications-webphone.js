import { Inviter, Registerer, RegistererState, SessionState, UserAgent } from 'sip.js';
import { hideLoadingOverlay } from './form-loading.js';
import { showToast } from './toast.js';

const STORAGE_KEY = 'communications.webphone_extension';

let singleton = null;

function formatDuration(totalSeconds) {
    const minutes = Math.floor(totalSeconds / 60)
        .toString()
        .padStart(2, '0');
    const seconds = Math.floor(totalSeconds % 60)
        .toString()
        .padStart(2, '0');

    return `${minutes}:${seconds}`;
}

function selectedExtension() {
    const select = document.querySelector('[name="from_extension"]');
    if (select?.value) {
        return select.value;
    }

    const panel = document.querySelector('[data-webphone-panel]');

    return panel?.dataset.defaultExtension || localStorage.getItem(STORAGE_KEY) || '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function createCancelledError() {
    const error = new Error('Connection cancelled.');
    error.cancelled = true;

    return error;
}

function stripCarrierPrefix(value) {
    const raw = String(value || '').trim();
    if (raw.includes('#')) {
        return raw.slice(raw.lastIndexOf('#') + 1).trim();
    }

    return raw;
}

function normalizeDialTarget(value) {
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

function isExtensionTarget(value) {
    const digits = String(value || '').replace(/\D/g, '');

    return digits !== '' && digits.length <= 6;
}

function formatOutboundDid(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    if (digits.length === 10) {
        return `+1${digits}`;
    }
    if (digits.length === 11 && digits.startsWith('1')) {
        return `+${digits}`;
    }

    return `+${digits}`;
}

function selectedExtensionOutboundDid() {
    const select = document.querySelector('[name="from_extension"]');
    const selected = select?.selectedOptions?.[0];
    if (selected?.dataset?.outboundDid) {
        return formatOutboundDid(selected.dataset.outboundDid);
    }

    return formatOutboundDid(document.querySelector('[data-webphone-panel]')?.dataset.defaultOutboundDid);
}

class ApexWebphone {
    constructor() {
        this.userAgent = null;
        this.registerer = null;
        this.session = null;
        this.config = null;
        this.state = 'offline';
        this.pendingClickToCall = false;
        this.panel = null;
        this.connectPromise = null;
        this.currentExtension = '';
        this.lastError = '';
        this.callStartedAt = null;
        this.callTimer = null;
        this.connectAttempt = 0;
        this.cancelledConnectAttempt = 0;
        this.audioContext = null;
        this.ringtoneInterval = null;
        this.ringbackInterval = null;
        this.outboundWaitingActive = false;
        this.currentCallDirection = null;
        this.currentCallPeer = '';
        this.morpheusCallUuid = null;
        this.recordingActive = false;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this.destinationPollTimer = null;
        this.directDialActive = false;
        this.customerFirstOutbound = false;
        this.outboundDialStartedAt = 0;
        this.callOnHold = false;
    }

    bindPanel(panel) {
        if (panel.dataset.webphoneBound === '1' && this.panel === panel) {
            return;
        }

        panel.dataset.webphoneBound = '1';
        this.panel = panel;
        this.ui = {
            statusText: panel.querySelector('[data-webphone-status-text]'),
            dot: panel.querySelector('[data-webphone-dot]'),
            hint: panel.querySelector('[data-webphone-hint]'),
            stage: panel.querySelector('[data-webphone-stage]'),
            stageNote: panel.querySelector('[data-webphone-stage-note]'),
            extension: panel.querySelector('[data-webphone-extension]'),
            domain: panel.querySelector('[data-webphone-domain]'),
            transport: panel.querySelector('[data-webphone-transport]'),
            callInfo: panel.querySelector('[data-webphone-call-info]'),
            callCard: panel.querySelector('[data-webphone-call-card]'),
            callTitle: panel.querySelector('[data-webphone-call-title]'),
            callSubtitle: panel.querySelector('[data-webphone-call-subtitle]'),
            callTimer: panel.querySelector('[data-webphone-call-timer]'),
            connectBtn: panel.querySelector('[data-webphone-connect]'),
            disconnectBtn: panel.querySelector('[data-webphone-disconnect]'),
            answerBtn: panel.querySelector('[data-webphone-answer]'),
            hangupBtn: panel.querySelector('[data-webphone-hangup]'),
            bridgeBtn: panel.querySelector('[data-webphone-bridge]'),
            bridgePanel: panel.querySelector('[data-webphone-bridge-panel]'),
            bridgeExtension: panel.querySelector('[data-webphone-bridge-extension]'),
            iframe: panel.querySelector('[data-webphone-iframe]'),
            remoteAudio: panel.querySelector('[data-webphone-remote]'),
            floatingPopup: document.querySelector('[data-webphone-floating]'),
            floatingTitle: document.querySelector('[data-webphone-floating-title]'),
            floatingSubtitle: document.querySelector('[data-webphone-floating-subtitle]'),
            floatingDetail: document.querySelector('[data-webphone-floating-detail]'),
            floatingBadge: document.querySelector('[data-webphone-floating-badge]'),
            floatingVisual: document.querySelector('[data-webphone-floating-visual]'),
            floatingTimerRow: document.querySelector('[data-webphone-floating-timer-row]'),
            floatingTimerLabel: document.querySelector('[data-webphone-floating-timer-label]'),
            floatingTimer: document.querySelector('[data-webphone-floating-timer]'),
            floatingAnswer: document.querySelector('[data-webphone-floating-answer]'),
            floatingHangup: document.querySelector('[data-webphone-floating-hangup]'),
            floatingRecord: document.querySelector('[data-webphone-floating-record]'),
            floatingRecordLabel: document.querySelector('[data-webphone-floating-record-label]'),
            callControls: panel.querySelector('[data-webphone-call-controls]'),
            recordBtn: panel.querySelector('[data-webphone-record]'),
            recordLabel: panel.querySelector('[data-webphone-record-label]'),
            endCallBtn: panel.querySelector('[data-webphone-end-call]'),
            holdBtn: panel.querySelector('[data-webphone-hold]'),
            transferBtn: panel.querySelector('[data-webphone-transfer]'),
            floatingHold: document.querySelector('[data-webphone-floating-hold]'),
            floatingTransfer: document.querySelector('[data-webphone-floating-transfer]'),
        };

        this.ui.connectBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.connect(selectedExtension()).catch((error) => {
                this.handleConnectFailure(error);
            });
        });

        this.ui.disconnectBtn?.addEventListener('click', () => {
            this.disconnect().catch(() => {});
        });

        this.ui.answerBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.answer().catch((error) => {
                showToast(error.message || 'Could not answer call.', 'error');
            });
        });

        this.ui.hangupBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.hangup().catch(() => {});
        });

        this.ui.bridgeBtn?.addEventListener('click', () => {
            this.openBridge();
        });

        this.ui.floatingAnswer?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.answer().catch((error) => {
                showToast(error.message || 'Could not answer call.', 'error');
            });
        });

        this.ui.floatingHangup?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.hangup().catch(() => {});
        });

        this.ui.recordBtn?.addEventListener('click', () => this.toggleRecording());
        this.ui.floatingRecord?.addEventListener('click', () => this.toggleRecording());
        this.ui.endCallBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.hangup().catch(() => {});
        });

        this.ui.holdBtn?.addEventListener('click', () => {
            this.toggleHold().catch((error) => {
                showToast(error.message || 'Could not update hold.', 'error');
            });
        });

        this.ui.floatingHold?.addEventListener('click', () => {
            this.toggleHold().catch((error) => {
                showToast(error.message || 'Could not update hold.', 'error');
            });
        });

        this.ui.transferBtn?.addEventListener('click', () => {
            this.promptTransfer().catch((error) => {
                showToast(error.message || 'Could not transfer call.', 'error');
            });
        });

        this.ui.floatingTransfer?.addEventListener('click', () => {
            this.promptTransfer().catch((error) => {
                showToast(error.message || 'Could not transfer call.', 'error');
            });
        });

        const extSelect = document.querySelector('[name="from_extension"]');
        extSelect?.addEventListener('change', () => this.syncSelectedExtension());

        this.syncSelectedExtension();
        this.setState('offline');
        this.primeAudio();
    }

    primeAudio() {
        const unlock = () => {
            this.ensureAudioContext().catch(() => {});
        };

        document.addEventListener('pointerdown', unlock, { once: true });
        document.addEventListener('keydown', unlock, { once: true });
    }

    async ensureAudioContext() {
        const AudioCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtor) {
            return null;
        }

        if (!this.audioContext) {
            this.audioContext = new AudioCtor();
        }

        if (this.audioContext.state === 'suspended') {
            await this.audioContext.resume();
        }

        return this.audioContext;
    }

    startRingtone() {
        if (this.ringtoneInterval) {
            return;
        }

        const playBurst = async () => {
            const context = await this.ensureAudioContext();
            if (!context) {
                return;
            }

            const gain = context.createGain();
            gain.gain.setValueAtTime(0.0001, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.42, context.currentTime + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.95);
            gain.connect(context.destination);

            [880, 988, 1175].forEach((frequency, index) => {
                const oscillator = context.createOscillator();
                oscillator.type = 'triangle';
                oscillator.frequency.setValueAtTime(frequency, context.currentTime + index * 0.18);
                oscillator.connect(gain);
                oscillator.start(context.currentTime + index * 0.18);
                oscillator.stop(context.currentTime + 0.95);
            });
        };

        playBurst().catch(() => {});
        this.ringtoneInterval = window.setInterval(() => {
            playBurst().catch(() => {});
        }, 1400);
    }

    stopRingtone() {
        if (!this.ringtoneInterval) {
            return;
        }

        window.clearInterval(this.ringtoneInterval);
        this.ringtoneInterval = null;
    }

    startRingback() {
        if (this.ringbackInterval) {
            return;
        }

        this.outboundWaitingActive = true;

        const playOutboundRing = async () => {
            if (!this.outboundWaitingActive) {
                return;
            }

            const context = await this.ensureAudioContext();
            if (!context) {
                return;
            }

            const start = context.currentTime;
            const master = context.createGain();
            master.gain.setValueAtTime(0.0001, start);
            master.gain.exponentialRampToValueAtTime(0.3, start + 0.02);
            master.gain.setValueAtTime(0.3, start + 1.95);
            master.gain.exponentialRampToValueAtTime(0.0001, start + 2.05);
            master.connect(context.destination);

            // Classic phone ringback (iPhone-style waiting tone): 440 Hz + 480 Hz, 2s on / 4s off.
            [440, 480].forEach((frequency) => {
                const osc = context.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(frequency, start);
                osc.connect(master);
                osc.start(start);
                osc.stop(start + 2.05);
            });
        };

        playOutboundRing().catch(() => {});
        this.ringbackInterval = window.setInterval(() => {
            playOutboundRing().catch(() => {});
        }, 6000);
    }

    stopRingback() {
        this.outboundWaitingActive = false;

        if (!this.ringbackInterval) {
            return;
        }

        window.clearInterval(this.ringbackInterval);
        this.ringbackInterval = null;
    }

    toggleRecording() {
        this.recordingActive = !this.recordingActive;
        this.updateRecordingUi();

        if (this.recordingActive) {
            showToast('Recording is on. Morpheus saves this call automatically.', 'success');
        } else {
            showToast('Recording indicator off.', 'warning');
        }
    }

    updateRecordingUi() {
        const active = this.recordingActive;
        this.ui?.recordBtn?.classList.toggle('is-recording', active);
        this.ui?.floatingRecord?.classList.toggle('is-recording', active);

        const label = active ? 'Recording' : 'Record';
        if (this.ui?.recordLabel) {
            this.ui.recordLabel.textContent = label;
        }
        if (this.ui?.floatingRecordLabel) {
            this.ui.floatingRecordLabel.textContent = label;
        }
    }

    setCallControlsVisible(visible, { showRecord = false, showEndCall = false, showAnswer = false, showHold = false, showTransfer = false } = {}) {
        this.ui?.callControls?.classList.toggle('hidden', !visible);
        this.ui?.recordBtn?.classList.toggle('hidden', !showRecord);
        this.ui?.endCallBtn?.classList.toggle('hidden', !showEndCall);
        this.ui?.holdBtn?.classList.toggle('hidden', !showHold);
        this.ui?.transferBtn?.classList.toggle('hidden', !showTransfer);
        this.ui?.floatingRecord?.classList.toggle('hidden', !showRecord);
        this.ui?.floatingHangup?.classList.toggle('hidden', !showEndCall);
        this.ui?.floatingHold?.classList.toggle('hidden', !showHold);
        this.ui?.floatingTransfer?.classList.toggle('hidden', !showTransfer);
        this.ui?.floatingAnswer?.classList.toggle('hidden', !showAnswer);
    }

    handleConnectFailure(error) {
        if (error?.cancelled) {
            this.lastError = '';
            this.pendingClickToCall = false;
            this.setState('offline');
            if (this.ui?.hint) {
                this.ui.hint.textContent = 'Call canceled before the line connected.';
            }
            return;
        }

        let message = error?.message || 'Could not connect phone.';
        if (/websocket closed/i.test(message) && /1006/.test(message)) {
            message =
                'Phone WebSocket could not stay connected. Retrying through CRM proxy — click Connect line again. If it persists, allow microphone access and check your network firewall.';
        }
        this.lastError = message;
        hideLoadingOverlay();
        this.stopRingtone();
        this.setState('error', 'Offline');
        this.ui.hint.textContent = message;
        this.ui.bridgeBtn?.classList.remove('hidden');
        this.updateFloatingPopup({ visible: false });
        showToast(message, 'error');
    }

    openBridge() {
        const portalUrl = this.panel?.dataset.portalUrl;
        if (!portalUrl || !this.ui?.iframe) {
            return;
        }

        this.ui.iframe.src = portalUrl;
        this.ui.bridgePanel?.classList.remove('hidden');
        this.ui.bridgeBtn?.classList.add('hidden');
        this.setState('registered', 'Embedded phone');
        this.ui.hint.textContent =
            'Morpheus phone loaded below. Log in there, then dial from Quick dial above.';
        if (this.ui.stageNote) {
            this.ui.stageNote.textContent = 'Fallback phone is open and ready to use.';
        }
        showToast('Use the embedded Morpheus phone below to register your line.', 'warning');
    }

    markClickToCallPending() {
        this.pendingClickToCall = true;
        window.setTimeout(() => {
            this.pendingClickToCall = false;
        }, 120_000);
    }

    setCustomerFirstOutbound(enabled) {
        this.customerFirstOutbound = Boolean(enabled);
    }

    setMorpheusCallUuid(uuid) {
        this.morpheusCallUuid = uuid ? String(uuid) : null;
    }

    buildCallRouteDetail(destination) {
        const fromDid =
            formatOutboundDid(this.config?.outbound_caller_id) || selectedExtensionOutboundDid();
        const to = normalizeDialTarget(destination) || destination || this.currentCallPeer || '';
        const parts = [];

        if (fromDid) {
            parts.push(`From: ${fromDid}`);
        }
        if (to) {
            parts.push(`To: ${to}`);
        }

        return parts.join(' · ');
    }

    showClickToCallRinging(destination, { customerFirst = false } = {}) {
        const normalized = normalizeDialTarget(destination) || destination;
        const routeDetail = this.buildCallRouteDetail(normalized);
        hideLoadingOverlay();
        this.ensureAudioContext().catch(() => {});
        this.setCallContext('outbound', normalized);
        this.clickToCallActive = true;
        this.awaitingDestinationBridge = true;
        this.customerFirstOutbound = customerFirst;
        this.outboundDialStartedAt = Date.now();
        this.recordingActive = true;
        this.updateRecordingUi();
        this.stopRingtone();
        this.startRingback();
        const ringingCopy = customerFirst
            ? (normalized
                ? `Ringing ${normalized} — answer within 90 seconds. Keep Connect line on.`
                : 'Ringing your phone — answer within 90 seconds. Keep Connect line on.')
            : (normalized
                ? `Connecting your line… ${normalized} will ring once your browser phone answers.`
                : 'Connecting your line… the destination will ring once your browser phone answers.');
        this.updateCallCard({
            title: 'Outgoing call',
            subtitle: ringingCopy,
            detail: routeDetail,
            visible: true,
        });
        this.setCallControlsVisible(true, { showRecord: true, showEndCall: true, showAnswer: false });
        this.updateFloatingPopup({
            title: 'Outgoing call',
            subtitle: ringingCopy,
            detail: routeDetail,
            visible: true,
            statusLabel: customerFirst ? 'Ringing' : 'Connecting',
            showRingingVisual: true,
            showConnectedTimer: false,
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });
        this.setState('dialing');
        this.markClickToCallPending();
        this.startDestinationPoll();
    }

    hangupUrlTemplate() {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');

        return panel?.dataset.hangupUrl || '';
    }

    callStatusUrlTemplate() {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');

        return panel?.dataset.callStatusUrl || '';
    }

    callActionUrlTemplate(action) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        const key = `${action}Url`;

        return panel?.dataset[key] || '';
    }

    async postCallAction(action, body = {}) {
        const uuid = this.morpheusCallUuid;
        const template = this.callActionUrlTemplate(action);

        if (!uuid || !template) {
            throw new Error('No active Morpheus call to control.');
        }

        const url = template.replace('__UUID__', encodeURIComponent(uuid));
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok === false) {
            throw new Error(data.error || `Call ${action} failed.`);
        }

        return data;
    }

    updateHoldUi() {
        const label = this.callOnHold ? 'Resume' : 'Hold';
        if (this.ui?.holdBtn) {
            this.ui.holdBtn.textContent = label;
            this.ui.holdBtn.classList.toggle('is-active', this.callOnHold);
        }
        if (this.ui?.floatingHold) {
            this.ui.floatingHold.textContent = label;
            this.ui.floatingHold.classList.toggle('is-active', this.callOnHold);
        }
    }

    async toggleHold() {
        if (!this.morpheusCallUuid) {
            throw new Error('Connect a call before using hold.');
        }

        const action = this.callOnHold ? 'unhold' : 'hold';
        await this.postCallAction(action);
        this.callOnHold = !this.callOnHold;
        this.updateHoldUi();
        showToast(this.callOnHold ? 'Call on hold.' : 'Call resumed.', 'success');
    }

    async promptTransfer() {
        if (!this.morpheusCallUuid) {
            throw new Error('Connect a call before transferring.');
        }

        const destination = window.prompt('Transfer to extension or phone number:', '');
        if (!destination || !String(destination).trim()) {
            return;
        }

        const digits = String(destination).replace(/\D/g, '');
        const payload = digits.length <= 6 ? digits : digits;

        await this.postCallAction('transfer', { destination: payload });
        showToast(`Call transferred to ${destination}.`, 'success');
        await this.hangup().catch(() => {});
    }

    stopDestinationPoll() {
        if (!this.destinationPollTimer) {
            return;
        }

        window.clearInterval(this.destinationPollTimer);
        this.destinationPollTimer = null;
    }

    async pollDestinationOnce() {
        const uuid = this.morpheusCallUuid;
        const template = this.callStatusUrlTemplate();

        if (!uuid || !template || !this.awaitingDestinationBridge) {
            return;
        }

        const url = new URL(template.replace('__UUID__', encodeURIComponent(uuid)), window.location.origin);
        if (this.currentCallPeer) {
            url.searchParams.set('destination', this.currentCallPeer);
        }
        if (this.customerFirstOutbound) {
            url.searchParams.set('customer_first', '1');
        }

        try {
            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || data.ok === false) {
                return;
            }

            if (data.bridged_to && data.bridged_to !== uuid) {
                this.morpheusCallUuid = data.bridged_to;
            }

            if (data.destination_connected || (Number(data.billsec) >= 2 && data.live === false)) {
                this.markDestinationConnected();

                return;
            }

            if (!data.live && data.hangup_cause) {
                const elapsed = Date.now() - (this.outboundDialStartedAt || Date.now());
                const cause = String(data.hangup_cause);
                const billsec = Number(data.billsec ?? 0);
                const transientCauses = [
                    'NO_ROUTE_DESTINATION',
                    'NO_USER_RESPONSE',
                    'ORIGINATOR_CANCEL',
                    'USER_BUSY',
                    'CALL_REJECTED',
                ];

                if (
                    this.clickToCallActive
                    && billsec < 3
                    && elapsed < 120_000
                    && (this.customerFirstOutbound || transientCauses.includes(cause))
                ) {
                    return;
                }

                this.stopDestinationPoll();

                if (billsec >= 3) {
                    this.markDestinationConnected();

                    return;
                }

                const causeLabel = cause.replace(/_/g, ' ').toLowerCase();
                const message = this.customerFirstOutbound
                    ? 'Call ended before connecting. Keep Connect line on and try again.'
                    : `Destination leg ended (${causeLabel}). If the phone never rang, check Morpheus trunk routing.`;

                showToast(message, 'warning');
            }
        } catch {
            // Keep polling while the SIP leg is still active.
        }
    }

    startDestinationPoll() {
        this.stopDestinationPoll();
        this.pollDestinationOnce().catch(() => {});
        this.destinationPollTimer = window.setInterval(() => {
            this.pollDestinationOnce().catch(() => {});
        }, 1200);
    }

    markDestinationConnected() {
        if (!this.awaitingDestinationBridge) {
            return;
        }

        this.awaitingDestinationBridge = false;
        this.clickToCallActive = false;
        this.stopDestinationPoll();
        this.stopRingback();
        // Keep morpheusCallUuid for hold/transfer/hangup until the user ends the call.

        if (!this.callStartedAt) {
            this.startCallTimer();
        }

        this.updateCallCard({
            title: 'Call live',
            subtitle: 'Destination answered — two-way audio is active.',
            detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
            visible: true,
            timer: this.ui?.floatingTimer?.textContent || '00:00',
        });
        this.updateFloatingPopup({
            title: 'Call live',
            subtitle: 'Destination answered — two-way audio is active.',
            detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
            visible: true,
            timer: this.ui?.floatingTimer?.textContent || '00:00',
            statusLabel: 'Connected',
            showRingingVisual: false,
            showConnectedTimer: true,
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            showHold: Boolean(this.morpheusCallUuid),
            showTransfer: Boolean(this.morpheusCallUuid),
            state: 'in-call',
        });
        this.setCallControlsVisible(true, {
            showRecord: true,
            showEndCall: true,
            showHold: Boolean(this.morpheusCallUuid),
            showTransfer: Boolean(this.morpheusCallUuid),
        });
        this.setState('in-call');
    }

    async hangupMorpheusCall(uuid) {
        const template = this.hangupUrlTemplate();
        if (!template || !uuid) {
            return;
        }

        const url = template.replace('__UUID__', encodeURIComponent(uuid));

        try {
            await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });
        } catch {
            // SIP hangup may already have ended the call leg.
        }
    }

    cancelPendingConnect() {
        this.pendingClickToCall = false;
        this.cancelledConnectAttempt = this.connectAttempt;
        this.disconnect().catch(() => {});
    }

    isBridgeMode() {
        return !!(this.ui?.bridgePanel && !this.ui.bridgePanel.classList.contains('hidden'));
    }

    canDirectDial() {
        return !!(
            this.userAgent &&
            this.registerer?.state === RegistererState.Registered &&
            !this.isBridgeMode()
        );
    }

    setCallContext(direction, peer = '') {
        this.currentCallDirection = direction;
        this.currentCallPeer = peer;
    }

    syncSelectedExtension() {
        if (!this.ui) {
            return;
        }

        const extension = selectedExtension() || this.currentExtension || this.panel?.dataset.defaultExtension || '—';
        if (this.ui.extension) {
            this.ui.extension.textContent = extension;
        }
        if (this.ui.bridgeExtension) {
            this.ui.bridgeExtension.textContent = extension;
        }

        if (
            this.state === 'registered' &&
            this.currentExtension &&
            extension !== this.currentExtension &&
            this.ui.stageNote
        ) {
            this.ui.stageNote.textContent = `Ready on ${this.currentExtension}. Click Reconnect to switch to ${extension}.`;
        }
    }

    applyConfigMeta(config) {
        if (!this.ui || !config) {
            return;
        }

        config.display_name = this.sanitizeDisplayName(config);

        if (this.ui.domain) {
            this.ui.domain.textContent = config.domain || '—';
        }
        if (this.ui.transport) {
            this.ui.transport.textContent = config.wss_url || '—';
        }
        if (this.ui.extension) {
            this.ui.extension.textContent = config.extension || this.currentExtension || '—';
        }
        if (this.ui.bridgeExtension) {
            this.ui.bridgeExtension.textContent = config.extension || this.currentExtension || '—';
        }
    }

    updateCallCard({ title = '', subtitle = '', detail = '', visible = false, timer = '00:00' } = {}) {
        if (!this.ui) {
            return;
        }

        this.ui.callCard?.classList.toggle('hidden', !visible);
        if (this.ui.callTitle) {
            this.ui.callTitle.textContent = title;
        }
        if (this.ui.callSubtitle) {
            this.ui.callSubtitle.textContent = subtitle;
        }
        if (this.ui.callTimer) {
            this.ui.callTimer.textContent = timer;
        }
        if (this.ui.callInfo) {
            this.ui.callInfo.textContent = detail;
            this.ui.callInfo.classList.toggle('hidden', !detail);
        }
    }

    updateFloatingPopup({
        title = '',
        subtitle = '',
        detail = '',
        visible = false,
        timer = '00:00',
        statusLabel = '',
        showRingingVisual = false,
        showConnectedTimer = false,
        showAnswer = false,
        showHangup = false,
        showRecord = false,
        showHold = false,
        showTransfer = false,
        state = 'offline',
    } = {}) {
        if (!this.ui?.floatingPopup) {
            return;
        }

        this.ui.floatingPopup.classList.toggle('hidden', !visible);
        this.ui.floatingPopup.dataset.state = state;

        if (this.ui.floatingTitle) {
            this.ui.floatingTitle.textContent = title;
        }
        if (this.ui.floatingSubtitle) {
            this.ui.floatingSubtitle.textContent = subtitle;
        }
        if (this.ui.floatingDetail) {
            this.ui.floatingDetail.textContent = detail;
            this.ui.floatingDetail.classList.toggle('hidden', !detail);
        }
        if (this.ui.floatingBadge) {
            this.ui.floatingBadge.textContent = statusLabel;
            this.ui.floatingBadge.classList.toggle('hidden', !visible || !statusLabel);
            this.ui.floatingBadge.classList.toggle('is-connected', statusLabel === 'Connected');
            this.ui.floatingBadge.classList.toggle('is-ringing', statusLabel === 'Ringing');
        }
        if (this.ui.floatingVisual) {
            this.ui.floatingVisual.classList.toggle('hidden', !visible || !showRingingVisual);
        }
        if (this.ui.floatingTimerRow) {
            this.ui.floatingTimerRow.classList.toggle('hidden', !visible || !showConnectedTimer);
        }
        if (this.ui.floatingTimerLabel) {
            this.ui.floatingTimerLabel.textContent = 'Connected time';
        }
        if (this.ui.floatingTimer) {
            this.ui.floatingTimer.textContent = timer;
        }
        this.ui.floatingAnswer?.classList.toggle('hidden', !showAnswer);
        this.ui.floatingHangup?.classList.toggle('hidden', !showHangup);
        this.ui.floatingRecord?.classList.toggle('hidden', !showRecord);
        this.ui.floatingHold?.classList.toggle('hidden', !showHold);
        this.ui.floatingTransfer?.classList.toggle('hidden', !showTransfer);
    }

    startCallTimer() {
        if (this.awaitingDestinationBridge) {
            return;
        }

        this.stopCallTimer();
        this.callStartedAt = Date.now();
        this.ui?.callTimer?.classList.remove('hidden');
        this.ui?.floatingTimerRow?.classList.remove('hidden');
        if (this.ui?.floatingBadge) {
            this.ui.floatingBadge.textContent = 'Connected';
            this.ui.floatingBadge.classList.remove('hidden', 'is-ringing');
            this.ui.floatingBadge.classList.add('is-connected');
        }
        if (this.ui?.floatingVisual) {
            this.ui.floatingVisual.classList.add('hidden');
        }
        this.callTimer = window.setInterval(() => {
            if (!this.callStartedAt) {
                return;
            }

            const seconds = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
            const formatted = formatDuration(seconds);
            if (this.ui?.callTimer) {
                this.ui.callTimer.textContent = formatted;
            }
            if (this.ui?.floatingTimer && !this.ui.floatingPopup?.classList.contains('hidden')) {
                this.ui.floatingTimer.textContent = formatted;
            }
        }, 1000);
    }

    stopCallTimer() {
        if (this.callTimer) {
            window.clearInterval(this.callTimer);
            this.callTimer = null;
        }
        this.callStartedAt = null;
        if (this.ui?.callTimer) {
            this.ui.callTimer.textContent = '00:00';
        }
        if (this.ui?.floatingTimer) {
            this.ui.floatingTimer.textContent = '00:00';
        }
        this.ui?.floatingTimerRow?.classList.add('hidden');
    }

    setState(state, message = '') {
        this.state = state;

        if (!this.ui) {
            return;
        }

        const labels = {
            offline: 'Offline',
            connecting: 'Connecting…',
            registered: 'Registered',
            dialing: 'Dialing…',
            ringing: 'Ringing…',
            'in-call': 'On call',
            error: 'Error',
        };
        const stageNotes = {
            offline: 'Waiting to connect your line.',
            connecting: 'Preparing extension credentials and opening SIP over WSS.',
            registered: 'Line is live and ready for inbound or outbound calls.',
            dialing: 'Calling the destination over your registered browser line.',
            ringing: 'Live invite received. Answer to join the call.',
            'in-call': 'Audio is connected. Keep this tab open while you talk.',
            error: 'Connection failed. You can retry or open the fallback phone.',
        };

        this.ui.statusText.textContent = message || labels[state] || state;
        this.ui.dot.dataset.state = state;
        if (this.ui.stage) {
            this.ui.stage.dataset.state = state;
            this.ui.stage.textContent = labels[state] || state;
        }
        if (this.ui.stageNote) {
            this.ui.stageNote.textContent = stageNotes[state] || '';
        }

        const registered =
            state === 'registered' || state === 'dialing' || state === 'ringing' || state === 'in-call';
        this.ui.connectBtn.textContent = state === 'connecting' ? 'Connecting…' : registered ? 'Reconnect line' : 'Connect line';
        this.ui.connectBtn.disabled = state === 'connecting';
        this.ui.connectBtn.classList.toggle('hidden', state === 'dialing' || state === 'in-call');
        this.ui.disconnectBtn?.classList.toggle('hidden', !registered || state === 'dialing' || state === 'in-call');
        this.ui.disconnectBtn && (this.ui.disconnectBtn.disabled = state === 'connecting');
        const onActiveCall = state === 'dialing' || state === 'ringing' || state === 'in-call';
        const outboundActive =
            onActiveCall &&
            (this.currentCallDirection === 'outbound' ||
                this.clickToCallActive ||
                this.pendingClickToCall ||
                this.directDialActive);
        this.ui.answerBtn.classList.toggle('hidden', state !== 'ringing' || outboundActive);
        this.ui.hangupBtn.classList.toggle('hidden', state !== 'dialing' && state !== 'ringing' && state !== 'in-call');
        this.ui.hangupBtn?.classList.toggle('ghl-webphone-btn-end-call', state === 'dialing' || state === 'ringing' || state === 'in-call');

        this.setCallControlsVisible(onActiveCall, {
            showRecord: onActiveCall,
            showEndCall: onActiveCall,
            showHold: state === 'in-call' && Boolean(this.morpheusCallUuid),
            showTransfer: state === 'in-call' && Boolean(this.morpheusCallUuid),
            showAnswer: state === 'ringing' && !outboundActive,
        });

        if (state === 'registered' && !this.ui.bridgePanel?.classList.contains('hidden')) {
            return;
        }

        if (state === 'registered') {
            this.ui.hint.textContent = 'Phone ready — place calls from Quick dial below.';
            this.ui.bridgeBtn?.classList.add('hidden');
        } else if (state === 'dialing') {
            this.ui.hint.textContent = 'Calling the destination now. Hang up cancels the outbound attempt.';
        } else if (state === 'connecting') {
            this.ui.hint.textContent = 'Syncing SIP credentials and registering with Morpheus…';
        } else if (state === 'ringing') {
            this.ui.hint.textContent =
                'Answer picks up the live call on your browser line. Hang up declines or ends the ringing call.';
        } else if (state === 'in-call') {
            this.ui.hint.textContent = 'Call is live. Audio should be routed through your browser now.';
        } else if (state === 'offline' || state === 'error') {
            this.ui.hint.textContent =
                this.lastError ||
                'Built-in web phone — click Connect and allow microphone access.';
            this.setCallControlsVisible(false);
            this.updateFloatingPopup({ visible: false });
        }

        this.syncSelectedExtension();

        document.dispatchEvent(
            new CustomEvent('apex:webphone-state', { detail: { state, message: message || labels[state] || state } }),
        );
    }

    async syncExtensionInBackground(extension) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        if (!panel?.dataset.prepareUrl) {
            return;
        }

        try {
            await fetch(panel.dataset.prepareUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ extension }),
            });
        } catch {
            // Optional Morpheus password sync — connect does not depend on this.
        }
    }

    async prepareConfig(extension) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        if (!panel?.dataset.prepareUrl) {
            return this.fetchConfig(extension);
        }

        const response = await fetch(panel.dataset.prepareUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ extension }),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok || payload.ok === false) {
            if (response.status === 422) {
                return this.fetchConfig(extension);
            }

            throw new Error(payload.error || 'Could not prepare phone settings.');
        }

        if (!payload.config) {
            return this.fetchConfig(extension);
        }

        this.applyConfigMeta(payload.config);
        return payload.config;
    }

    async fetchConfig(extension) {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');
        if (!panel) {
            throw new Error('Webphone panel not found.');
        }

        const url = new URL(panel.dataset.configUrl, window.location.origin);
        url.searchParams.set('extension', extension);

        const response = await fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Could not load phone settings.');
        }

        this.applyConfigMeta(payload.config);
        return payload.config;
    }

    async connect(extension) {
        const normalized = String(extension || '').replace(/\D/g, '') || String(extension || '');
        if (!normalized) {
            throw new Error('Select your extension in the From dropdown first.');
        }

        if (this.state === 'registered' && this.currentExtension === normalized) {
            return true;
        }

        if (this.connectPromise) {
            return this.connectPromise;
        }

        const attempt = ++this.connectAttempt;
        this.connectPromise = this._connect(normalized, attempt).finally(() => {
            this.connectPromise = null;
        });

        return this.connectPromise;
    }

    throwIfConnectCancelled(attempt) {
        if (this.cancelledConnectAttempt === attempt) {
            throw createCancelledError();
        }
    }

    sanitizeDisplayName(config) {
        const callerId = String(config?.outbound_caller_id || '').replace(/\D/g, '');
        if (callerId) {
            return callerId;
        }

        const raw = String(config?.display_name || '').trim();
        if (raw === '' || /^(admin|setter|closer)_(super|ops|tl|ag)_[a-z0-9]{3}$/i.test(raw)) {
            return '';
        }

        if (/[<>"\\;]/.test(raw)) {
            return '';
        }

        return raw;
    }

    wssUrlPriority(url) {
        if (/morpheus\.cx:7443/i.test(url)) {
            return 0;
        }

        if (/morpheus-ws/i.test(url)) {
            return 2;
        }

        return 1;
    }

    normalizeWssUrl(url) {
        if (!url) {
            return null;
        }

        return String(url).trim();
    }

    expandWssCandidates(url) {
        const normalized = this.normalizeWssUrl(url);
        if (!normalized) {
            return [];
        }

        const candidates = [normalized];
        if (normalized.endsWith('/ws')) {
            const root = normalized.replace(/\/ws\/?$/, '/');
            if (!candidates.includes(root)) {
                candidates.push(root);
            }
        } else {
            const withWs = `${normalized.replace(/\/?$/, '')}/ws`;
            if (!candidates.includes(withWs)) {
                candidates.push(withWs);
            }
        }

        return candidates;
    }

    wssCandidates(config) {
        const urls = [config?.wss_url, config?.wss_url_fallback];
        const unique = [];

        urls.forEach((url) => {
            this.expandWssCandidates(url).forEach((candidate) => {
                if (candidate && !unique.includes(candidate)) {
                    unique.push(candidate);
                }
            });
        });

        return unique.sort((a, b) => this.wssUrlPriority(a) - this.wssUrlPriority(b));
    }

    async _connect(extension, attempt) {
        await this.disconnect(false);
        this.throwIfConnectCancelled(attempt);

        this.setState('connecting');
        this.config = await this.prepareConfig(extension);
        this.throwIfConnectCancelled(attempt);
        this.currentExtension = extension;
        this.config.display_name = this.sanitizeDisplayName(this.config);
        this.applyConfigMeta(this.config);
        localStorage.setItem(STORAGE_KEY, extension);

        if (!window.isSecureContext && window.location.protocol === 'http:') {
            throw new Error(
                'Microphone requires HTTPS. Click "Use embedded Morpheus phone" below instead.',
            );
        }

        const candidates = this.wssCandidates(this.config);
        if (candidates.length === 0) {
            throw new Error('No Morpheus WebSocket URL is configured for this extension.');
        }

        let lastError = new Error('Could not register with Morpheus.');

        for (let authAttempt = 0; authAttempt < 2; authAttempt++) {
            for (const wssUrl of candidates) {
                this.throwIfConnectCancelled(attempt);

                try {
                    await this._connectWithTransport(extension, attempt, wssUrl);
                    if (this.ui?.transport) {
                        this.ui.transport.textContent = wssUrl;
                    }

                    return true;
                } catch (error) {
                    lastError = error instanceof Error ? error : new Error('Could not register with Morpheus.');
                    await this.disconnect(false);
                }
            }

            const rejectedAuth =
                lastError.message.includes('403') || lastError.message.includes('401');

            if (!rejectedAuth || authAttempt > 0) {
                break;
            }

            this.config = await this.prepareConfig(extension);
            this.throwIfConnectCancelled(attempt);
            this.applyConfigMeta(this.config);
        }

        throw lastError;
    }

    async _connectWithTransport(extension, attempt, wssUrl) {
        const domain = this.config.domain;
        const sipUser = this.config.sip_user || this.config.auth_user || extension;
        const uri = UserAgent.makeURI(`sip:${sipUser}@${domain}`);
        if (!uri) {
            throw new Error('Invalid SIP address.');
        }

        const iceServers = (this.config.stun_servers || []).map((url) => ({ urls: url }));

        this.userAgent = new UserAgent({
            uri,
            transportOptions: {
                server: wssUrl,
                connectionTimeout: 15,
            },
            contactParams: {
                transport: 'ws',
            },
            authorizationUsername: this.config.auth_user,
            authorizationPassword: this.config.password,
            displayName: this.sanitizeDisplayName(this.config),
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: {
                    iceServers,
                },
            },
        });

        this.userAgent.delegate = {
            onInvite: (invitation) => this.handleInvite(invitation),
        };

        await this.userAgent.start();
        this.throwIfConnectCancelled(attempt);

        let transportError = '';

        if (this.userAgent.transport) {
            this.userAgent.transport.onDisconnect = (error) => {
                if (!error) {
                    return;
                }

                transportError = error.message || 'WebSocket disconnected.';
            };
        }

        this.registerer = new Registerer(this.userAgent, {
            expires: 300,
        });
        await this.waitForRegistration(this.registerer, attempt, wssUrl, () => transportError);
        this.throwIfConnectCancelled(attempt);
    }

    waitForRegistration(registerer, attempt, wssUrl, getTransportError) {
        return new Promise((resolve, reject) => {
            let settled = false;

            const finish = (fn, value) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                fn(value);
            };

            const timer = window.setTimeout(() => {
                const realm = this.config?.domain || 'Morpheus';
                const ext = this.currentExtension || 'your extension';
                const transportHint = getTransportError?.() || '';
                const suffix = transportHint
                    ? ` ${transportHint}`
                    : ` Tried ${wssUrl}. If this keeps failing, open the fallback Morpheus phone below.`;
                finish(
                    reject,
                    new Error(
                        `Could not register extension ${ext} on ${realm}.${suffix}`,
                    ),
                );
            }, 25000);

            registerer.stateChange.addListener((state) => {
                if (this.cancelledConnectAttempt === attempt) {
                    finish(reject, createCancelledError());
                    return;
                }

                if (state === RegistererState.Registered) {
                    this.setState('registered');
                    finish(resolve);
                }
            });

            registerer
                .register({
                    requestDelegate: {
                        onReject: (response) => {
                            const code = response?.message?.statusCode || 'error';
                            const reason = response?.message?.reasonPhrase || 'rejected';
                            finish(
                                reject,
                                new Error(`SIP registration rejected (${code} ${reason}) on ${wssUrl}.`),
                            );
                        },
                    },
                })
                .catch((error) => {
                    if (this.cancelledConnectAttempt === attempt) {
                        finish(reject, createCancelledError());
                        return;
                    }
                    finish(reject, error);
                });
        });
    }

    buildInviteExtraHeaders() {
        const headers = [];
        const domain = this.config?.dial_domain || this.config?.domain;
        const extension = this.config?.extension || this.currentExtension;
        const callerId = this.config?.outbound_caller_id;

        if (domain && extension) {
            headers.push(`P-Preferred-Identity: <sip:${extension}@${domain}>`);
        }

        if (domain && callerId) {
            const digits = String(callerId).replace(/\D/g, '');
            if (digits) {
                headers.push(`P-Asserted-Identity: <sip:${digits}@${domain}>`);
            }
        }

        const campaignId = this.config?.campaign_id;
        if (campaignId) {
            headers.push(`X-Campaign-ID: ${campaignId}`);
        }

        return headers;
    }

    buildTargetUri(destination) {
        const normalized = normalizeDialTarget(destination);
        if (!normalized || !this.config) {
            return null;
        }

        const host = this.config.dial_domain || this.config.domain;
        if (!host) {
            return null;
        }

        const prefix = this.config.outbound_prefix || '';
        const dialString = `${prefix}${normalized}`;
        const sipTarget = isExtensionTarget(dialString)
            ? `sip:${dialString}@${host}`
            : `sip:${dialString.replace(/^\+/, '')}@${host}${
                  this.config.sip_params ? `;${this.config.sip_params}` : ''
              }`;

        return UserAgent.makeURI(sipTarget);
    }

    async dial(destination) {
        const normalized = normalizeDialTarget(destination);
        if (!normalized) {
            throw new Error('Enter a valid phone number first.');
        }

        if (!this.canDirectDial()) {
            throw new Error('Connect your browser line before placing a direct call.');
        }

        if (this.session && this.session.state !== SessionState.Terminated) {
            throw new Error('Finish the current call before placing another one.');
        }

        const targetUri = this.buildTargetUri(normalized);
        if (!targetUri) {
            throw new Error('Could not build a SIP destination for this number.');
        }

        await this.ensureAudioContext().catch(() => {});

        const inviter = new Inviter(this.userAgent, targetUri);

        this.session = inviter;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this.directDialActive = true;
        this.setCallContext('outbound', normalized);
        this.recordingActive = true;
        this.updateRecordingUi();
        this.bindSession(inviter);
        this.startRingback();
        this.updateCallCard({
            title: 'Outgoing call',
            subtitle: `Ringing ${normalized}… waiting for answer.`,
            detail: `To: ${normalized}`,
            visible: true,
        });
        this.updateFloatingPopup({
            title: 'Outgoing call',
            subtitle: `Ringing ${normalized}… waiting for answer.`,
            detail: `To: ${normalized}`,
            visible: true,
            statusLabel: 'Ringing',
            showRingingVisual: true,
            showConnectedTimer: false,
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });
        this.setState('dialing');

        try {
            await inviter.invite({
                requestOptions: {
                    extraHeaders: this.buildInviteExtraHeaders(),
                },
                sessionDescriptionHandlerOptions: {
                    constraints: { audio: true, video: false },
                },
            });
            showToast(`Calling ${normalized}… ringing until the destination answers.`, 'success');
            return true;
        } catch (error) {
            this.stopRingback();
            hideLoadingOverlay();
            this.clearSession();
            throw error instanceof Error ? error : new Error('Could not place the call.');
        }
    }

    handleInvite(invitation) {
        if (this.directDialActive) {
            return;
        }

        this.session = invitation;
        const caller = invitation.remoteIdentity?.uri?.user || 'Unknown';
        const isOutboundLeg = this.pendingClickToCall;

        if (isOutboundLeg) {
            this.setCallContext('outbound', this.currentCallPeer || caller);
            this.stopRingtone();
            this.startRingback();
            this.updateFloatingPopup({
                title: 'Outgoing call',
                subtitle: this.currentCallPeer
                    ? `Ringing ${this.currentCallPeer}… waiting for answer.`
                    : 'Ringing destination… waiting for answer.',
                detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
                visible: true,
                statusLabel: 'Ringing',
                showRingingVisual: true,
                showConnectedTimer: false,
                showAnswer: false,
                showHangup: true,
                showRecord: true,
                state: 'dialing',
            });
        } else {
            this.setCallContext('inbound', caller);
            this.stopRingback();
        }

        this.bindSession(invitation);

        if (!isOutboundLeg) {
            this.updateCallCard({
                title: 'Incoming call',
                subtitle: 'A live call is waiting on your line.',
                detail: `Caller: ${caller}`,
                visible: true,
            });
            this.updateFloatingPopup({
                title: 'Incoming call',
                subtitle: 'Answer your line to join the call.',
                detail: `Caller: ${caller}`,
                visible: true,
                statusLabel: 'Ringing',
                showRingingVisual: true,
                showConnectedTimer: false,
                showAnswer: true,
                showHangup: true,
                state: 'ringing',
            });
            this.startRingtone();
            this.setState('ringing');
        } else {
            this.updateCallCard({
                title: 'Connecting to destination',
                subtitle: 'Ringing until the destination picks up.',
                detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
                visible: true,
            });
            this.updateFloatingPopup({
                title: 'Outgoing call',
                subtitle: 'Ringing until the destination picks up.',
                detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
                visible: true,
                statusLabel: 'Ringing',
                showRingingVisual: true,
                showConnectedTimer: false,
                showAnswer: false,
                showHangup: true,
                showRecord: true,
                state: 'dialing',
            });
            this.setState('dialing');
        }

        if (isOutboundLeg) {
            this.clickToCallActive = true;
            this.pendingClickToCall = false;

            if (this.config?.auto_answer_click_to_call) {
                this.ensureAudioContext()
                    .catch(() => {})
                    .finally(() => {
                        this.answer().catch(() => {
                            showToast('Could not auto-connect your line — click End and try again.', 'warning');
                        });
                    });

                return;
            }
        }

        if (this.config?.auto_answer_click_to_call && this.pendingClickToCall) {
            this.pendingClickToCall = false;
            this.ensureAudioContext()
                .catch(() => {})
                .finally(() => {
                    this.answer().catch(() => {
                        showToast('Incoming call — click Answer.', 'warning');
                    });
                });

            return;
        }

        if (!isOutboundLeg) {
            showToast('Incoming call — click Answer in the Phone panel.', 'warning');
        }
    }

    bindSession(session) {
        session.stateChange.addListener((state) => {
            if (state === SessionState.Establishing) {
                this.stopRingtone();
                if (this.currentCallDirection === 'outbound') {
                    this.startRingback();
                    this.updateCallCard({
                        title: 'Outgoing call',
                        subtitle: this.currentCallPeer
                            ? `Ringing ${this.currentCallPeer}… waiting for answer.`
                            : 'Ringing destination… waiting for answer.',
                        detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
                        visible: true,
                    });
                    this.updateFloatingPopup({
                        title: 'Outgoing call',
                        subtitle: this.currentCallPeer
                            ? `Ringing ${this.currentCallPeer}… waiting for answer.`
                            : 'Ringing destination… waiting for answer.',
                        detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
                        visible: true,
                        statusLabel: 'Ringing',
                        showRingingVisual: true,
                        showConnectedTimer: false,
                        showAnswer: false,
                        showHangup: true,
                        showRecord: true,
                        state: 'dialing',
                    });
                    this.setState('dialing');
                } else {
                    this.updateCallCard({
                        title: 'Connecting call',
                        subtitle: 'Negotiating media with Morpheus…',
                        detail: this.currentCallPeer ? `Caller: ${this.currentCallPeer}` : '',
                        visible: true,
                    });
                    this.updateFloatingPopup({
                        title: 'Connecting call',
                        subtitle: 'Negotiating media with Morpheus…',
                        detail: this.currentCallPeer ? `Caller: ${this.currentCallPeer}` : '',
                        visible: true,
                        statusLabel: 'Connecting',
                        showRingingVisual: false,
                        showConnectedTimer: false,
                        showAnswer: false,
                        showHangup: true,
                        state: 'connecting',
                    });
                }
            }

            if (state === SessionState.Established) {
                this.stopRingtone();
                hideLoadingOverlay();
                this.recordingActive = true;
                this.updateRecordingUi();

                const isClickToCallOutbound =
                    this.clickToCallActive && this.currentCallDirection === 'outbound';

                if (isClickToCallOutbound) {
                    this.awaitingDestinationBridge = true;
                    this.startRingback();
                    const routeDetail = this.buildCallRouteDetail(this.currentCallPeer);
                    this.updateCallCard({
                        title: 'Outgoing call',
                        subtitle: this.currentCallPeer
                            ? `Dialing ${this.currentCallPeer}… connected to Morpheus trunk.`
                            : 'Ringing destination… waiting for answer.',
                        detail: routeDetail,
                        visible: true,
                    });
                    this.updateFloatingPopup({
                        title: 'Outgoing call',
                        subtitle: this.currentCallPeer
                            ? `Dialing ${this.currentCallPeer}… destination sees your outbound caller ID.`
                            : 'Ringing destination… waiting for answer.',
                        detail: routeDetail,
                        visible: true,
                        statusLabel: 'Ringing',
                        showRingingVisual: true,
                        showConnectedTimer: false,
                        showAnswer: false,
                        showHangup: true,
                        showRecord: true,
                        state: 'dialing',
                    });
                    this.setState('dialing');
                    this.startDestinationPoll();
                    this.attachRemoteAudio(session);

                    return;
                }

                this.stopRingback();
                this.morpheusCallUuid = null;
                this.startCallTimer();
                this.updateCallCard({
                    title: 'Call live',
                    subtitle: 'Two-way audio is active.',
                    detail:
                        this.currentCallDirection === 'outbound'
                            ? `To: ${this.currentCallPeer}`
                            : this.currentCallPeer
                              ? `Caller: ${this.currentCallPeer}`
                              : '',
                    visible: true,
                    timer: '00:00',
                });
                this.updateFloatingPopup({
                    title: 'Call live',
                    subtitle: 'Two-way audio is active.',
                    detail:
                        this.currentCallDirection === 'outbound'
                            ? `To: ${this.currentCallPeer}`
                            : this.currentCallPeer
                              ? `Caller: ${this.currentCallPeer}`
                              : '',
                    visible: true,
                    timer: '00:00',
                    statusLabel: 'Connected',
                    showRingingVisual: false,
                    showConnectedTimer: true,
                    showAnswer: false,
                    showHangup: true,
                    showRecord: true,
                    state: 'in-call',
                });
                this.setState('in-call');
                this.attachRemoteAudio(session);
            }

            if (state === SessionState.Terminated) {
                const wasOutboundRinging =
                    this.currentCallDirection === 'outbound' &&
                    (this.state === 'dialing' || this.awaitingDestinationBridge);

                this.stopRingback();

                if (wasOutboundRinging && !this.directDialActive) {
                    const peer = this.currentCallPeer || '';
                    const digits = peer.replace(/\D/g, '');
                    if (digits.length >= 10) {
                        showToast(
                            `Your browser line disconnected before ${peer} answered. Stay on Connect line and try again.`,
                            'warning',
                        );
                    } else {
                        showToast('Call ended before the destination answered.', 'warning');
                    }
                } else if (wasOutboundRinging && this.directDialActive) {
                    showToast(
                        'Call ended before the destination answered. Check the number and Morpheus outbound routing.',
                        'warning',
                    );
                }

                this.clearSession();
            }
        });
    }

    attachRemoteAudio(session) {
        const remoteAudio = this.ui?.remoteAudio;
        const pc = session.sessionDescriptionHandler?.peerConnection;
        if (!remoteAudio || !pc) {
            return;
        }

        const maybeMarkConnected = () => {
            if (
                this.awaitingDestinationBridge &&
                this.currentCallDirection === 'outbound' &&
                session.state === SessionState.Established
            ) {
                window.setTimeout(() => {
                    if (this.awaitingDestinationBridge && session.state === SessionState.Established) {
                        this.markDestinationConnected();
                    }
                }, 1200);
            }
        };

        pc.ontrack = (event) => {
            remoteAudio.srcObject = event.streams[0];
            remoteAudio.play().catch(() => {});
            maybeMarkConnected();
        };

        const stream = new MediaStream();
        pc.getReceivers().forEach((receiver) => {
            if (receiver.track) {
                stream.addTrack(receiver.track);
            }
        });
        if (stream.getTracks().length > 0) {
            remoteAudio.srcObject = stream;
            remoteAudio.play().catch(() => {});
            maybeMarkConnected();
        }
    }

    async answer() {
        if (!this.session) {
            return;
        }

        const state = this.session.state;
        if (
            state === SessionState.Established ||
            state === SessionState.Terminating ||
            state === SessionState.Terminated
        ) {
            return;
        }

        this.stopRingtone();

        if (this.currentCallDirection === 'outbound') {
            this.startRingback();
        }

        await this.session.accept({
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: false },
            },
            sessionDescriptionHandlerModifiers: [
                (description) => {
                    description.sdp = description.sdp.replace(/^(m=video )\d+(.*)$/gm, '$10$2');
                    return Promise.resolve(description);
                },
            ],
        });
    }

    async hangup() {
        const morpheusUuid = this.morpheusCallUuid;

        if (this.session) {
            const state = this.session.state;
            if (state === SessionState.Initial || state === SessionState.Establishing) {
                if (this.session instanceof Inviter) {
                    await this.session.cancel();
                } else {
                    await this.session.reject();
                }
            } else {
                await this.session.bye();
            }
        }

        if (morpheusUuid) {
            await this.hangupMorpheusCall(morpheusUuid);
        }

        this.morpheusCallUuid = null;
        this.pendingClickToCall = false;
        this.clearSession();
    }

    clearSession() {
        this.session = null;
        this.stopRingtone();
        this.stopRingback();
        this.stopDestinationPoll();
        hideLoadingOverlay();
        this.stopCallTimer();
        this.recordingActive = false;
        this.updateRecordingUi();
        this.setCallContext(null, '');
        this.morpheusCallUuid = null;
        this.pendingClickToCall = false;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this.directDialActive = false;
        this.callOnHold = false;
        this.updateHoldUi();

        this.updateCallCard({ visible: false });
        this.updateFloatingPopup({ visible: false });

        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
        }

        if (this.ui?.bridgePanel && !this.ui.bridgePanel.classList.contains('hidden')) {
            this.setState('registered', 'Embedded phone');

            return;
        }

        if (this.registerer?.state === RegistererState.Registered) {
            this.setState('registered');
        } else {
            this.setState('offline');
        }
    }

    async disconnect(resetUi = true) {
        this.session = null;
        this.stopRingtone();
        this.stopRingback();
        this.stopCallTimer();
        this.setCallContext(null, '');

        if (this.registerer) {
            try {
                await this.registerer.unregister();
            } catch {
                // ignore
            }
            this.registerer = null;
        }

        if (this.userAgent) {
            try {
                await this.userAgent.stop();
            } catch {
                // ignore
            }
            this.userAgent = null;
        }

        if (resetUi) {
            this.setState('offline');
        }
    }

    async restoreConnection() {
        if (
            this.connectPromise ||
            this.canDirectDial() ||
            this.state === 'connecting' ||
            (!window.isSecureContext && window.location.protocol === 'http:')
        ) {
            return;
        }

        const extension = selectedExtension();
        if (!extension) {
            return;
        }

        try {
            await this.connect(extension);
        } catch {
            await this.disconnect(false).catch(() => {});
            this.lastError = '';
            this.setState('offline');
        }
    }

    isReady() {
        if (this.isBridgeMode()) {
            return true;
        }

        return (
            this.state === 'registered' ||
            this.state === 'dialing' ||
            this.state === 'in-call' ||
            this.state === 'ringing'
        );
    }
}

export function getWebphone() {
    if (!singleton) {
        singleton = new ApexWebphone();
    }

    return singleton;
}

export async function ensureWebphoneReady(options = {}) {
    const { silent = false } = options;
    const phone = getWebphone();
    const extension = selectedExtension();

    if (!extension) {
        if (!silent) {
            showToast('Select your extension before calling.', 'error');
        }

        return false;
    }

    const normalized = String(extension).replace(/\D/g, '') || String(extension);
    const onMatchingLine =
        phone.canDirectDial() && String(phone.currentExtension || '').replace(/\D/g, '') === normalized;

    if (onMatchingLine) {
        return true;
    }

    try {
        await phone.connect(normalized);

        return phone.canDirectDial();
    } catch (error) {
        if (!silent) {
            phone.handleConnectFailure(error);
        } else {
            phone.lastError = error?.message || 'Could not connect phone.';
            phone.setState('offline');
        }

        return false;
    }
}

export function markDialerClickToCallPending() {
    getWebphone().markClickToCallPending();
}

export function cancelPendingWebphoneConnect() {
    getWebphone().cancelPendingConnect();
}

export function canWebphoneDirectDial() {
    return getWebphone().canDirectDial();
}

export async function placeWebphoneCall(destination) {
    return getWebphone().dial(destination);
}

export function bootCommunicationsWebphone() {
    const panel = document.querySelector('[data-webphone-panel]');
    if (!panel) {
        return;
    }

    const phone = getWebphone();
    phone.bindPanel(panel);

    if (!window.isSecureContext && window.location.protocol === 'http:') {
        phone.ui.hint.textContent =
            'Apex is on HTTP — use the embedded Morpheus phone (HTTPS) for audio.';
        phone.ui.bridgeBtn?.classList.remove('hidden');
        return;
    }

    phone.restoreConnection().catch(() => {});
}
