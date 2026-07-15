import { Inviter, Registerer, RegistererState, SessionState, UserAgent } from 'sip.js';
import { hideLoadingOverlay } from './form-loading.js';
import { showToast, showCommToast, usesCallSummaryFlow } from './toast.js';

const STORAGE_KEY = 'communications.webphone_extension';
/** Auto-end unanswered outbound rings and open Call Summary after 1 min 10 sec. */
const RING_NO_ANSWER_TIMEOUT_MS = 70_000;

/**
 * Speaker playback volume while talking.
 * Moderate level helps AEC without starving far-end clarity.
 */
const REMOTE_PLAYBACK_VOLUME = 0.62;

/**
 * Soft silence gate for outbound mic (OPTIONAL).
 * Disabled by default: toggling track.enabled mid-call chops speech and feels like
 * a "breaking" call even while WebRTC stays connected.
 */
const SPEECH_GATE = {
    enabled: false,
    /** RMS above this = talking (0–1 scale from analyser). */
    openRms: 0.035,
    /** RMS below this = silence (hysteresis). */
    closeRms: 0.018,
    /** Must stay silent this long before gating outbound mic. */
    silenceMs: 1800,
    /** Keep mic open this long after last speech. */
    hangoverMs: 700,
    pollMs: 100,
};

/** Wait before treating WebRTC "disconnected" as a real call end. */
const WEBRTC_DISCONNECT_GRACE_MS = 4500;

function supportedAudioConstraints() {
    try {
        return navigator.mediaDevices?.getSupportedConstraints?.() || {};
    } catch {
        return {};
    }
}

/**
 * Browser captures the mic. PHP never processes PCM — it only originates via Morpheus.
 * Request only constraints this browser/device actually supports.
 */
function buildWebphoneAudioConstraints() {
    const supported = supportedAudioConstraints();
    const audio = {
        channelCount: 1,
    };

    if (supported.echoCancellation !== false) {
        audio.echoCancellation = true;
    }
    if (supported.noiseSuppression !== false) {
        audio.noiseSuppression = true;
    }
    // Soft AGC keeps voice consistent without mid-call track rebuilds.
    if (supported.autoGainControl !== false) {
        audio.autoGainControl = true;
    }

    // Chrome/Chromium extras (ignored safely elsewhere).
    audio.googEchoCancellation = true;
    audio.googEchoCancellation2 = true;
    audio.googNoiseSuppression = true;
    audio.googNoiseSuppression2 = true;
    audio.googAutoGainControl = true;
    audio.googHighpassFilter = true;
    audio.googTypingNoiseDetection = true;
    audio.googAudioMirroring = false;

    return audio;
}

function buildWebphoneMediaConstraints() {
    return {
        audio: buildWebphoneAudioConstraints(),
        video: false,
    };
}

function buildSoftAudioConstraints() {
    return {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
    };
}

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

function humanCallEndMessage(cause, { outbound = false } = {}) {
    const normalized = String(cause || '')
        .replace(/_/g, ' ')
        .trim()
        .toUpperCase();

    const map = {
        NO_ANSWER: 'No one answered the call.',
        USER_BUSY: 'The line is busy right now.',
        CALL_REJECTED: 'The call was declined.',
        NO_USER_RESPONSE: 'No response from the destination.',
        UNALLOCATED_NUMBER: 'That number could not be reached.',
        NO_ROUTE_DESTINATION: 'That number could not be routed.',
        ORIGINATOR_CANCEL: outbound ? 'Call canceled before connecting.' : 'The caller hung up.',
        NORMAL_CLEARING: 'Call ended.',
    };

    return map[normalized] || (normalized ? `Call ended (${normalized.toLowerCase()}).` : 'Call ended.');
}

function isPhoneDialerMode() {
    return Boolean(document.querySelector('.ghl-comm-phone-mode'));
}

function selectedExtension() {
    const select = document.querySelector('[name="from_extension"]');
    if (select?.value) {
        return select.value;
    }

    const panel = document.querySelector('[data-webphone-panel]');

    return panel?.dataset.defaultExtension || localStorage.getItem(STORAGE_KEY) || '';
}

function ensureDefaultExtensionSelected() {
    const select = document.querySelector('[name="from_extension"]');
    if (!select || select.value) {
        return select?.value || selectedExtension();
    }

    const first = Array.from(select.options).find((option) => option.value && !option.disabled);
    if (first) {
        select.value = first.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    return select.value || selectedExtension();
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
        this.timerPhase = null;
        this.connectAttempt = 0;
        this.cancelledConnectAttempt = 0;
        this.audioContext = null;
        this.ringtoneInterval = null;
        this.ringbackInterval = null;
        this._activeRingNodes = [];
        this._ringtoneStopped = true;
        this._ringbackStopped = true;
        this.outboundWaitingActive = false;
        this.currentCallDirection = null;
        this.currentCallPeer = '';
        this.morpheusCallUuid = null;
        this.originateCallUuid = null;
        this.pstnPollUuid = null;
        this.recordingActive = false;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this.destinationPollTimer = null;
        this._callMonitorActive = false;
        this.remoteHangupHandled = false;
        this.callEventsSource = null;
        this.directDialActive = false;
        this.sawOutboundRinging = false;
        this.outboundTerminateDetail = '';
        this.suppressTerminateToast = false;
        this.outboundDialInProgress = false;
        this.customerFirstOutbound = false;
        this.outboundDialStartedAt = 0;
        this.agentLegEstablishedAt = 0;
        this.callOnHold = false;
        this.micMuted = false;
        this._lastPollSnapshot = '';
        this._lastSeenPstnBillsec = 0;
        this._remoteAnswerWatcher = null;
        this._reportedDestinationConnected = null;
        this._callEndedDispatched = false;
        this._pendingCallEndMeta = null;
        this._ringNoAnswerTimeoutFired = false;
        this._finalizeAfterByeInFlight = false;
        this._antiEchoTimer = null;
        this._localMicStream = null;
        this._speechGateTimer = null;
        this._speechGateAnalyser = null;
        this._speechGateSource = null;
        this._speechGateContext = null;
        this._speechGateSpeaking = true;
        this._speechGateSilentSince = 0;
        this._speechGateLastVoiceAt = 0;
        this._webrtcRecoverTimer = null;
        this._webrtcIceRestarted = false;
        this._rtpGrowthFrames = 0;
        this._liveTrackWatcher = null;
        this._voiceEnergyWatcher = null;
        this._remoteAnswerAnalyser = null;
        this._remoteAnswerSource = null;
        this._voiceEnergyFrames = 0;
        this.trackedCallUuids = new Set();
        this.bridgedCallUuid = null;
        this.hangupInFlight = false;
        this.userInitiatedHangup = false;
        this.lastDialedDestination = '';
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
            disconnectBtns: panel.querySelectorAll('[data-webphone-disconnect]'),
            answerBtn: panel.querySelector('[data-webphone-answer]'),
            answerBtns: panel.querySelectorAll('[data-webphone-answer]'),
            hangupBtn: panel.querySelector('[data-webphone-hangup]'),
            hangupBtns: panel.querySelectorAll('[data-webphone-hangup]'),
            bridgeBtn: panel.querySelector('[data-webphone-bridge]'),
            bridgeBtns: panel.querySelectorAll('[data-webphone-bridge]'),
            bridgePanel: panel.querySelector('[data-webphone-bridge-panel]'),
            bridgeExtension: panel.querySelector('[data-webphone-bridge-extension]'),
            iframe: panel.querySelector('[data-webphone-iframe]'),
            remoteAudio: panel.querySelector('[data-webphone-remote]'),
            callControls: panel.querySelector('[data-webphone-call-controls]'),
            recordBtn: panel.querySelector('[data-webphone-record]'),
            recordLabel: panel.querySelector('[data-webphone-record-label]'),
            endCallBtn: panel.querySelector('[data-webphone-end-call]'),
            holdBtn: panel.querySelector('[data-webphone-hold]'),
            muteBtn: panel.querySelector('[data-webphone-mute]'),
            transferBtn: panel.querySelector('[data-webphone-transfer]'),
        };

        this.initDialerCallActionDelegation();

        this.ui.connectBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.connect(selectedExtension()).catch((error) => {
                this.handleConnectFailure(error);
            });
        });

        this.ui.disconnectBtns?.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (btn.disabled) {
                    return;
                }
                btn.closest('details')?.removeAttribute('open');
                this.disconnect().catch(() => {});
            });
        });

        this.ui.answerBtns?.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.ensureAudioContext().catch(() => {});
                this.answer().catch((error) => {
                    showToast(error.message || 'Could not answer call.', 'error');
                });
            });
        });

        this.ui.hangupBtns?.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.ensureAudioContext().catch(() => {});
                this.hangup('panel-hangup-btn').catch(() => {});
            });
        });

        this.ui.bridgeBtns?.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.openBridge();
            });
        });

        this.ui.recordBtn?.addEventListener('click', () => this.toggleRecording());
        this.ui.endCallBtn?.addEventListener('click', () => {
            this.ensureAudioContext().catch(() => {});
            this.hangup('end-call-btn').catch(() => {});
        });

        this.ui.holdBtn?.addEventListener('click', () => {
            this.toggleHold().catch((error) => {
                showToast(error.message || 'Could not update hold.', 'error');
            });
        });

        this.ui.muteBtn?.addEventListener('click', () => {
            this.toggleMute().catch((error) => {
                showToast(error.message || 'Could not mute microphone.', 'error');
            });
        });

        this.ui.transferBtn?.addEventListener('click', () => {
            this.promptTransfer().catch((error) => {
                showToast(error.message || 'Could not transfer call.', 'error');
            });
        });

        this.bindActiveCallKeypadAndTransfer();

        const extSelect = document.querySelector('[name="from_extension"]');
        extSelect?.addEventListener('change', () => this.syncSelectedExtension());

        this.syncSelectedExtension();
        this.setState('offline');
        this.primeAudio();
    }

    bindActiveCallKeypadAndTransfer() {
        // Always keep delegation live (Turbo navigations / hot reloads).
        if (document.documentElement.dataset.commActiveCallUiBound === '1') {
            return;
        }
        document.documentElement.dataset.commActiveCallUiBound = '1';
        this._dtmfBuffer = '';

        document.addEventListener('click', (event) => {
            const keypadToggle = event.target.closest('[data-dialer-active-keypad-toggle]');
            if (keypadToggle) {
                event.preventDefault();
                event.stopPropagation();
                this.toggleActiveKeypad();
                return;
            }

            if (event.target.closest('[data-dialer-active-keypad-hide]')) {
                event.preventDefault();
                event.stopPropagation();
                this.setActiveKeypadOpen(false);
                return;
            }

            if (event.target.closest('[data-dialer-active-keypad-delete]')) {
                event.preventDefault();
                event.stopPropagation();
                this.deleteActiveKeypadDigit();
                return;
            }

            if (event.target.closest('[data-dialer-active-keypad-clear]')) {
                event.preventDefault();
                event.stopPropagation();
                this.clearActiveKeypadDigits();
                return;
            }

            if (event.target.closest('[data-dialer-answering-machine]')) {
                event.preventDefault();
                event.stopPropagation();
                this.markAnsweringMachineAndHangup().catch((error) => {
                    showCommToast(error?.message || 'Could not mark answering machine.', 'error');
                });
                return;
            }

            const toneBtn = event.target.closest('[data-dialer-active-dtmf]');
            if (toneBtn) {
                event.preventDefault();
                event.stopPropagation();
                const tone = toneBtn.getAttribute('data-dialer-active-dtmf') || '';
                this.sendDtmfTone(tone);
                return;
            }

            if (event.target.closest('[data-dialer-transfer-close]')) {
                event.preventDefault();
                this.closeTransferModal();
                return;
            }

            if (event.target.closest('[data-dialer-transfer-confirm]')) {
                event.preventDefault();
                this.confirmTransferFromModal().catch((error) => {
                    showToast(error.message || 'Could not transfer call.', 'error');
                });
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                this.closeTransferModal();
                this.setActiveKeypadOpen(false);
                return;
            }

            if (event.key === 'Backspace') {
                const keypad = document.querySelector('[data-dialer-active-keypad]:not(.hidden)');
                if (keypad && !event.target?.matches?.('input, textarea, [contenteditable="true"]')) {
                    event.preventDefault();
                    this.deleteActiveKeypadDigit();
                    return;
                }
            }

            if (event.key === 'Enter' && event.target?.matches?.('[data-dialer-transfer-input]')) {
                event.preventDefault();
                this.confirmTransferFromModal().catch((error) => {
                    showToast(error.message || 'Could not transfer call.', 'error');
                });
            }
        });
    }

    syncActiveKeypadDigits() {
        const value = String(this._dtmfBuffer || '');
        document.querySelectorAll('[data-dialer-active-keypad-digits]').forEach((el) => {
            el.textContent = value;
        });
    }

    deleteActiveKeypadDigit() {
        this._dtmfBuffer = String(this._dtmfBuffer || '').slice(0, -1);
        this.syncActiveKeypadDigits();
    }

    clearActiveKeypadDigits() {
        this._dtmfBuffer = '';
        this.syncActiveKeypadDigits();
    }

    toggleActiveKeypad(force = null) {
        const panel = document.querySelector('[data-dialer-active-keypad]');
        if (!panel) {
            return;
        }
        const open = force === null ? panel.classList.contains('hidden') : Boolean(force);
        this.setActiveKeypadOpen(open);
    }

    setActiveKeypadOpen(open) {
        document.querySelectorAll('[data-dialer-active-keypad]').forEach((panel) => {
            panel.classList.toggle('hidden', !open);
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        });
        document.querySelectorAll('[data-dialer-active-keypad-toggle]').forEach((btn) => {
            btn.classList.toggle('is-active', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (!open) {
            this._dtmfBuffer = '';
            this.syncActiveKeypadDigits();
        }
        if (open) {
            document.querySelectorAll('[data-dialer-active-notes]').forEach((notes) => {
                notes.classList.add('hidden');
            });
            this.syncActiveKeypadDigits();
        }
    }

    sendDtmfTone(tone) {
        const digit = String(tone || '');
        if (!/^[0-9*#]$/.test(digit)) {
            return;
        }

        this._dtmfBuffer = `${String(this._dtmfBuffer || '')}${digit}`.slice(-24);
        this.syncActiveKeypadDigits();

        const session = this.session;
        const onCall = ['dialing', 'ringing', 'in-call'].includes(this.state);
        if (!session || !onCall) {
            showCommToast('Join or place a call before using the keypad.', 'warning');
            return;
        }

        // Allow tones once the SIP session exists (including early media / ringing).
        const canSend = session.state === SessionState.Established
            || session.state === SessionState.Establishing
            || this.timerPhase === 'connected'
            || this.timerPhase === 'ringing';

        if (!canSend) {
            showCommToast('Connect the call before using the keypad.', 'warning');
            return;
        }

        try {
            const sdh = session.sessionDescriptionHandler;
            if (sdh && typeof sdh.sendDtmf === 'function') {
                const sent = sdh.sendDtmf(digit);
                if (sent === false) {
                    throw new Error('DTMF send failed');
                }
                return;
            }

            if (typeof session.info === 'function') {
                session.info({
                    requestOptions: {
                        body: {
                            contentDisposition: 'render',
                            contentType: 'application/dtmf-relay',
                            content: `Signal=${digit}\r\nDuration=160`,
                        },
                    },
                }).catch(() => {});
                return;
            }

            showCommToast('Keypad tones are not available on this line.', 'warning');
        } catch (error) {
            showCommToast(error?.message || 'Could not send keypad tone.', 'error');
        }
    }

    async markAnsweringMachineAndHangup() {
        const onCall = ['dialing', 'ringing', 'in-call'].includes(this.state);
        if (!onCall) {
            showCommToast('No active call to mark as answering machine.', 'warning');
            return;
        }

        window.__apexForceDisposition = 'Answer Machine';
        showCommToast('Marked answer machine — ending call…', 'info');
        await this.hangup('answering-machine');
    }

    openTransferModal() {
        const modal = document.querySelector('[data-dialer-transfer-modal]');
        const input = document.querySelector('[data-dialer-transfer-input]');
        if (!modal || !input) {
            return false;
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        input.value = '';
        window.setTimeout(() => input.focus(), 0);
        return true;
    }

    closeTransferModal() {
        document.querySelectorAll('[data-dialer-transfer-modal]').forEach((modal) => {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
        });
    }

    async confirmTransferFromModal() {
        const input = document.querySelector('[data-dialer-transfer-input]');
        const destination = String(input?.value || '').trim();
        if (!destination) {
            showToast('Enter an extension or phone number.', 'warning');
            input?.focus();
            return;
        }

        await this.executeTransfer(destination);
        this.closeTransferModal();
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
        this.stopRingtone();
        this.stopRingback();

        const playBurst = async () => {
            const context = await this.ensureAudioContext();
            if (!context || this._ringtoneStopped) {
                return;
            }

            const gain = context.createGain();
            gain.gain.setValueAtTime(0.0001, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.42, context.currentTime + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.95);
            gain.connect(context.destination);
            this._activeRingNodes.push(gain);

            [880, 988, 1175].forEach((frequency, index) => {
                const oscillator = context.createOscillator();
                oscillator.type = 'triangle';
                oscillator.frequency.setValueAtTime(frequency, context.currentTime + index * 0.18);
                oscillator.connect(gain);
                oscillator.start(context.currentTime + index * 0.18);
                oscillator.stop(context.currentTime + 0.95);
                this._activeRingNodes.push(oscillator);
            });
        };

        this._ringtoneStopped = false;
        playBurst().catch(() => {});
        this.ringtoneInterval = window.setInterval(() => {
            playBurst().catch(() => {});
        }, 1400);
    }

    stopRingtone() {
        this._ringtoneStopped = true;

        if (this.ringtoneInterval) {
            window.clearInterval(this.ringtoneInterval);
            this.ringtoneInterval = null;
        }

        this.stopActiveRingNodes();
    }

    startRingback() {
        this.stopRingtone();
        this.stopRingback();

        this.outboundWaitingActive = true;
        this._ringbackStopped = false;

        const playOutboundRing = async () => {
            if (!this.outboundWaitingActive || this._ringbackStopped) {
                return;
            }

            const context = await this.ensureAudioContext();
            if (!context || this._ringbackStopped) {
                return;
            }

            const start = context.currentTime;
            const master = context.createGain();
            master.gain.setValueAtTime(0.0001, start);
            master.gain.exponentialRampToValueAtTime(0.18, start + 0.02);
            master.gain.setValueAtTime(0.18, start + 1.95);
            master.gain.exponentialRampToValueAtTime(0.0001, start + 2.05);
            master.connect(context.destination);
            this._activeRingNodes.push(master);

            // Classic phone ringback: 440 Hz + 480 Hz, 2s on / 4s off.
            [440, 480].forEach((frequency) => {
                const osc = context.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(frequency, start);
                osc.connect(master);
                osc.start(start);
                osc.stop(start + 2.05);
                this._activeRingNodes.push(osc);
            });
        };

        playOutboundRing().catch(() => {});
        this.ringbackInterval = window.setInterval(() => {
            playOutboundRing().catch(() => {});
        }, 6000);
    }

    stopRingback() {
        this.outboundWaitingActive = false;
        this._ringbackStopped = true;

        if (this.ringbackInterval) {
            window.clearInterval(this.ringbackInterval);
            this.ringbackInterval = null;
        }

        this.stopActiveRingNodes();
    }

    stopActiveRingNodes() {
        const nodes = this._activeRingNodes || [];
        this._activeRingNodes = [];
        nodes.forEach((node) => {
            try {
                if (typeof node.stop === 'function') {
                    node.stop();
                }
            } catch {
                // already stopped
            }
            try {
                node.disconnect?.();
            } catch {
                // already disconnected
            }
        });
    }

    stopAllLocalRingers() {
        this.stopRingtone();
        this.stopRingback();
    }

    setRemoteAudioMuted(muted) {
        const remoteAudio = this.ui?.remoteAudio;
        if (!remoteAudio) {
            return;
        }

        remoteAudio.muted = Boolean(muted);
        remoteAudio.volume = REMOTE_PLAYBACK_VOLUME;
        try {
            remoteAudio.setAttribute('playsinline', 'true');
            remoteAudio.setAttribute('webkit-playsinline', 'true');
        } catch {
            // ignore
        }
    }

    stopAntiEchoKeepalive() {
        if (this._antiEchoTimer) {
            window.clearInterval(this._antiEchoTimer);
            this._antiEchoTimer = null;
        }
    }

    startAntiEchoKeepalive() {
        this.stopAntiEchoKeepalive();
        // Soft keepalive only — never re-apply mic constraints (causes voice drops).
        this._antiEchoTimer = window.setInterval(() => {
            if (this.state !== 'in-call' || this.hangupInFlight) {
                this.stopAntiEchoKeepalive();
                return;
            }
            const remoteAudio = this.ui?.remoteAudio;
            if (remoteAudio && !remoteAudio.muted) {
                remoteAudio.volume = REMOTE_PLAYBACK_VOLUME;
            }
            // If mute was stuck off-track, re-enable once without toggling repeatedly.
            if (!this.micMuted) {
                this.ensureOutboundMicLive({ soft: true });
            }
        }, 8000);
    }

    releaseLocalMicStream({ keepTrackIds = [] } = {}) {
        if (!this._localMicStream) {
            return;
        }

        const keep = new Set(keepTrackIds.map(String));
        this._localMicStream.getTracks().forEach((track) => {
            if (!keep.has(String(track.id))) {
                try {
                    track.stop();
                } catch {
                    // ignore
                }
            }
        });
        this._localMicStream = null;
    }

    /**
     * One-time soft noise/echo tune on the existing mic track.
     * Do NOT replaceTrack during talk — that causes audible voice drops.
     */
    async tuneLocalMicForSpeech() {
        const pc = this.session?.sessionDescriptionHandler?.peerConnection;
        if (!pc || typeof pc.getSenders !== 'function') {
            return false;
        }

        const preferred = buildWebphoneAudioConstraints();
        const soft = buildSoftAudioConstraints();

        const tasks = pc.getSenders().map(async (sender) => {
            const track = sender.track;
            if (!track || track.kind !== 'audio') {
                return;
            }

            try {
                track.contentHint = 'speech';
            } catch {
                // ignore
            }

            // Only enable if muted wrongly by AEC settle — never disable here.
            if (!this.micMuted && track.enabled === false) {
                track.enabled = true;
            }

            try {
                await track.applyConstraints(preferred);
            } catch {
                try {
                    await track.applyConstraints(soft);
                } catch {
                    // Device may reject extras; keep current track live.
                }
            }

            try {
                const settings = track.getSettings?.() || {};
                this.logPhone('info', 'Mic speech settings', {
                    label: track.label,
                    echoCancellation: settings.echoCancellation,
                    noiseSuppression: settings.noiseSuppression,
                    autoGainControl: settings.autoGainControl,
                    sampleRate: settings.sampleRate,
                    channelCount: settings.channelCount,
                });
            } catch {
                // ignore
            }
        });

        await Promise.allSettled(tasks);
        return true;
    }

    stopOutboundSpeechGate() {
        if (this._speechGateTimer) {
            window.clearInterval(this._speechGateTimer);
            this._speechGateTimer = null;
        }

        try {
            this._speechGateSource?.disconnect?.();
        } catch {
            // ignore
        }
        this._speechGateSource = null;
        this._speechGateAnalyser = null;

        if (this._speechGateContext && this._speechGateContext !== this.audioContext) {
            try {
                void this._speechGateContext.close?.();
            } catch {
                // ignore
            }
        }
        this._speechGateContext = null;
        this._speechGateSpeaking = true;
        this._speechGateSilentSince = 0;
        this._speechGateLastVoiceAt = 0;
    }

    /**
     * While connected: if the agent is silent long enough, pause outbound mic so
     * fans/keyboard/room chatter don't flood the destination. Speaking opens it
     * immediately; hangover avoids chopping word endings.
     */
    startOutboundSpeechGate() {
        this.stopOutboundSpeechGate();
        if (!SPEECH_GATE.enabled) {
            return;
        }

        const pc = this.session?.sessionDescriptionHandler?.peerConnection;
        const track = pc?.getSenders?.().find((sender) => sender.track?.kind === 'audio')?.track;
        if (!track || (!window.AudioContext && !window.webkitAudioContext)) {
            return;
        }

        const AudioCtor = window.AudioContext || window.webkitAudioContext;
        try {
            this._speechGateContext = new AudioCtor();
            const stream = new MediaStream([track]);
            this._speechGateSource = this._speechGateContext.createMediaStreamSource(stream);
            this._speechGateAnalyser = this._speechGateContext.createAnalyser();
            this._speechGateAnalyser.fftSize = 512;
            this._speechGateAnalyser.smoothingTimeConstant = 0.5;
            this._speechGateSource.connect(this._speechGateAnalyser);
        } catch (error) {
            this.logPhone('warn', 'Speech gate unavailable', {
                error: error instanceof Error ? error.message : String(error),
            });
            this.stopOutboundSpeechGate();
            return;
        }

        const data = new Uint8Array(this._speechGateAnalyser.fftSize);
        this._speechGateSpeaking = true;
        this._speechGateLastVoiceAt = Date.now();
        this._speechGateSilentSince = 0;

        this._speechGateTimer = window.setInterval(() => {
            if (this.state !== 'in-call' || this.hangupInFlight || this.micMuted) {
                return;
            }

            const liveTrack = pc?.getSenders?.().find((sender) => sender.track?.kind === 'audio')?.track;
            if (!liveTrack || !this._speechGateAnalyser) {
                return;
            }

            this._speechGateAnalyser.getByteTimeDomainData(data);
            let sum = 0;
            for (let i = 0; i < data.length; i += 1) {
                const centered = (data[i] - 128) / 128;
                sum += centered * centered;
            }
            const rms = Math.sqrt(sum / data.length);
            const now = Date.now();

            if (rms >= SPEECH_GATE.openRms) {
                this._speechGateLastVoiceAt = now;
                this._speechGateSilentSince = 0;
                if (!this._speechGateSpeaking) {
                    this._speechGateSpeaking = true;
                    liveTrack.enabled = true;
                }
                return;
            }

            if (rms > SPEECH_GATE.closeRms) {
                // Near-speech zone — keep open, don't gate.
                this._speechGateSilentSince = 0;
                return;
            }

            if (!this._speechGateSilentSince) {
                this._speechGateSilentSince = now;
            }

            const silentFor = now - this._speechGateSilentSince;
            const sinceVoice = now - this._speechGateLastVoiceAt;
            if (
                this._speechGateSpeaking
                && silentFor >= SPEECH_GATE.silenceMs
                && sinceVoice >= SPEECH_GATE.hangoverMs
            ) {
                this._speechGateSpeaking = false;
                // Gate only send path; user mute still wins via micMuted checks.
                liveTrack.enabled = false;
            }
        }, SPEECH_GATE.pollMs);
    }

    /**
     * Keep AEC on and avoid speaker↔mic feedback loops without chopping speech.
     */
    async reinforceAntiEcho({ retuneMic = false } = {}) {
        const remoteAudio = this.ui?.remoteAudio;
        if (remoteAudio) {
            remoteAudio.volume = REMOTE_PLAYBACK_VOLUME;
            // Never play remote early-media while still ringing.
            if (
                this.awaitingDestinationBridge
                || this.state === 'dialing'
                || this.state === 'ringing'
                || this.timerPhase !== 'connected'
            ) {
                remoteAudio.muted = true;
            }
        }

        // Local ring oscillators on AudioContext.destination also leak into open mics.
        if (this.state === 'in-call' || this.timerPhase === 'connected') {
            this.stopAllLocalRingers();
            this.outboundWaitingActive = false;
            try {
                if (this.audioContext && this.audioContext.state === 'running') {
                    void this.audioContext.suspend?.();
                }
            } catch {
                // ignore
            }
        }

        if (retuneMic) {
            await this.tuneLocalMicForSpeech();
        }
    }

    peerDigits(value) {
        return String(value || '').replace(/\D/g, '');
    }

    destinationMatchesPeer(data) {
        const peerDigits = this.peerDigits(this.currentCallPeer);
        const snapDigits = this.peerDigits(
            data?.destination_number || data?.phone_number || data?.to || '',
        );

        if (peerDigits.length < 10 || snapDigits.length < 10) {
            return false;
        }

        return snapDigits === peerDigits
            || snapDigits.endsWith(peerDigits)
            || peerDigits.endsWith(snapDigits);
    }

    applyRemoteCallStatus(data, { source = 'poll' } = {}) {
        if (!data || data.ok === false) {
            return false;
        }

        if (this.isRemoteCallEnded(data)) {
            void this.handleRemotePartyHangup(data, { source });

            return true;
        }

        if (data.bridged_to && data.bridged_to !== this.originateCallUuid) {
            this.bridgedCallUuid = String(data.bridged_to);
            this.trackedCallUuids.add(String(data.bridged_to));
        }

        const remoteState = String(data.state || '').toUpperCase();
        const ringingOnly =
            ['RING_WAIT', 'RINGING', 'EARLY'].includes(remoteState)
            && data.destination_connected !== true
            && data.outcome !== 'connected';

        if (ringingOnly && this.awaitingDestinationBridge && !this.timerPhase) {
            this.startRingTimer();
        }

        const billsec = Number(data.billsec ?? 0);
        const destMatches = this.destinationMatchesPeer(data);
        const answeredState = ['CONNECTED', 'ANSWERED', 'BRIDGED', 'ACTIVE'].includes(remoteState);
        const hasAnswerTime = Boolean(data.answer_time || data.answered_at || data.destination_answer_time);
        const fromActiveDestProbe =
            String(data.source || '') === 'active_calls_destination'
            || String(data.source || '') === 'active_calls';

        if (billsec > this._lastSeenPstnBillsec) {
            this._lastSeenPstnBillsec = billsec;
        }

        // Only promote on clear destination signals — never on agent-leg billsec alone.
        const destinationConnected =
            data.destination_connected === true
            || data.destination_answered === true
            || data.outcome === 'connected'
            || (fromActiveDestProbe && answeredState && data.live !== false && !data.hangup_cause)
            || (answeredState && destMatches && billsec >= 1 && data.live !== false && !data.hangup_cause)
            || (data.live && destMatches && hasAnswerTime && billsec >= 1 && !data.hangup_cause)
            || (
                answeredState
                && data.live !== false
                && !data.hangup_cause
                && Boolean(data.phone_number || data.destination_number)
                && (
                    destMatches
                    || this.peerDigits(data.phone_number || data.destination_number || '').length >= 10
                )
                && fromActiveDestProbe
            );

        if (destinationConnected) {
            this._serverConfirmedDestination = true;
            if (!this.currentCallPeer && (data.to || data.destination_number || data.phone_number)) {
                this.setCallContext(
                    this.currentCallDirection || 'outbound',
                    data.to || data.destination_number || data.phone_number,
                );
            }
            this.markDestinationConnected({ source });

            return true;
        }

        return false;
    }

    isRemoteCallEnded(data) {
        if (!data || data.ok === false) {
            return false;
        }

        const cause = data.hangup_cause ? String(data.hangup_cause) : '';
        const billsec = Number(data.billsec ?? 0);
        const elapsed = Date.now() - (this.outboundDialStartedAt || Date.now());
        const bridging = this.awaitingDestinationBridge || this.clickToCallActive;
        const connectedAlready =
            this._serverConfirmedDestination
            && (
                this.timerPhase === 'connected'
                || (this.state === 'in-call' && !this.awaitingDestinationBridge)
            );

        // After both sides connected — honor remote hangup immediately.
        if (connectedAlready) {
            if (data.call_ended === true || data.outcome === 'ended') {
                return true;
            }
            if (data.live === false || (cause && data.live === false)) {
                return true;
            }
            if (!data.live && cause) {
                return true;
            }

            return false;
        }

        // Still waiting for destination to pick up: keep ringing.
        // Agent-leg CDR/webhooks often report NORMAL_CLEARING / call_ended while
        // Morpheus is still dialing the PSTN B-leg — hanging up here kills the ring.
        // Also treat UI-only "connected" (webrtc false positive) as still bridging.
        const stillBridging =
            bridging
            || this.clickToCallActive
            || (
                this.currentCallDirection === 'outbound'
                && !this.directDialActive
                && !this._serverConfirmedDestination
                && ['dialing', 'ringing', 'in-call'].includes(this.state)
            );

        if (stillBridging) {
            const hardFail = ['UNALLOCATED_NUMBER', 'NO_ROUTE_DESTINATION', 'SUBSCRIBER_ABSENT'];
            const softFail = ['USER_BUSY', 'NO_ANSWER', 'NO_USER_RESPONSE', 'CALL_REJECTED'];

            if (hardFail.includes(cause) && elapsed >= 2500) {
                return true;
            }

            // Soft fails only after the destination had time to actually ring.
            if (softFail.includes(cause) && elapsed >= 12_000) {
                return true;
            }

            // Real PSTN talk time then hangup.
            if ((data.call_ended === true || data.live === false) && billsec >= 15) {
                return true;
            }

            // Absolute bridge timeout.
            if (elapsed >= 90_000 && (data.call_ended === true || data.live === false || cause)) {
                return true;
            }

            return false;
        }

        if (data.call_ended === true || data.outcome === 'ended') {
            return true;
        }

        if (!data.live && cause) {
            return true;
        }

        return false;
    }

    async handleRemotePartyHangup(data = {}, { source = 'poll' } = {}) {
        if (this.hangupInFlight || this.remoteHangupHandled) {
            return;
        }

        const hangupCause = data.hangup_cause ? String(data.hangup_cause) : '';
        const bridgingOutbound =
            !this.directDialActive
            && this.currentCallDirection === 'outbound'
            && (
                this.awaitingDestinationBridge
                || this.clickToCallActive
                || this.pendingClickToCall
            )
            && !this._serverConfirmedDestination
            && !this.userInitiatedHangup;

        // False "ended" while Morpheus is still connecting the agent / dialing PSTN.
        // Destroying the SIP session here is what stopped the destination from ringing.
        if (bridgingOutbound && source !== 'user' && !String(source).includes('hangup-btn') && source !== 'end-call-btn') {
            this.logPhone('info', 'Ignored remote hangup while destination bridge in progress', {
                source,
                hangup_cause: hangupCause || null,
                uuid: this.originateCallUuid,
                state: this.state,
            });

            return;
        }

        this.remoteHangupHandled = true;
        const wasOnCall = ['dialing', 'ringing', 'in-call'].includes(this.state);
        const endedPhone = this.currentCallPeer || this.lastDialedDestination || '';
        const endedUuid = this.hangupCallUuid();

        // Clear Call Monitoring immediately (server + local board), before Morpheus wait.
        this.notifyMonitoringHangup({ phone: endedPhone, callUuid: endedUuid || '' });

        // Instantly restore dial pad so the UI never stays stuck on the ringing card.
        this.flushCallUiInstant();
        this.restoreDialerAfterCall();
        const wasConnected = this.timerPhase === 'connected' || this.state === 'in-call' || this._serverConfirmedDestination;
        const durationSec = wasConnected && this.callStartedAt && this.timerPhase === 'connected'
            ? Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000))
            : 0;
        if (wasConnected && !window.__apexForceDisposition) {
            window.__apexForceDisposition = 'Owner Hang up';
        }
        this._pendingCallEndMeta = {
            connected: wasConnected,
            result: wasConnected ? 'Connected' : 'No answer',
            durationSec,
        };
        // Disposition popup must open immediately — do not wait for Morpheus hangup APIs.
        this.dispatchCallEndedOnce({ phone: endedPhone, callUuid: endedUuid || '' });

        this.logPhone('info', 'Remote party hung up', {
            source,
            hangup_cause: hangupCause || null,
            originateCallUuid: this.originateCallUuid,
            state: this.state,
            wasConnected,
        });

        this.stopDestinationPoll();
        this.stopRemoteAnswerWatcher();
        this.stopAllLocalRingers();

        const morpheusUuid = this.hangupCallUuid();
        const relatedUuids = [
            ...new Set(
                [
                    morpheusUuid,
                    this.bridgedCallUuid,
                    this.originateCallUuid,
                    this.morpheusCallUuid,
                    this.pstnPollUuid,
                    ...this.trackedCallUuids,
                ]
                    .filter(Boolean)
                    .map(String),
            ),
        ];
        await Promise.allSettled([
            this.endLocalSipSession(this.session),
            this.killDestinationLegsNow({
                uuids: relatedUuids,
                destination: endedPhone,
                extension: selectedExtension() || this.currentExtension || '',
                bridgedUuid: this.bridgedCallUuid || null,
            }),
        ]);

        try {
            // session already bye'd above — keep as no-op safety if still open
            if (this.session && this.session.state !== SessionState.Terminated) {
                await this.endLocalSipSession(this.session);
            }
        } catch (error) {
            this.logPhone('warn', 'Local SIP cleanup after remote hangup failed', {
                source,
                error: error instanceof Error ? error.message : String(error),
            });
        }

        if (wasOnCall && !usesCallSummaryFlow()) {
            showCommToast(
                wasConnected
                    ? (hangupCause ? humanCallEndMessage(hangupCause) : 'Other party hung up.')
                    : (hangupCause ? humanCallEndMessage(hangupCause, { outbound: true }) : 'Call ended.'),
                'info',
            );
        }

        this.clearSession();
        this.restoreDialerAfterCall();
    }

    restoreDialerAfterCall() {
        this.resetDialerCallUi();
        document.querySelectorAll('.ghl-dialer-call-icon-btn').forEach((btn) => {
            delete btn.dataset.webphoneDialState;
            btn.classList.remove('is-hangup-mode');
            btn.setAttribute('aria-label', 'Place call');
            btn.setAttribute('title', 'Call');
            btn.removeAttribute('disabled');
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
            btn.removeAttribute('aria-disabled');
        });
        document.querySelectorAll('[data-dialer-active-screen]').forEach((screen) => {
            screen.classList.add('hidden');
            screen.setAttribute('aria-hidden', 'true');
            screen.classList.remove('is-ringing', 'is-connected');
        });
        document.querySelectorAll('[data-dialer-phone-stage]').forEach((stage) => {
            stage.classList.remove('is-hidden-during-call');
        });
        document.querySelectorAll('[data-dialer-call-actions]').forEach((row) => {
            row.classList.add('hidden');
        });
    }

    stopRemoteAnswerWatcher() {
        if (this._remoteAnswerWatcher) {
            window.clearInterval(this._remoteAnswerWatcher);
            this._remoteAnswerWatcher = null;
        }

        this.stopLiveTrackWatcher();
        this.stopRemoteVoiceEnergyWatcher();

        if (this._remoteAnswerSource) {
            try {
                this._remoteAnswerSource.disconnect();
            } catch {
                // ignore
            }
            this._remoteAnswerSource = null;
        }

        this._remoteAnswerAnalyser = null;
        this._voiceEnergyFrames = 0;
    }

    startRemoteAnswerWatcher(session) {
        // After the agent leg is up: poll Morpheus aggressively + watch RTP growth
        // (ringback is bursty; sustained inbound packets after ~2.5s means answered).
        if (this._remoteAnswerWatcher) {
            return;
        }

        if (!session || !this.awaitingDestinationBridge) {
            return;
        }

        this.startLiveTrackWatcher(session);
        this._lastInboundAudioPackets = 0;
        this._rtpGrowthFrames = 0;
        const startedAt = Date.now();
        this._remoteAnswerWatcher = window.setInterval(() => {
            if (!this.awaitingDestinationBridge || this.hangupInFlight || this.remoteHangupHandled) {
                this.stopRemoteAnswerWatcher();

                return;
            }

            if (Date.now() - startedAt > 120_000) {
                this.stopRemoteAnswerWatcher();

                return;
            }

            // Keep LOCAL ringback only while PSTN is still ringing.
            // Unmuting remote early-media here made speakers play carrier ring into the
            // open mic → loud echo on the destination side.
            if (this.agentLegEstablishedAt > 0) {
                this.setRemoteAudioMuted(true);
                void this.reinforceAntiEcho();
            }

            // Poll status immediately so Connected flips the moment the destination answers.
            if (this.agentLegEstablishedAt > 0) {
                void this.pollDestinationOnce();
            }

            void this.detectAnswerFromWebRtcStats(session);
        }, 250);
    }

    startRemoteVoiceEnergyWatcher(session) {
        // Disabled: ringback early-media looked like talk audio and caused
        // premature Connected + Morpheus hangup (destination never rang).
        return;
    }

    stopRemoteVoiceEnergyWatcher() {
        if (this._voiceEnergyWatcher) {
            window.clearInterval(this._voiceEnergyWatcher);
            this._voiceEnergyWatcher = null;
        }
        this._remoteAnswerAnalyser = null;
        this._voiceEnergyFrames = 0;
    }

    async detectAnswerFromWebRtcStats(session) {
        if (!this.awaitingDestinationBridge || this._serverConfirmedDestination) {
            return;
        }

        // Allow RTP inference as soon as the agent leg is up (no artificial 2s wait).
        if (!this.agentLegEstablishedAt || Date.now() - this.agentLegEstablishedAt < 200) {
            return;
        }

        const pc = session?.sessionDescriptionHandler?.peerConnection;
        if (!pc || typeof pc.getStats !== 'function') {
            return;
        }

        try {
            const stats = await pc.getStats();
            let packets = 0;
            stats.forEach((report) => {
                if (report.type === 'inbound-rtp' && (report.kind === 'audio' || report.mediaType === 'audio')) {
                    packets += Number(report.packetsReceived || 0);
                }
            });

            if (packets <= 0) {
                this._rtpGrowthFrames = 0;
                this._lastInboundAudioPackets = packets;

                return;
            }

            if (packets > this._lastInboundAudioPackets) {
                this._rtpGrowthFrames = (this._rtpGrowthFrames || 0) + 1;
            } else {
                this._rtpGrowthFrames = 0;
            }

            this._lastInboundAudioPackets = packets;

            // ~0.5s of continuous inbound RTP growth after agent is up = destination talking.
            if ((this._rtpGrowthFrames || 0) >= 2) {
                this.logPhone('info', 'Destination answer inferred from sustained RTP', {
                    packets,
                    frames: this._rtpGrowthFrames,
                });
                this._serverConfirmedDestination = true;
                this.markDestinationConnected({ source: 'webrtc-rtp-confirmed' });
            }
        } catch {
            // Stats not available yet.
        }
    }

    startLiveTrackWatcher(session) {
        // Live tracks exist during early media / ringing — never treat that as
        // both-sides-connected. Keep a lightweight watcher only for cleanup.
        if (!this.awaitingDestinationBridge || this._liveTrackWatcher) {
            return;
        }

        const startedAt = Date.now();
        this._liveTrackWatcher = window.setInterval(() => {
            if (!this.awaitingDestinationBridge || Date.now() - startedAt > 180_000) {
                this.stopLiveTrackWatcher();
            }
        }, 2000);
    }

    stopLiveTrackWatcher() {
        if (this._liveTrackWatcher) {
            window.clearInterval(this._liveTrackWatcher);
            this._liveTrackWatcher = null;
        }
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

        const label = active ? 'Recording' : 'Record';
        if (this.ui?.recordLabel) {
            this.ui.recordLabel.textContent = label;
        }
        document.querySelectorAll('[data-dialer-call-record]').forEach((btn) => {
            btn.classList.toggle('is-recording', active);
        });
        document.querySelectorAll('[data-dialer-call-record-label]').forEach((el) => {
            el.textContent = label;
        });
    }

    setDialerCallControlsVisible(visible, { showRecord = false, showEndCall = false, showAnswer = false, showHold = false, showMute = false, showTransfer = false } = {}) {
        document.querySelectorAll('[data-dialer-call-actions]').forEach((row) => {
            row.classList.toggle('hidden', !visible);
        });
        document.querySelectorAll('[data-dialer-call-answer]').forEach((btn) => {
            btn.classList.toggle('hidden', !showAnswer);
        });
        document.querySelectorAll('[data-dialer-call-hangup]').forEach((btn) => {
            btn.classList.toggle('hidden', !showEndCall);
        });
        document.querySelectorAll('[data-dialer-call-record]').forEach((btn) => {
            btn.classList.toggle('hidden', !showRecord);
        });
        document.querySelectorAll('[data-dialer-call-hold]').forEach((btn) => {
            btn.classList.toggle('hidden', !showHold);
        });
        document.querySelectorAll('[data-dialer-call-mute]').forEach((btn) => {
            btn.classList.toggle('hidden', !showMute);
        });
        document.querySelectorAll('[data-dialer-call-transfer]').forEach((btn) => {
            btn.classList.toggle('hidden', !showTransfer);
        });
    }

    initDialerCallActionDelegation() {
        if (document.documentElement.dataset.dialerCallActionsBound === '1') {
            return;
        }

        document.documentElement.dataset.dialerCallActionsBound = '1';

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-dialer-call-answer]')) {
                event.preventDefault();
                this.ensureAudioContext().catch(() => {});
                this.answer().catch((error) => {
                    showToast(error.message || 'Could not answer call.', 'error');
                });

                return;
            }

            if (event.target.closest('[data-dialer-call-hangup]')) {
                event.preventDefault();
                this.ensureAudioContext().catch(() => {});
                this.hangup('dialer-hangup-btn').catch(() => {});

                return;
            }

            if (event.target.closest('[data-dialer-call-record]')) {
                event.preventDefault();
                this.toggleRecording();

                return;
            }

            if (event.target.closest('[data-dialer-call-hold]')) {
                event.preventDefault();
                this.toggleHold().catch((error) => {
                    showToast(error.message || 'Could not update hold.', 'error');
                });

                return;
            }

            if (event.target.closest('[data-dialer-call-mute]')) {
                event.preventDefault();
                this.toggleMute().catch((error) => {
                    showToast(error.message || 'Could not mute microphone.', 'error');
                });

                return;
            }

            if (event.target.closest('[data-dialer-call-transfer]')) {
                event.preventDefault();
                this.promptTransfer().catch((error) => {
                    showToast(error.message || 'Could not transfer call.', 'error');
                });
            }
        });
    }

    setCallControlsVisible(visible, { showRecord = false, showEndCall = false, showAnswer = false, showHold = false, showMute = false, showTransfer = false } = {}) {
        this.ui?.callControls?.classList.toggle('hidden', !visible);
        this.ui?.recordBtn?.classList.toggle('hidden', !showRecord);
        this.ui?.endCallBtn?.classList.toggle('hidden', !showEndCall);
        this.ui?.holdBtn?.classList.toggle('hidden', !showHold);
        this.ui?.muteBtn?.classList.toggle('hidden', !showMute);
        this.ui?.transferBtn?.classList.toggle('hidden', !showTransfer);
        this.setDialerCallControlsVisible(visible, { showRecord, showEndCall, showAnswer, showHold, showMute, showTransfer });
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
        const id = uuid ? String(uuid) : null;
        this.morpheusCallUuid = id;
        if (id) {
            this.originateCallUuid = id;
            this.pstnPollUuid = id;
            this.trackCallUuid(id);
            if (
                !this._callMonitorActive
                && (this.awaitingDestinationBridge || this.state === 'dialing' || this.state === 'in-call')
            ) {
                this.startDestinationPoll();
            }
        }
    }

    trackCallUuid(uuid) {
        if (uuid) {
            this.trackedCallUuids.add(String(uuid));
        }
    }

    logPhone(level, message, context = {}) {
        if (!this.config?.wss_console_debug && level === 'info') {
            return;
        }

        const payload = { message, ...context };
        if (level === 'warn') {
            console.warn('[webphone]', payload);
        } else if (level === 'error') {
            console.error('[webphone]', payload);
        } else {
            console.info('[webphone]', payload);
        }
    }

    async showPstnLegFailedToast(uuid, peer) {
        if (usesCallSummaryFlow()) {
            return;
        }

        const template = this.callStatusUrlTemplate();
        let hangupHint = '';

        if (uuid && template) {
            try {
                const url = new URL(template.replace('__UUID__', encodeURIComponent(uuid)), window.location.origin);
                if (peer) {
                    url.searchParams.set('destination', peer);
                }
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.ok !== false) {
                    const billsec = Number(data.billsec ?? 0);
                    const cause = String(data.hangup_cause || '');
                    if (billsec > 0 && billsec < 15) {
                        hangupHint = ` (PSTN leg ${billsec}s`;
                        if (cause) {
                            hangupHint += `, ${cause.replace(/_/g, ' ').toLowerCase()}`;
                        }
                        hangupHint += ')';
                    } else if (cause) {
                        hangupHint = ` (${cause.replace(/_/g, ' ').toLowerCase()})`;
                    }
                }
            } catch {
                // Toast still shown without CDR detail.
            }
        }

        const target = peer || 'the destination';
        showToast(
            `Morpheus disconnected before ${target} answered${hangupHint}. Stay on Connect line (Registered) and try Call again.`,
            'warning',
        );
    }

    async finalizeClickToCallAfterBye(uuid, peer) {
        if (this._finalizeAfterByeInFlight) {
            return;
        }
        this._finalizeAfterByeInFlight = true;

        try {
            this.session = null;
            if (this.ui?.remoteAudio) {
                this.ui.remoteAudio.srcObject = null;
            }

            // Agent SIP byed, but Morpheus may still be ringing the destination.
            // Keep the dark Ringing screen + top-right floating header until the PSTN leg ends.
            this.refreshOutboundRingingUi(peer);

            // User already ended the call / disposition already open — soft cleanup only.
            if (this.userInitiatedHangup || this._callEndedDispatched) {
                return;
            }

            const deadline = Date.now() + 45_000;
            let connected = false;

            while (Date.now() < deadline && uuid) {
                if (this.userInitiatedHangup || this._callEndedDispatched) {
                    return;
                }

                const template = this.callStatusUrlTemplate();
                if (!template) {
                    break;
                }

                try {
                    const url = new URL(template.replace('__UUID__', encodeURIComponent(uuid)), window.location.origin);
                    if (peer) {
                        url.searchParams.set('destination', peer);
                    }
                    const response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                    const data = await response.json().catch(() => ({}));

                    if (response.ok && data.ok !== false) {
                        if (this.applyRemoteCallStatus(data, { source: 'post-bye-poll' })) {
                            connected = true;
                            break;
                        }

                        if (data.live && Number(data.billsec ?? 0) >= 1 && !this.destinationMatchesPeer(data)) {
                            this.refreshOutboundRingingUi(peer);
                        }

                        if (!data.live && data.hangup_cause) {
                            break;
                        }
                    }
                } catch {
                    // Keep polling.
                }

                await new Promise((resolve) => window.setTimeout(resolve, 1200));
            }

            if (this.userInitiatedHangup || this._callEndedDispatched) {
                return;
            }

            if (!connected) {
                await this.showPstnLegFailedToast(uuid, peer);
            }

            const durationSec = connected
                ? Math.max(1, Number(this._lastSeenPstnBillsec || 0))
                : 0;
            this._pendingCallEndMeta = {
                connected,
                result: connected ? 'Connected' : 'No answer',
                durationSec,
            };

            this.clearSession({ emitEnded: true });
        } finally {
            this._finalizeAfterByeInFlight = false;
        }
    }

    /** Keep dialer + floating header on Ringing while PSTN is still being dialed. */
    refreshOutboundRingingUi(peer = '') {
        if (this.userInitiatedHangup || this._callEndedDispatched) {
            return;
        }

        const normalized = normalizeDialTarget(peer)
            || normalizeDialTarget(this.currentCallPeer)
            || normalizeDialTarget(this.lastDialedDestination)
            || peer
            || this.currentCallPeer
            || this.lastDialedDestination
            || '';
        const routeDetail = this.buildCallRouteDetail(normalized);
        const ringingCopy = normalized
            ? `Ringing ${normalized}… waiting for answer.`
            : 'Ringing destination… waiting for answer.';

        this.clickToCallActive = true;
        this.awaitingDestinationBridge = true;
        if (normalized) {
            this.setCallContext('outbound', normalized);
            this.lastDialedDestination = normalized;
        }
        this.setState('dialing');

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
            statusLabel: 'Ringing',
            showRingingVisual: true,
            showConnectedTimer: false,
            timerLabel: 'Ringing time',
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });

        if (this.timerPhase !== 'connected') {
            this.startRingTimer();
        }
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
        this._callEndedDispatched = false;
        this._monitoringHangupNotified = false;
        this._finalizeAfterByeInFlight = false;
        this.userInitiatedHangup = false;
        this.remoteHangupHandled = false;
        this.setCallContext('outbound', normalized);
        this.clickToCallActive = true;
        this.awaitingDestinationBridge = true;
        this.customerFirstOutbound = customerFirst;
        this.lastDialedDestination = normalized || this.currentCallPeer || '';
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
                ? `Ringing ${normalized}… waiting for answer.`
                : 'Ringing destination… waiting for answer.');
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
            statusLabel: 'Ringing',
            showRingingVisual: true,
            showConnectedTimer: false,
            timerLabel: 'Ringing time',
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });
        this.startRingTimer();
        this.setState('dialing');
        this.markClickToCallPending();
        // Do not poll Morpheus until the agent SIP leg is up — early polls on a
        // 404 uuid were slow and could tear down the session before INVITE/answer.
        // Polling starts from SessionState.Established (startDestinationPoll).
        if (this.session?.state === SessionState.Established) {
            this.startDestinationPoll();
        }
    }

    activeCallUuid() {
        if (this.originateCallUuid) {
            return this.originateCallUuid;
        }

        if (this.morpheusCallUuid) {
            return this.morpheusCallUuid;
        }

        if (this.pstnPollUuid) {
            return this.pstnPollUuid;
        }

        const tracked = Array.from(this.trackedCallUuids);

        return tracked.length ? tracked[0] : null;
    }

    hangupCallUuid() {
        return this.originateCallUuid || this.morpheusCallUuid || this.pstnPollUuid || null;
    }

    hangupUrlTemplate() {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');

        return panel?.dataset.hangupUrl || '';
    }

    releaseExtensionUrl() {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');

        return panel?.dataset.releaseExtensionUrl || '';
    }

    callEventsUrlTemplate() {
        const panel = this.panel || document.querySelector('[data-webphone-panel]');

        return panel?.dataset.callEventsUrl || '';
    }

    subscribeCallEvents() {
        this.stopCallEventsStream();

        const uuid = this.activeCallUuid();
        const template = this.callEventsUrlTemplate();
        if (!uuid || !template) {
            return;
        }

        const url = new URL(template.replace('__UUID__', encodeURIComponent(uuid)), window.location.origin);
        if (this.currentCallPeer) {
            url.searchParams.set('destination', this.currentCallPeer);
        }

        try {
            const source = new EventSource(url.toString(), { withCredentials: true });
            this.callEventsSource = source;

            source.onmessage = (event) => {
                let data = {};
                try {
                    data = JSON.parse(event.data);
                } catch {
                    return;
                }

                // Instant Connected: server flags win immediately; otherwise billsec≥1 is enough.
                const serverConfirmed =
                    data.destination_connected === true
                    || data.destination_answered === true
                    || data.outcome === 'connected';

                const agentLive = this.agentLegEstablishedAt > 0;

                const connectedNow =
                    serverConfirmed
                    || (
                        agentLive
                        && Number(data.billsec ?? 0) >= 1
                        && data.live !== false
                        && !data.hangup_cause
                        && this.destinationMatchesPeer(data)
                    );

                if (connectedNow && (this.awaitingDestinationBridge || this.state === 'dialing' || this.timerPhase !== 'connected')) {
                    if (serverConfirmed) {
                        this._serverConfirmedDestination = true;
                    }
                    this.stopAllLocalRingers();
                    this.markDestinationConnected({ source: serverConfirmed ? 'websocket-confirmed' : 'websocket-stream' });
                    if (data.billsec) {
                        this.syncCallTimerFromBillsec(Number(data.billsec));
                    }
                    if (this.isRemoteCallEnded(data)) {
                        void this.handleRemotePartyHangup(data, { source: 'websocket-stream' });
                        this.stopCallEventsStream();
                    }

                    return;
                }

                // Real-time hangup from webhook/SSE.
                if (this.isRemoteCallEnded(data)) {
                    void this.handleRemotePartyHangup(data, { source: 'websocket-stream' });
                    this.stopCallEventsStream();

                    return;
                }

                if (this.applyRemoteCallStatus(data, { source: 'websocket-stream' })) {
                    if (data.billsec && this.timerPhase === 'connected') {
                        this.syncCallTimerFromBillsec(Number(data.billsec));
                    }
                }
            };

            source.onerror = () => {
                this.stopCallEventsStream();
                // Keep listening for answer + remote hangup for the whole call.
                const keepListening =
                    this._callMonitorActive
                    && ['dialing', 'ringing', 'in-call'].includes(this.state);
                if (keepListening) {
                    window.setTimeout(() => {
                        if (
                            this._callMonitorActive
                            && !this.callEventsSource
                            && ['dialing', 'ringing', 'in-call'].includes(this.state)
                        ) {
                            this.subscribeCallEvents();
                        }
                    }, 600);
                }
            };

            this.logPhone('info', 'Subscribed to call events stream', {
                uuid,
                destination: this.currentCallPeer,
            });
        } catch (error) {
            this.logPhone('warn', 'Could not open call events stream', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    stopCallEventsStream() {
        if (this.callEventsSource) {
            this.callEventsSource.close();
            this.callEventsSource = null;
        }
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
        document.querySelectorAll('[data-dialer-call-hold]').forEach((btn) => {
            const labelEl = btn.querySelector('[data-dialer-call-hold-label]') || btn.querySelector('span');
            if (labelEl) {
                labelEl.textContent = label;
            } else {
                btn.textContent = label;
            }
            btn.classList.toggle('is-active', this.callOnHold);
            btn.title = this.callOnHold ? 'Resume call' : 'Place caller on hold';
        });
    }

    applyLocalMicMuted(muted) {
        const pc = this.session?.sessionDescriptionHandler?.peerConnection;
        if (!pc || typeof pc.getSenders !== 'function') {
            return false;
        }

        let applied = false;
        pc.getSenders().forEach((sender) => {
            if (sender.track?.kind === 'audio') {
                sender.track.enabled = !muted;
                // Keep the capture track live when unmuted so destination gets speech.
                if (!muted && typeof sender.track.contentHint === 'string') {
                    try {
                        sender.track.contentHint = 'speech';
                    } catch {
                        // Older browsers ignore contentHint.
                    }
                }
                applied = true;
            }
        });

        return applied;
    }

    /**
     * Ensure local mic is live for the destination (unless agent explicitly muted).
     * soft=true only enables a disabled track — never flickers an already-live mic.
     */
    ensureOutboundMicLive({ soft = false } = {}) {
        if (this.micMuted) {
            this.updateMuteUi();
            return false;
        }

        const pc = this.session?.sessionDescriptionHandler?.peerConnection;
        let applied = false;
        if (pc && typeof pc.getSenders === 'function') {
            pc.getSenders().forEach((sender) => {
                if (sender.track?.kind !== 'audio') {
                    return;
                }
                if (soft) {
                    if (sender.track.enabled === false) {
                        sender.track.enabled = true;
                        applied = true;
                    }
                    return;
                }
                if (sender.track.enabled === false) {
                    sender.track.enabled = true;
                    applied = true;
                }
            });
        } else if (!soft) {
            applied = this.applyLocalMicMuted(false);
        }

        this.micMuted = false;
        this.updateMuteUi();

        return applied;
    }

    updateMuteUi() {
        const label = this.micMuted ? 'Unmute' : 'Mute';
        if (this.ui?.muteBtn) {
            this.ui.muteBtn.textContent = label;
            this.ui.muteBtn.classList.toggle('is-active', this.micMuted);
            this.ui.muteBtn.setAttribute('aria-pressed', this.micMuted ? 'true' : 'false');
            this.ui.muteBtn.title = this.micMuted ? 'Unmute your microphone' : 'Mute your microphone';
        }

        document.querySelectorAll('[data-dialer-call-mute]').forEach((btn) => {
            const labelEl = btn.querySelector('[data-dialer-call-mute-label]') || btn.querySelector('span:not([aria-hidden])');
            if (labelEl && labelEl.tagName !== 'SVG') {
                labelEl.textContent = label;
            }
            btn.classList.toggle('is-active', this.micMuted);
            btn.setAttribute('aria-pressed', this.micMuted ? 'true' : 'false');
            btn.title = this.micMuted ? 'Unmute your microphone' : 'Mute your microphone';
        });
    }

    async toggleMute() {
        const onCall = ['dialing', 'ringing', 'in-call'].includes(this.state);
        if (!onCall) {
            throw new Error('Mute is available while dialing or on a call.');
        }

        const nextMuted = !this.micMuted;
        const applied = this.applyLocalMicMuted(nextMuted);
        this.micMuted = nextMuted;
        if (!nextMuted) {
            // Unmute always opens speech path; speech gate will re-settle.
            this._speechGateSpeaking = true;
            this._speechGateLastVoiceAt = Date.now();
            this._speechGateSilentSince = 0;
        }
        this.updateMuteUi();
        showCommToast(
            this.micMuted
                ? (applied ? 'Microphone muted.' : 'Microphone muted (ringing).')
                : 'Microphone unmuted.',
            'info',
        );

        // If tracks appear later (early media → talk), keep mute applied.
        if (this.micMuted) {
            window.setTimeout(() => this.applyLocalMicMuted(true), 400);
            window.setTimeout(() => this.applyLocalMicMuted(true), 1200);
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
        showCommToast(this.callOnHold ? 'Call on hold.' : 'Call resumed.', 'info');
    }

    async promptTransfer() {
        if (!this.morpheusCallUuid) {
            throw new Error('Connect a call before transferring.');
        }

        if (this.openTransferModal()) {
            return;
        }

        const destination = window.prompt('Transfer to extension or phone number:', '');
        if (!destination || !String(destination).trim()) {
            return;
        }

        await this.executeTransfer(destination);
    }

    async executeTransfer(destination) {
        if (!this.morpheusCallUuid) {
            throw new Error('Connect a call before transferring.');
        }

        const raw = String(destination || '').trim();
        if (!raw) {
            return;
        }

        const digits = raw.replace(/\D/g, '');
        const payload = digits || raw;

        await this.postCallAction('transfer', { destination: payload });
        showCommToast(`Call transferred to ${raw}.`, 'info');
        await this.hangup().catch(() => {});
    }

    stopDestinationPoll() {
        this._callMonitorActive = false;
        this.stopCallEventsStream();

        if (this._statusPollAbort) {
            try {
                this._statusPollAbort.abort();
            } catch {
                // ignore
            }
            this._statusPollAbort = null;
        }
        this._statusPollInFlight = false;

        if (!this.destinationPollTimer) {
            return;
        }

        window.clearTimeout(this.destinationPollTimer);
        this.destinationPollTimer = null;
    }

    startDestinationPoll() {
        this.stopDestinationPoll();
        this._callMonitorActive = true;
        this.remoteHangupHandled = false;
        this.subscribeCallEvents();
        this.runCallMonitorLoop().catch(() => {});
    }

    scheduleCallMonitor(delayMs = 350) {
        if (!this._callMonitorActive || !this.activeCallUuid()) {
            return;
        }

        if (this.destinationPollTimer) {
            window.clearTimeout(this.destinationPollTimer);
        }

        this.destinationPollTimer = window.setTimeout(() => {
            this.destinationPollTimer = null;
            this.runCallMonitorLoop().catch(() => {});
        }, delayMs);
    }

    async runCallMonitorLoop() {
        if (!this._callMonitorActive || !this.activeCallUuid()) {
            return;
        }

        await this.pollDestinationOnce();

        if (this._callMonitorActive && this.activeCallUuid()) {
            // SSE covers connect/hangup. Keep HTTP status polls light so they
            // never stack behind the 4s client abort / block other CRM requests.
            const hasEvents = Boolean(this.callEventsSource);
            let delayMs = 2500;
            if (this.awaitingDestinationBridge || this.state === 'dialing') {
                // Poll aggressively while waiting for answer — Connected must flip instantly.
                delayMs = hasEvents ? 450 : 300;
            } else if (this.state === 'in-call') {
                delayMs = hasEvents ? 4000 : 2500;
                // Keep wallboard connected_at locked to dialer timer while call is live.
                if (this.callStartedAt && this.timerPhase === 'connected') {
                    const lastSync = this._lastConnectedAtSyncAt || 0;
                    if (Date.now() - lastSync > 12000) {
                        this._lastConnectedAtSyncAt = Date.now();
                        this.reportDestinationConnected('heartbeat');
                    }
                }
            }
            this.scheduleCallMonitor(delayMs);
        }
    }

    async pollDestinationOnce() {
        const uuid = this.activeCallUuid();
        const template = this.callStatusUrlTemplate();

        if (!uuid || !template || !this._callMonitorActive) {
            return;
        }

        // Never stack slow Morpheus polls — that kept the UI stuck on Ringing.
        if (this._statusPollInFlight) {
            return;
        }

        if (
            this.state === 'in-call'
            && this.session?.state === SessionState.Terminated
            && !this.hangupInFlight
            && !this.remoteHangupHandled
        ) {
            await this.handleRemotePartyHangup(
                { hangup_cause: 'NORMAL_CLEARING' },
                { source: 'poll-sip-terminated' },
            );

            return;
        }

        const url = new URL(template.replace('__UUID__', encodeURIComponent(uuid)), window.location.origin);
        if (this.currentCallPeer) {
            url.searchParams.set('destination', this.currentCallPeer);
        }
        if (this.customerFirstOutbound) {
            url.searchParams.set('customer_first', '1');
        }

        this._statusPollInFlight = true;
        const abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        this._statusPollAbort = abort;
        // Allow slow Morpheus hops; do not abort healthy in-flight polls early.
        const timeoutId = window.setTimeout(() => {
            try {
                abort?.abort();
            } catch {
                // ignore
            }
        }, 12000);

        try {
            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: abort?.signal,
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || data.ok === false) {
                return;
            }

            if (data.bridged_to && data.bridged_to !== uuid) {
                this.bridgedCallUuid = String(data.bridged_to);
                this.trackedCallUuids.add(String(data.bridged_to));
            }

            if (this.applyRemoteCallStatus(data, { source: 'poll' })) {
                return;
            }

            if (this.state === 'in-call' && Number(data.billsec ?? 0) > 0) {
                this.syncCallTimerFromBillsec(Number(data.billsec));
            }

            if (
                (this.awaitingDestinationBridge || this.state === 'dialing')
                && (
                    data.destination_connected === true
                    || data.destination_answered === true
                    || data.outcome === 'connected'
                )
            ) {
                this._serverConfirmedDestination = true;
                this.stopAllLocalRingers();
                this.markDestinationConnected({ source: 'poll-confirmed' });

                return;
            }

            if (this.isRemoteCallEnded(data)) {
                await this.handleRemotePartyHangup(data, { source: 'poll' });

                return;
            }

            if (
                this.awaitingDestinationBridge
                && data.live
                && Number(data.billsec ?? 0) >= 1
                && !this.destinationMatchesPeer(data)
                && data.destination_connected !== true
            ) {
                const ringingCopy = this.currentCallPeer
                    ? `Ringing ${this.currentCallPeer}… waiting for answer.`
                    : 'Destination is ringing — waiting for answer.';
                this.updateCallCard({
                    title: 'Outgoing call',
                    subtitle: ringingCopy,
                    detail: this.buildCallRouteDetail(this.currentCallPeer),
                    visible: true,
                });
                this.updateFloatingPopup({
                    title: 'Outgoing call',
                    subtitle: ringingCopy,
                    detail: this.buildCallRouteDetail(this.currentCallPeer),
                    visible: true,
                    statusLabel: 'Ringing',
                    showRingingVisual: true,
                    showConnectedTimer: false,
                    timerLabel: 'Ringing time',
                    showAnswer: false,
                    showHangup: true,
                    showRecord: true,
                    state: 'dialing',
                });
                if (!this.timerPhase) {
                    this.startRingTimer();
                }
            }

            if (!this.awaitingDestinationBridge) {
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
                    && billsec < 15
                    && elapsed < 120_000
                    && (this.customerFirstOutbound || transientCauses.includes(cause) || cause === 'NORMAL_CLEARING')
                ) {
                    return;
                }

                this.stopDestinationPoll();

                showCommToast(
                    humanCallEndMessage(cause, { outbound: true }),
                    'warning',
                );
                this.clearSession();
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                // Keep polling while the SIP leg is still active.
            }
        } finally {
            window.clearTimeout(timeoutId);
            this._statusPollInFlight = false;
            if (this._statusPollAbort === abort) {
                this._statusPollAbort = null;
            }
        }
    }

    markDestinationConnected({ source = 'poll' } = {}) {
        const promoting =
            this.awaitingDestinationBridge || this.state === 'dialing' || this.state === 'connecting';

        if (!promoting && this.state === 'in-call') {
            // Already live — never restart/reset the connected timer (causes flicker).
            this._serverConfirmedDestination = true;
            if (!this.callTimer || !this.callStartedAt || this.timerPhase !== 'connected') {
                this.startCallTimer({ restart: false });
            }
            this.reportDestinationConnected(source);

            return;
        }

        if (!promoting) {
            return;
        }

        this.logPhone('info', 'Destination connected — updating UI to live', {
            source,
            peer: this.currentCallPeer,
            uuid: this.originateCallUuid,
        });

        this.awaitingDestinationBridge = false;
        this.clickToCallActive = false;
        if (!this.morpheusCallUuid && this.pstnPollUuid) {
            this.morpheusCallUuid = this.pstnPollUuid;
        }
        this.stopRemoteAnswerWatcher();
        if (!this._callMonitorActive) {
            this.startDestinationPoll();
        }

        if (this.session) {
            this.attachRemoteAudio(this.session);
        }

        this.enterBothSidesConnected({ source, restartTimer: true });
        this.reportDestinationConnected(source);
    }

    /**
     * Tell the CRM wallboard both sides are connected (Morpheus webhooks often miss this).
     */
    reportDestinationConnected(source = 'agent') {
        const uuid = this.morpheusCallUuid || this.originateCallUuid || this.pstnPollUuid;
        if (!uuid) {
            return;
        }

        // Ensure dialer timer is started BEFORE we stamp connected_at for monitoring.
        if (!this.callStartedAt || this.timerPhase !== 'connected') {
            this.startCallTimer({ restart: true });
        }

        const template = this.callActionUrlTemplate('destinationConnected')
            || this.panel?.dataset?.destinationConnectedUrl
            || '';
        if (!template) {
            return;
        }

        const isSync = source === 'agent-sync' || source === 'heartbeat';
        if (this._reportedDestinationConnected === uuid && ! isSync) {
            return;
        }

        this._reportedDestinationConnected = uuid;
        const url = template.replace('__UUID__', encodeURIComponent(uuid));
        const startedAt = this.callStartedAt || Date.now();
        // Match dialer display seconds (0-based), not a forced +1.
        const billsec = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
        const destination = this.currentCallPeer || this.lastDialedDestination || '';

        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = window.setTimeout(() => {
            try {
                controller?.abort();
            } catch {
                // ignore
            }
        }, 8000);

        void fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            signal: controller?.signal,
            body: JSON.stringify({
                destination,
                billsec,
                source: String(source || 'agent'),
                // Exact dialer timer epoch — monitoring must use this for matching seconds.
                connected_at: new Date(startedAt).toISOString(),
            }),
        }).then((response) => {
            if (!response.ok) {
                this._reportedDestinationConnected = null;
            }
        }).catch(() => {
            this._reportedDestinationConnected = null;
        }).finally(() => {
            window.clearTimeout(timeoutId);
        });

        // Re-sync shortly after so a server-first mark cannot keep a mismatched connected_at.
        if (! isSync && ! this._connectedAtSyncScheduled) {
            this._connectedAtSyncScheduled = true;
            window.setTimeout(() => {
                this._connectedAtSyncScheduled = false;
                if (this.state === 'in-call' && this.callStartedAt) {
                    this.reportDestinationConnected('agent-sync');
                }
            }, 500);
        }
    }

    /**
     * Both legs are up: stop ringing and start the connected call timer.
     */
    enterBothSidesConnected({ source = 'unknown', restartTimer = true } = {}) {
        const alreadyLive =
            this.state === 'in-call'
            && this.timerPhase === 'connected'
            && Boolean(this.callStartedAt)
            && Boolean(this.callTimer);

        this.awaitingDestinationBridge = false;
        this.clickToCallActive = false;
        this._serverConfirmedDestination = true;
        this.agentLegEstablishedAt = this.agentLegEstablishedAt || Date.now();

        // Poll/SSE often re-confirm "connected" — do not rewrite timer UI to 00:00.
        if (alreadyLive && !restartTimer) {
            return;
        }

        this.stopAllLocalRingers();
        this.outboundWaitingActive = false;
        // Keep remote muted while local ringers stop, then open talk audio quickly.
        this.setRemoteAudioMuted(true);
        void (async () => {
            // Soft one-time mic tune only — no replaceTrack (prevents voice drops).
            await this.reinforceAntiEcho({ retuneMic: true });
            this.ensureOutboundMicLive({ soft: true });
            this.startAntiEchoKeepalive();
            this.startOutboundSpeechGate();
            window.setTimeout(() => {
                if (this.state !== 'in-call' || this.hangupInFlight) {
                    return;
                }
                this.setRemoteAudioMuted(false);
                this.ensureOutboundMicLive({ soft: true });
            }, 120);
        })();
        this.ensureOutboundMicLive({ soft: true });

        if (!this.currentCallPeer && this.lastDialedDestination) {
            this.currentCallPeer = normalizeDialTarget(this.lastDialedDestination) || this.lastDialedDestination;
        }

        // First connect may restart; later confirmations must keep the same epoch.
        if (restartTimer && !alreadyLive) {
            this.startCallTimer({ restart: true });
        } else if (this.timerPhase !== 'connected' || !this.callTimer || !this.callStartedAt) {
            this.startCallTimer({ restart: false });
        }

        const peer = this.currentCallPeer || this.lastDialedDestination || '';
        const detail = peer
            ? (this.currentCallDirection === 'outbound' ? `To: ${peer}` : `Caller: ${peer}`)
            : '';
        const timerDisplay = this.currentFormattedTimer() || '00:00';

        this.updateCallCard({
            title: peer || 'Call live',
            subtitle: 'Both sides connected — timer started.',
            detail,
            visible: true,
            timer: timerDisplay,
        });
        this.updateFloatingPopup({
            title: peer || 'Call live',
            subtitle: 'Both sides connected — timer started.',
            detail,
            visible: true,
            timer: timerDisplay,
            timerLabel: 'Connected time',
            statusLabel: 'Connected',
            showRingingVisual: false,
            showConnectedTimer: true,
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            showHold: Boolean(this.morpheusCallUuid || this.activeCallUuid?.()),
            showMute: true,
            showTransfer: Boolean(this.morpheusCallUuid || this.activeCallUuid?.()),
            state: 'in-call',
        });
        this.setCallControlsVisible(true, {
            showRecord: true,
            showEndCall: true,
            showHold: Boolean(this.morpheusCallUuid || this.activeCallUuid?.()),
            showMute: true,
            showTransfer: Boolean(this.morpheusCallUuid || this.activeCallUuid?.()),
        });
        if (this.state !== 'in-call') {
            this.setState('in-call');
        } else {
            this.refreshDialerCallOverlay('in-call');
        }

        this.logPhone('info', 'Both sides connected', { source, peer });
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

    isTransportConnected() {
        const state = this.userAgent?.transport?.state;
        if (typeof state === 'string') {
            return state.toLowerCase() === 'connected';
        }

        return !!(this.userAgent?.transport?.isConnected?.() ?? this.canDirectDial());
    }

    configuredWssUrl() {
        return (
            this.activeWssUrl ||
            this.config?.wss_url ||
            this.panel?.dataset?.wssUrl ||
            ''
        );
    }

    async ensureLiveTransport(extension) {
        if (this.canDirectDial() && this.isTransportConnected()) {
            return true;
        }

        const normalized = String(extension || selectedExtension() || this.currentExtension || '').replace(/\D/g, '');
        if (!normalized) {
            throw new Error('Select your extension before calling.');
        }

        await this.connect(normalized);

        return this.canDirectDial() && this.isTransportConnected();
    }

    async assertTransportForOriginate() {
        ensureDefaultExtensionSelected();
        const extension = selectedExtension();
        if (!extension) {
            throw new Error('Select your extension before calling.');
        }

        await this.ensureLiveTransport(extension);

        if (!this.canDirectDial() || !this.isTransportConnected()) {
            const wssUrl = this.configuredWssUrl() || 'wss://apexone.morpheus.cx:7443/';
            throw new Error(
                `Phone WebSocket is not connected. Click Connect line and wait for Registered on ${wssUrl}.`,
            );
        }

        return true;
    }

    setCallContext(direction, peer = '') {
        this.currentCallDirection = direction;
        const normalized = peer ? (normalizeDialTarget(peer) || String(peer)) : '';
        this.currentCallPeer = normalized;
        if (normalized) {
            this.lastDialedDestination = normalized;
        }
    }

    syncSelectedExtension() {
        if (!this.ui) {
            return;
        }

        const extension = selectedExtension() || this.currentExtension || this.panel?.dataset.defaultExtension || '—';
        const connected =
            this.state === 'registered' ||
            this.state === 'dialing' ||
            this.state === 'ringing' ||
            this.state === 'in-call';
        const displayExtension = connected && this.currentExtension ? this.currentExtension : extension;

        if (this.ui.extension) {
            this.ui.extension.textContent = extension;
        }
        if (this.ui.bridgeExtension) {
            this.ui.bridgeExtension.textContent = extension;
        }
        document.querySelectorAll('[data-webphone-connected-extension]').forEach((el) => {
            el.textContent = displayExtension;
        });

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

    currentFormattedTimer() {
        if (!this.callStartedAt) {
            return null;
        }

        const seconds = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
        return formatDuration(seconds);
    }

    updateCallCard({ title = '', subtitle = '', detail = '', visible = false, timer = null } = {}) {
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
        // null = keep current text so poll/SSE UI refreshes do not snap to 00:00.
        if (this.ui.callTimer && timer != null) {
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
        timer = null,
        timerLabel = 'Connected time',
        statusLabel = '',
        showRingingVisual = false,
        showConnectedTimer = false,
        showAnswer = false,
        showHangup = false,
        showRecord = false,
        showHold = false,
        showMute = false,
        showTransfer = false,
        state = 'offline',
    } = {}) {
        const peer =
            this.currentCallPeer ||
            detail.replace(/^(To:|Caller:)\s*/i, '').trim() ||
            document.querySelector('.ghl-dialer-form--phone [name="destination"]')?.value ||
            '';
        const showTimer =
            visible &&
            this.timerPhase === 'connected' &&
            (state === 'in-call' || statusLabel === 'Connected');
        const badgeText =
            statusLabel ||
            (state === 'in-call' ? 'Connected' : state === 'ringing' || state === 'dialing' ? 'Ringing' : '');
        const resolvedTimer =
            timer
            ?? this.currentFormattedTimer()
            ?? document.querySelector('[data-dialer-call-timer]')?.textContent
            ?? '00:00';

        document.querySelectorAll('[data-dialer-input-shell]').forEach((shell) => {
            shell.classList.toggle('is-call-active', visible);
            shell.dataset.callState = visible ? state : 'idle';

            const input = shell.querySelector('[name="destination"]');
            if (input) {
                input.readOnly = visible;
                input.setAttribute('aria-readonly', visible ? 'true' : 'false');
            }
        });

        document.querySelectorAll('[data-dialer-call-layer]').forEach((layer) => {
            layer.classList.toggle('hidden', !visible);
            layer.dataset.state = visible ? state : 'idle';
            layer.classList.toggle('is-ringing', visible && (showRingingVisual || state === 'ringing' || state === 'dialing'));
            layer.classList.toggle('is-connected', visible && (statusLabel === 'Connected' || state === 'in-call'));

            const badge = layer.querySelector('[data-dialer-call-badge]');
            if (badge) {
                badge.textContent = badgeText;
                badge.classList.toggle('hidden', !badgeText);
                badge.classList.toggle('is-connected', badgeText === 'Connected');
                badge.classList.toggle('is-ringing', badgeText === 'Ringing' || badgeText === 'Connecting');
            }

            const peerEl = layer.querySelector('[data-dialer-call-peer]');
            if (peerEl) {
                peerEl.textContent = peer;
                peerEl.classList.toggle('hidden', !peer);
            }

            const timerEl = layer.querySelector('[data-dialer-call-timer]');
            if (timerEl) {
                if (timer != null || showTimer) {
                    timerEl.textContent = resolvedTimer;
                }
                timerEl.classList.toggle('hidden', !showTimer);
                timerEl.dataset.timerLabel = timerLabel;
            }
        });

        this.setDialerCallControlsVisible(visible, {
            showRecord,
            showEndCall: showHangup,
            showAnswer,
            showHold,
            showMute,
            showTransfer,
        });

        this.updateActiveCallScreen({
            visible,
            state,
            peer,
            timer: resolvedTimer,
            showTimer,
            showHold,
            showMute,
            showTransfer,
            showRecord,
            statusLabel: badgeText,
        });

        if (!visible) {
            this.resetDialerCallUi();
        }
    }

    updateActiveCallScreen({
        visible = false,
        state = 'offline',
        peer = '',
        timer = null,
        showTimer = false,
        showHold = false,
        showMute = false,
        showTransfer = false,
        showRecord = false,
        statusLabel = '',
    } = {}) {
        const phoneMode = isPhoneDialerMode();
        const onCall = visible && phoneMode;
        const connected =
            statusLabel === 'Connected'
            || this._serverConfirmedDestination
            || (state === 'in-call' && this.timerPhase === 'connected');
        const statusText =
            connected
                ? 'Connected'
                : (
                    statusLabel === 'Connecting' && (state === 'dialing' || state === 'ringing')
                        ? 'Ringing'
                        : (
                            statusLabel
                            || (state === 'ringing' || state === 'dialing' ? 'Ringing' : 'Connecting')
                        )
                );
        const displayPeer = normalizeDialTarget(peer)
            || normalizeDialTarget(this.currentCallPeer)
            || normalizeDialTarget(this.lastDialedDestination)
            || peer
            || this.currentCallPeer
            || '—';

        document.querySelectorAll('[data-dialer-active-screen]').forEach((screen) => {
            screen.classList.toggle('hidden', !onCall);
            screen.setAttribute('aria-hidden', onCall ? 'false' : 'true');
            screen.classList.toggle('is-ringing', onCall && !connected);
            screen.classList.toggle('is-connected', onCall && connected);

            const peerEl = screen.querySelector('[data-dialer-active-peer]');
            if (peerEl) {
                peerEl.textContent = displayPeer;
            }

            const statusEl = screen.querySelector('[data-dialer-active-status]');
            if (statusEl) {
                statusEl.textContent = statusText;
            }

            const timerEl = screen.querySelector('[data-dialer-active-timer]');
            if (timerEl) {
                const nextTimer =
                    timer
                    ?? this.currentFormattedTimer()
                    ?? timerEl.textContent
                    ?? '00:00';
                timerEl.textContent = nextTimer;
                timerEl.classList.toggle('hidden', !showTimer);
            }
        });

        document.querySelectorAll('[data-dialer-phone-stage]').forEach((stage) => {
            stage.classList.toggle('is-hidden-during-call', onCall);
        });
    }

    refreshDialerCallOverlay(state = this.state) {
        const onActiveCall = state === 'dialing' || state === 'ringing' || state === 'in-call';
        if (!onActiveCall) {
            return;
        }

        const peer =
            this.currentCallPeer ||
            document.querySelector('.ghl-dialer-form--phone [name="destination"]')?.value ||
            '';
        const outboundActive =
            this.currentCallDirection === 'outbound' ||
            this.clickToCallActive ||
            this.pendingClickToCall ||
            this.directDialActive;
        // Ringback / ring timer means the destination is being rung — show Ringing,
        // not Connecting (Connecting used to stick until SIP Established even while audio played).
        const isRingingUi =
            this.timerPhase === 'ringing'
            || this.outboundWaitingActive
            || (outboundActive && (this.awaitingDestinationBridge || state === 'dialing' || state === 'ringing'));
        const statusLabel =
            this.timerPhase === 'connected' || this._serverConfirmedDestination || state === 'in-call'
                ? 'Connected'
                : isRingingUi
                  ? 'Ringing'
                  : state === 'ringing' || state === 'dialing'
                    ? 'Ringing'
                    : 'Connecting';
        const timerEl = document.querySelector('[data-dialer-call-timer]');
        const isConnectedUi = statusLabel === 'Connected';
        const overlayTimer =
            this.currentFormattedTimer()
            || timerEl?.textContent
            || '00:00';

        this.updateFloatingPopup({
            title: isConnectedUi ? 'Call live' : 'Outgoing call',
            subtitle: isConnectedUi
                ? 'Both sides connected — timer started.'
                : (peer ? `Ringing ${peer}…` : 'Ringing destination…'),
            detail: peer ? `To: ${peer}` : '',
            visible: true,
            timer: overlayTimer,
            timerLabel: isConnectedUi ? 'Connected time' : 'Ringing time',
            statusLabel,
            showRingingVisual: !isConnectedUi,
            showConnectedTimer: isConnectedUi,
            showAnswer: state === 'ringing' && !outboundActive,
            showHangup: true,
            showRecord: true,
            showHold: isConnectedUi && Boolean(this.morpheusCallUuid),
            showMute: true,
            showTransfer: isConnectedUi && Boolean(this.morpheusCallUuid),
            state: isConnectedUi ? 'in-call' : state,
        });
    }

    flushCallUiInstant() {
        this.stopCallTimer();
        this.updateCallCard({ visible: false });
        this.updateFloatingPopup({ visible: false });
        this.resetDialerCallUi();
        document.querySelectorAll('.ghl-dialer-call-icon-btn').forEach((btn) => {
            delete btn.dataset.webphoneDialState;
            btn.classList.remove('is-hangup-mode');
            btn.setAttribute('aria-label', 'Place call');
            btn.setAttribute('title', 'Call');
        });
        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
            this.ui.remoteAudio.pause?.();
        }
    }

    startCallTimer({ restart = false } = {}) {
        if (this.awaitingDestinationBridge && !restart) {
            return;
        }

        // Keep a stable connected epoch — restarting mid-call resets the UI to 00:00.
        if (
            !restart
            && this.timerPhase === 'connected'
            && this.callTimer
            && this.callStartedAt
        ) {
            this.tickCallTimer();
            return;
        }

        const preservedStartedAt =
            !restart
            && this.timerPhase === 'connected'
            && this.callStartedAt
                ? this.callStartedAt
                : null;

        if (this.callTimer) {
            window.clearInterval(this.callTimer);
            this.callTimer = null;
        }

        this.timerPhase = 'connected';
        this.callStartedAt = preservedStartedAt || Date.now();
        document.querySelectorAll('[data-dialer-call-layer]').forEach((layer) => {
            layer.classList.remove('is-ringing');
            layer.classList.add('is-connected');
            const badge = layer.querySelector('[data-dialer-call-badge]');
            if (badge) {
                badge.textContent = 'Connected';
                badge.classList.remove('is-ringing');
                badge.classList.add('is-connected');
            }
        });
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            timerEl.classList.remove('hidden');
            timerEl.dataset.timerLabel = 'Connected time';
        });
        document.querySelectorAll('[data-webphone-floating-timer-label]').forEach((label) => {
            label.textContent = 'Connected time';
        });
        document.querySelectorAll('[data-webphone-floating-timer-row]').forEach((row) => {
            row.classList.remove('hidden');
        });

        this.tickCallTimer();
        this.callTimer = window.setInterval(() => this.tickCallTimer(), 1000);
        this.refreshDialerCallOverlay('in-call');
    }

    startRingTimer() {
        this.stopCallTimer();
        this.timerPhase = 'ringing';
        this.callStartedAt = Date.now();
        this._ringNoAnswerTimeoutFired = false;
        document.querySelectorAll('[data-dialer-call-layer]').forEach((layer) => {
            layer.classList.add('is-ringing');
            layer.classList.remove('is-connected');
            const badge = layer.querySelector('[data-dialer-call-badge]');
            if (badge) {
                badge.textContent = 'Ringing';
                badge.classList.add('is-ringing');
                badge.classList.remove('is-connected');
            }
        });
        // Show ringing timer so agents see time advancing while waiting for answer.
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            timerEl.classList.remove('hidden');
            timerEl.dataset.timerLabel = 'Ringing time';
            timerEl.textContent = '00:00';
        });
        document.querySelectorAll('[data-webphone-floating-timer-row]').forEach((row) => {
            row.classList.remove('hidden');
        });
        document.querySelectorAll('[data-webphone-floating-timer-label]').forEach((label) => {
            label.textContent = 'Ringing time';
        });
        this.tickRingTimer();
        this.callTimer = window.setInterval(() => this.tickRingTimer(), 1000);
        this.refreshDialerCallOverlay(this.state === 'in-call' ? 'dialing' : this.state);
    }

    tickRingTimer() {
        if (!this.callStartedAt || this.timerPhase !== 'ringing') {
            return;
        }

        const elapsed = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
        const mm = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const ss = String(elapsed % 60).padStart(2, '0');
        const text = `${mm}:${ss}`;
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer], [data-webphone-floating-timer]').forEach((el) => {
            el.textContent = text;
        });

        // After 1:10 of ringing with no answer — hang up and open Call Summary.
        if (
            !this._ringNoAnswerTimeoutFired
            && elapsed >= Math.floor(RING_NO_ANSWER_TIMEOUT_MS / 1000)
            && (this.awaitingDestinationBridge || this.clickToCallActive || this.state === 'dialing' || this.state === 'ringing')
            && !this._serverConfirmedDestination
            && this.timerPhase === 'ringing'
        ) {
            this._ringNoAnswerTimeoutFired = true;
            void this.endRingNoAnswerTimeout();
        }
    }

    async endRingNoAnswerTimeout() {
        if (this.hangupInFlight || this.remoteHangupHandled) {
            return;
        }

        // Still connected / answered — do not force hangup.
        if (this.timerPhase === 'connected' || this._serverConfirmedDestination || this.state === 'in-call') {
            return;
        }

        this.logPhone('info', 'Ring timeout — no answer after 1:10', {
            peer: this.currentCallPeer || this.lastDialedDestination || '',
            uuid: this.hangupCallUuid(),
        });

        await this.hangup('ring-no-answer-timeout');
    }

    syncCallTimerFromBillsec(billsec) {
        if (this.timerPhase !== 'connected') {
            return;
        }

        const serverSeconds = Math.max(0, Math.floor(Number(billsec) || 0));
        if (serverSeconds < 1 || !this.callStartedAt) {
            return;
        }

        const localSeconds = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
        if (serverSeconds > localSeconds + 1) {
            this.callStartedAt = Date.now() - serverSeconds * 1000;
            this.tickCallTimer();
        }
    }

    tickCallTimer() {
        if (!this.callStartedAt || this.timerPhase !== 'connected') {
            return;
        }

        const seconds = Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000));
        const formatted = formatDuration(seconds);
        if (this.ui?.callTimer && this.ui.callTimer.textContent !== formatted) {
            this.ui.callTimer.textContent = formatted;
        }
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            if (timerEl.textContent !== formatted) {
                timerEl.textContent = formatted;
            }
            timerEl.classList.remove('hidden');
        });
    }

    stopCallTimer() {
        if (this.callTimer) {
            window.clearInterval(this.callTimer);
            this.callTimer = null;
        }
        this.callStartedAt = null;
        this.timerPhase = null;
        if (this.ui?.callTimer) {
            this.ui.callTimer.textContent = '00:00';
        }
        document.querySelectorAll('[data-dialer-call-timer]').forEach((timerEl) => {
            timerEl.textContent = '00:00';
        });
    }

    resetDialerCallUi() {
        document.querySelectorAll('[data-dialer-input-shell]').forEach((shell) => {
            shell.classList.remove('is-call-active');
            shell.dataset.callState = 'idle';
            const input = shell.querySelector('[name="destination"]');
            if (input) {
                input.readOnly = false;
                input.removeAttribute('aria-readonly');
            }
        });
        document.querySelectorAll('[data-dialer-call-layer]').forEach((layer) => {
            layer.classList.add('hidden');
            layer.dataset.state = 'idle';
            layer.classList.remove('is-ringing', 'is-connected');
        });
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            timerEl.textContent = '00:00';
            timerEl.classList.add('hidden');
        });
        document.querySelectorAll('[data-dialer-active-screen]').forEach((screen) => {
            screen.classList.add('hidden');
            screen.setAttribute('aria-hidden', 'true');
            screen.classList.remove('is-ringing', 'is-connected');
        });
        this.setActiveKeypadOpen(false);
        this.closeTransferModal();
        document.querySelectorAll('[data-dialer-phone-stage]').forEach((stage) => {
            stage.classList.remove('is-hidden-during-call');
        });
        this.setDialerCallControlsVisible(false);
    }

    setState(state, message = '') {
        const previousState = this.state;
        this.state = state;

        const activeStates = ['dialing', 'ringing', 'in-call'];
        if (activeStates.includes(state) && !activeStates.includes(previousState)) {
            window.dispatchEvent(new CustomEvent('comm:call-active', {
                detail: {
                    phone: this.currentCallPeer || '',
                    callUuid: this.hangupCallUuid() || '',
                },
            }));
        }

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
        const connectLabel =
            state === 'connecting'
                ? 'Connecting…'
                : registered
                  ? 'Connected'
                  : 'Connect';
        const labelEl = this.ui.connectBtn?.querySelector('[data-webphone-connect-label]');
        if (labelEl) {
            labelEl.textContent = connectLabel;
        } else if (this.ui.connectBtn) {
            this.ui.connectBtn.textContent =
                state === 'connecting' ? 'Connecting…' : registered ? 'Reconnect line' : 'Connect line';
        }
        this.ui.connectBtn.disabled = state === 'connecting';
        const isConnecting = state === 'connecting';
        const isConnected = registered && !isConnecting;
        document.querySelectorAll('[data-comm-connect-group]').forEach((group) => {
            group.classList.toggle('is-connecting', isConnecting);
            group.classList.toggle('is-connected', isConnected);
        });
        this.ui.connectBtn.classList.toggle('hidden', state === 'dialing' || state === 'in-call');
        const canDisconnect = registered && state !== 'dialing' && state !== 'in-call';
        this.ui.disconnectBtns?.forEach((btn) => {
            const inMenu = btn.closest('.ghl-comm-connect-group__dropdown');
            if (inMenu) {
                btn.classList.remove('hidden');
                btn.disabled = !canDisconnect || state === 'connecting';
            } else {
                btn.classList.toggle('hidden', !canDisconnect);
                btn.disabled = !canDisconnect || state === 'connecting';
            }
        });
        const onActiveCall = state === 'dialing' || state === 'ringing' || state === 'in-call';
        document.querySelectorAll('.ghl-dialer-call-icon-btn').forEach((btn) => {
            if (onActiveCall) {
                btn.dataset.webphoneDialState = state;
                btn.classList.add('is-hangup-mode');
                btn.setAttribute('aria-label', 'End call');
                btn.setAttribute('title', 'End call');
                btn.removeAttribute('disabled');
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
                btn.removeAttribute('aria-disabled');
            } else {
                delete btn.dataset.webphoneDialState;
                btn.classList.remove('is-hangup-mode');
                btn.setAttribute('aria-label', 'Place call');
                btn.setAttribute('title', 'Call');
            }
        });
        const outboundActive =
            onActiveCall &&
            (this.currentCallDirection === 'outbound' ||
                this.clickToCallActive ||
                this.pendingClickToCall ||
                this.directDialActive);
        const showAnswer = state === 'ringing' && !outboundActive;
        this.ui.answerBtns?.forEach((btn) => {
            btn.classList.toggle('hidden', !showAnswer);
        });
        const showHangup = state === 'dialing' || state === 'ringing' || state === 'in-call';
        this.ui.hangupBtns?.forEach((btn) => {
            btn.classList.toggle('hidden', !showHangup);
            btn.classList.toggle('ghl-webphone-btn-end-call', showHangup);
        });
        this.ui.hangupBtn?.classList.toggle('ghl-webphone-btn-end-call', showHangup);

        this.setCallControlsVisible(onActiveCall, {
            showRecord: onActiveCall,
            showEndCall: onActiveCall,
            showHold: state === 'in-call' && Boolean(this.morpheusCallUuid),
            showMute: onActiveCall,
            showTransfer: state === 'in-call' && Boolean(this.morpheusCallUuid),
            showAnswer: state === 'ringing' && !outboundActive,
        });

        if (onActiveCall) {
            this.refreshDialerCallOverlay(state);
        }

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
                    this.activeWssUrl = wssUrl;
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

        const iceServers = this.buildIceServers();

        this.userAgent = new UserAgent({
            uri,
            transportOptions: {
                server: wssUrl,
                connectionTimeout: 15,
            },
            contactParams: {
                transport: 'ws',
            },
            contactName: String(this.config.extension || sipUser || ''),
            authorizationUsername: this.config.auth_user,
            authorizationPassword: this.config.password,
            displayName: this.sanitizeDisplayName(this.config),
            sessionDescriptionHandlerFactoryOptions: {
                constraints: buildWebphoneMediaConstraints(),
                peerConnectionConfiguration: {
                    iceServers,
                    iceCandidatePoolSize: 10,
                },
            },
        });

        this.userAgent.delegate = {
            onInvite: (invitation) => this.handleInvite(invitation),
            onNotify: (notification) => {
                // Morpheus sends NOTIFY (voicemail-summary, dialog). Must ACK 200 — 481 breaks call setup.
                const accept = () => {
                    if (typeof notification?.accept === 'function') {
                        notification.accept({ statusCode: 200, reasonPhrase: 'OK' });
                        return true;
                    }
                    const req = notification?.incomingNotifyRequest ?? notification?.request;
                    if (typeof req?.accept === 'function') {
                        req.accept({ statusCode: 200, reasonPhrase: 'OK' });
                        return true;
                    }
                    return false;
                };
                try {
                    accept();
                } catch {
                    // SIP.js may auto-handle on next tick.
                    window.setTimeout(() => {
                        try {
                            accept();
                        } catch {
                            // Ignore — non-fatal.
                        }
                    }, 0);
                }
            },
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
        const registerDomain = this.config?.domain;
        const extension = this.config?.extension || this.currentExtension;
        const callerId = this.config?.outbound_caller_id;

        if (registerDomain && extension) {
            headers.push(`P-Preferred-Identity: <sip:${extension}@${registerDomain}>`);
        }

        if (registerDomain && callerId) {
            const digits = String(callerId).replace(/\D/g, '');
            if (digits) {
                headers.push(`P-Asserted-Identity: <sip:${digits}@${registerDomain}>`);
                headers.push(`Remote-Party-ID: <sip:${digits}@${registerDomain}>;party=calling;privacy=off;screen=no`);
            }
        }

        const campaignId = this.config?.campaign_id;
        if (campaignId) {
            headers.push(`X-Campaign-ID: ${campaignId}`);
        }

        return headers;
    }

    waitForOutboundInviteProgress(inviter, timeoutSec = 12) {
        return new Promise((resolve, reject) => {
            let settled = false;

            const finish = (fn, value) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                inviter.stateChange.removeListener(onState);
            };

            const onState = (state) => {
                if (state === SessionState.Established) {
                    finish(resolve, 'answered');
                } else if (state === SessionState.Terminated) {
                    const detail =
                        this.lastInviteReject ||
                        this.outboundTerminateDetail ||
                        (this.sawOutboundRinging ? 'no answer' : 'before ringing');
                    finish(reject, new Error(detail));
                }
            };

            if (inviter.state === SessionState.Established) {
                resolve('answered');

                return;
            }

            if (inviter.state === SessionState.Terminated) {
                reject(
                    new Error(
                        this.lastInviteReject || this.outboundTerminateDetail || 'Call ended before ringing.',
                    ),
                );

                return;
            }

            const timer = setTimeout(() => {
                if (this.sawOutboundRinging) {
                    finish(resolve, 'ringing');
                } else {
                    finish(
                        reject,
                        new Error(
                            this.lastInviteReject ||
                                'No ringing response from Morpheus yet — check Socket tab INVITE.',
                        ),
                    );
                }
            }, timeoutSec * 1000);

            inviter.stateChange.addListener(onState);
        });
    }

    buildTargetUri(destination) {
        const normalized = normalizeDialTarget(destination);
        if (!normalized || !this.config) {
            return null;
        }

        const prefix = this.config.outbound_prefix || '';
        let dialString = `${prefix}${normalized}`;
        if (!isExtensionTarget(dialString)) {
            dialString = dialString.replace(/^\+/, '');
        }
        const host = this.config.dial_domain || this.config.domain;
        if (!host) {
            return null;
        }

        const sipTarget = isExtensionTarget(dialString)
            ? `sip:${dialString}@${host}`
            : `sip:${dialString}@${host}${
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
        this._callEndedDispatched = false;
        this._monitoringHangupNotified = false;
        this._finalizeAfterByeInFlight = false;
        this.userInitiatedHangup = false;
        this.remoteHangupHandled = false;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this.directDialActive = true;
        this.sawOutboundRinging = false;
        this.outboundTerminateDetail = '';
        this.suppressTerminateToast = false;
        this.lastInviteReject = '';
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
            timerLabel: 'Ringing time',
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });
        this.startRingTimer();
        this.setState('dialing');

        const targetHost = this.config.dial_domain || this.config.domain;
        const dialPreview = `sip:${String(normalized).replace(/^\+/, '')}@${targetHost}`;

        this.outboundDialInProgress = true;

        try {
            await inviter.invite({
                requestDelegate: {
                    onTrying: () => {
                        this.outboundTerminateDetail = '';
                    },
                    onProgress: (response) => {
                        const code = response?.message?.statusCode || 0;
                        if (code === 180 || code === 183) {
                            this.sawOutboundRinging = true;
                            this.outboundTerminateDetail = '';
                        }
                    },
                    onReject: (response) => {
                        const code = response?.message?.statusCode || 0;
                        const reason = response?.message?.reasonPhrase || 'rejected';
                        const q850 = String(response?.message?.getHeader?.('Reason') || '');
                        const routeHint = q850.includes('NO_ROUTE_DESTINATION')
                            ? ' — use click-to-call API, not browser PSTN INVITE'
                            : '';
                        this.lastInviteReject = `${code} ${reason}${routeHint}`.trim();
                        this.outboundTerminateDetail = this.lastInviteReject;
                    },
                },
                requestOptions: {
                    extraHeaders: this.buildInviteExtraHeaders(),
                },
                sessionDescriptionHandlerOptions: {
                    constraints: buildWebphoneMediaConstraints(),
                },
            });

            await this.waitForOutboundInviteProgress(
                inviter,
                Math.min(15, this.config?.ring_timeout_sec || 12),
            );

            return true;
        } catch (error) {
            this.suppressTerminateToast = true;
            this.stopRingback();
            hideLoadingOverlay();
            this.clearSession();

            const detail = error instanceof Error ? error.message : 'Could not place the call.';
            const hint = detail.includes('ringing') || detail.includes('407') || detail.includes('403')
                ? ` (${dialPreview})`
                : '';

            throw new Error(`${detail}${hint}`);
        } finally {
            this.outboundDialInProgress = false;
        }
    }

    handleInvite(invitation) {
        if (this.directDialActive && !this.pendingClickToCall && !this.clickToCallActive) {
            invitation.reject({ statusCode: 486, reasonPhrase: 'Busy Here' }).catch(() => {});

            return;
        }

        if (this.directDialActive) {
            this.directDialActive = false;
            this.stopRingback();
        }

        this.session = invitation;
        const caller = invitation.remoteIdentity?.uri?.user || 'Unknown';
        const isOutboundLeg = this.pendingClickToCall || this.clickToCallActive;

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
                timerLabel: 'Ringing time',
                showAnswer: false,
                showHangup: true,
                showRecord: true,
                state: 'dialing',
            });
            this.startRingTimer();
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
                timerLabel: 'Ringing time',
                showAnswer: true,
                showHangup: true,
                state: 'ringing',
            });
            this.startRingTimer();
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
            // Keep pendingClickToCall until SIP Established so the leg stays classified
            // as click-to-call (destination dial starts only after agent answers).

            if (this.config?.auto_answer_click_to_call) {
                const tryAnswer = () => {
                    this.answer().catch(() => {
                        window.setTimeout(() => {
                            this.answer().catch(() => {
                                showToast('Could not auto-connect your line — click Answer, or End and try again.', 'warning');
                            });
                        }, 500);
                    });
                };
                this.ensureAudioContext()
                    .catch(() => {})
                    .finally(tryAnswer);

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

                const isTrackedOriginatedOutbound =
                    this.currentCallDirection === 'outbound'
                    && !this.directDialActive
                    && (
                        Boolean(this.activeCallUuid())
                        || this.awaitingDestinationBridge
                        || this.clickToCallActive
                    );

                if (isTrackedOriginatedOutbound && this.state === 'in-call') {
                    this.attachRemoteAudio(session);

                    return;
                }

                if (isTrackedOriginatedOutbound && this.awaitingDestinationBridge) {
                    this.clickToCallActive = true;
                    this.pendingClickToCall = false;
                    this.agentLegEstablishedAt = Date.now();
                    this._lastSeenPstnBillsec = 0;
                    this.stopRingtone();
                    this.startRingback();
                    const routeDetail = this.buildCallRouteDetail(this.currentCallPeer);
                    const cellRingCopy = this.currentCallPeer
                        ? `Your line is connected — ringing ${this.currentCallPeer} now. Stay on this tab.`
                        : 'Your line is connected — ringing the destination now. Stay on this tab.';
                    this.updateCallCard({
                        title: 'Ringing destination',
                        subtitle: cellRingCopy,
                        detail: routeDetail,
                        visible: true,
                    });
                    this.updateFloatingPopup({
                        title: 'Ringing destination',
                        subtitle: cellRingCopy,
                        detail: routeDetail,
                        visible: true,
                        statusLabel: 'Ringing',
                        showRingingVisual: true,
                        showConnectedTimer: false,
                        timerLabel: 'Ringing time',
                        showAnswer: false,
                        showHangup: true,
                        showRecord: true,
                        state: 'dialing',
                    });
                    this.setState('dialing');
                    this.startRingTimer();
                    this.startDestinationPoll();
                    this.attachRemoteAudio(session);
                    this.setRemoteAudioMuted(true);
                    this.startRemoteAnswerWatcher(session);

                    return;
                }

                if (isTrackedOriginatedOutbound && !this.awaitingDestinationBridge) {
                    this.markDestinationConnected({ source: 'sip-established' });
                    this.attachRemoteAudio(session);

                    return;
                }

                // Direct dial / inbound answer: SIP Established means both sides are up.
                this.enterBothSidesConnected({ source: 'sip-established-direct' });
                this.attachRemoteAudio(session);
            }

            if (state === SessionState.Terminated) {
                const wasOutboundRinging =
                    this.currentCallDirection === 'outbound' &&
                    (this.state === 'dialing' || this.awaitingDestinationBridge || this.clickToCallActive);
                const statusUuid = this.hangupCallUuid();
                const peer = this.currentCallPeer || this.lastDialedDestination || '';
                const stillRegistered = this.registerer?.state === RegistererState.Registered;
                const clickToCallDrop =
                    wasOutboundRinging &&
                    !this.directDialActive &&
                    stillRegistered &&
                    (this.clickToCallActive || this.awaitingDestinationBridge) &&
                    statusUuid &&
                    !this.userInitiatedHangup;

                this.stopRingback();

                if (clickToCallDrop) {
                    // Keep Ringing UI — Morpheus may still be dialing the lead.
                    void this.finalizeClickToCallAfterBye(statusUuid, peer);
                } else if (wasOutboundRinging && !this.directDialActive && !this.userInitiatedHangup) {
                    const digits = String(peer || '').replace(/\D/g, '');

                    if (statusUuid && (this.clickToCallActive || this.awaitingDestinationBridge || digits.length >= 10)) {
                        void this.finalizeClickToCallAfterBye(statusUuid, peer);
                    } else if (this.clickToCallActive || this.awaitingDestinationBridge) {
                        // Agent leg dropped early without a uuid yet — keep ringing chrome.
                        this.refreshOutboundRingingUi(peer);
                    } else {
                        this.flushCallUiInstant();
                        this._pendingCallEndMeta = {
                            connected: false,
                            result: 'No answer',
                            durationSec: 0,
                        };
                        if (!usesCallSummaryFlow()) {
                            if (digits.length >= 10) {
                                showCommToast(
                                    humanCallEndMessage('NO_ANSWER', { outbound: true }),
                                    'warning',
                                );
                            } else {
                                showCommToast('Call ended before the destination answered.', 'warning');
                            }
                        }
                        this.clearSession({ emitEnded: true });
                    }
                } else if (wasOutboundRinging && this.directDialActive) {
                    if (this.suppressTerminateToast || this.outboundDialInProgress) {
                        this.suppressTerminateToast = false;
                    } else if (!usesCallSummaryFlow()) {
                        const rejectDetail = this.lastInviteReject || this.outboundTerminateDetail;
                        const detailSuffix = rejectDetail ? ` (${rejectDetail})` : '';
                        const message = this.sawOutboundRinging
                            ? humanCallEndMessage('NO_ANSWER', { outbound: true })
                            : 'Call ended before the destination answered.';
                        showCommToast(message, 'warning');
                    }
                }

                if (statusUuid && !this.userInitiatedHangup && !clickToCallDrop) {
                    // Never hang up Morpheus while the destination is still supposed to ring.
                    if (this.awaitingDestinationBridge || this.clickToCallActive) {
                        void this.finalizeClickToCallAfterBye(statusUuid, peer);

                        return;
                    }

                    if (this.state === 'in-call') {
                        void this.handleRemotePartyHangup(
                            { hangup_cause: 'NORMAL_CLEARING' },
                            { source: 'sip-terminated' },
                        );

                        return;
                    }

                    void this.hangupMorpheusCall(statusUuid).finally(() => {
                        this.clearSession({ emitEnded: true });
                    });

                    return;
                }

                if (!clickToCallDrop && !(this.awaitingDestinationBridge || this.clickToCallActive)) {
                    this.clearSession({ emitEnded: true });
                }
            }
        });
    }

    /**
     * STUN + optional TURN (Morpheus/TURN credentials from prepare-webphone config).
     * STUN alone fails on many NAT/firewall networks — configure MORPHEUS_TURN_* when available.
     */
    buildIceServers() {
        const servers = [];
        const stunList = Array.isArray(this.config?.stun_servers) ? this.config.stun_servers : [];
        stunList.forEach((url) => {
            const trimmed = String(url || '').trim();
            if (trimmed) {
                servers.push({ urls: trimmed });
            }
        });

        const turnList = Array.isArray(this.config?.turn_urls) ? this.config.turn_urls : [];
        const username = String(this.config?.turn_username || '').trim();
        const credential = String(this.config?.turn_credential || '').trim();
        turnList.forEach((url) => {
            const trimmed = String(url || '').trim();
            if (!trimmed) {
                return;
            }
            const entry = { urls: trimmed };
            if (username !== '') {
                entry.username = username;
                entry.credential = credential;
            }
            servers.push(entry);
        });

        if (servers.length === 0) {
            servers.push({ urls: 'stun:stun.l.google.com:19302' });
        }

        return servers;
    }

    clearWebrtcRecoverTimer() {
        if (this._webrtcRecoverTimer) {
            window.clearTimeout(this._webrtcRecoverTimer);
            this._webrtcRecoverTimer = null;
        }
    }

    /**
     * Do not hang up on a brief WebRTC "disconnected" blip (Wi-Fi hiccup).
     * Wait, try ICE restart once, then treat sustained failed/closed as remote hangup.
     */
    attachWebRtcConnectionRecovery(pc) {
        const shouldConsiderLive = () => (
            this.state === 'in-call'
            && !this.awaitingDestinationBridge
            && !this.hangupInFlight
            && !this.remoteHangupHandled
            && this.session?.sessionDescriptionHandler?.peerConnection === pc
        );

        const endFromWebrtc = (source) => {
            if (!shouldConsiderLive()) {
                return;
            }
            void this.handleRemotePartyHangup(
                { hangup_cause: 'NORMAL_CLEARING' },
                { source },
            );
        };

        const tryIceRestart = () => {
            try {
                if (typeof pc.restartIce === 'function') {
                    pc.restartIce();
                    this.logPhone('info', 'WebRTC ICE restart requested');
                    return true;
                }
            } catch (error) {
                this.logPhone('warn', 'WebRTC ICE restart failed', {
                    message: error?.message || String(error),
                });
            }

            return false;
        };

        const scheduleRecoverOrEnd = () => {
            this.clearWebrtcRecoverTimer();
            this._webrtcRecoverTimer = window.setTimeout(() => {
                this._webrtcRecoverTimer = null;
                if (!shouldConsiderLive()) {
                    return;
                }

                const ice = pc.iceConnectionState;
                const conn = pc.connectionState;
                if (
                    conn === 'connected'
                    || ice === 'connected'
                    || ice === 'completed'
                ) {
                    return;
                }

                if (!this._webrtcIceRestarted && (conn === 'failed' || ice === 'failed' || conn === 'disconnected' || ice === 'disconnected')) {
                    this._webrtcIceRestarted = true;
                    tryIceRestart();
                    this._webrtcRecoverTimer = window.setTimeout(() => {
                        this._webrtcRecoverTimer = null;
                        if (!shouldConsiderLive()) {
                            return;
                        }
                        const ice2 = pc.iceConnectionState;
                        const conn2 = pc.connectionState;
                        if (
                            conn2 === 'connected'
                            || ice2 === 'connected'
                            || ice2 === 'completed'
                        ) {
                            this._webrtcIceRestarted = false;
                            return;
                        }
                        endFromWebrtc(
                            conn2 === 'closed' ? 'webrtc-closed' : 'webrtc-recover-failed',
                        );
                    }, WEBRTC_DISCONNECT_GRACE_MS);
                    return;
                }

                endFromWebrtc(
                    conn === 'closed' ? 'webrtc-closed' : 'webrtc-recover-failed',
                );
            }, WEBRTC_DISCONNECT_GRACE_MS);
        };

        pc.addEventListener('connectionstatechange', () => {
            const connectionState = pc.connectionState;
            this.logPhone('info', 'WebRTC connection state', { connectionState });

            if (connectionState === 'connected') {
                this.clearWebrtcRecoverTimer();
                this._webrtcIceRestarted = false;
                return;
            }

            if (!shouldConsiderLive()) {
                return;
            }

            if (connectionState === 'disconnected') {
                showCommToast('Connection interrupted. Recovering…', 'warning');
                scheduleRecoverOrEnd();
                return;
            }

            if (connectionState === 'failed') {
                showCommToast('Connection failed. Reconnecting…', 'warning');
                scheduleRecoverOrEnd();
                return;
            }

            if (connectionState === 'closed') {
                scheduleRecoverOrEnd();
            }
        });

        pc.addEventListener('iceconnectionstatechange', () => {
            const iceConnectionState = pc.iceConnectionState;
            this.logPhone('info', 'WebRTC ICE state', { iceConnectionState });

            if (
                iceConnectionState === 'connected'
                || iceConnectionState === 'completed'
            ) {
                this.clearWebrtcRecoverTimer();
                this._webrtcIceRestarted = false;
                return;
            }

            if (!shouldConsiderLive()) {
                return;
            }

            if (
                iceConnectionState === 'failed'
                || iceConnectionState === 'disconnected'
            ) {
                scheduleRecoverOrEnd();
            }
        });

        pc.addEventListener('icecandidateerror', (event) => {
            this.logPhone('warn', 'ICE candidate error', {
                errorCode: event.errorCode,
                errorText: event.errorText,
                url: event.url,
            });
        });
    }

    attachRemoteAudio(session) {
        const remoteAudio = this.ui?.remoteAudio;
        const pc = session?.sessionDescriptionHandler?.peerConnection;
        if (!remoteAudio || !pc) {
            return;
        }

        const bindStream = (stream) => {
            if (!stream) {
                return;
            }

            remoteAudio.srcObject = stream;
            remoteAudio.volume = REMOTE_PLAYBACK_VOLUME;
            // Keep remote muted while ringing / awaiting destination — prevents
            // speaker→microphone acoustic echo that the destination hears.
            const keepLocalRing =
                Boolean(this.ringtoneInterval)
                || Boolean(this.ringbackInterval)
                || this.outboundWaitingActive
                || this.awaitingDestinationBridge
                || this.state === 'dialing'
                || this.state === 'ringing'
                || this.timerPhase !== 'connected';
            remoteAudio.muted = keepLocalRing;
            remoteAudio.autoplay = true;
            if (!keepLocalRing) {
                remoteAudio.play().catch(() => {});
            }
            void this.reinforceAntiEcho();
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0] || new MediaStream([event.track]);
            bindStream(stream);
            // Do not stop local ringers on early media — that caused a gap or
            // double tone when carrier ringback also arrived. Local ringers stop
            // when the call is established / answered.
            if (this.awaitingDestinationBridge) {
                this.startRemoteAnswerWatcher(session);
                this.startLiveTrackWatcher(session);
            }
        };

        if (!pc._commHubHangupBound) {
            pc._commHubHangupBound = true;
            this.attachWebRtcConnectionRecovery(pc);
        }

        const stream = new MediaStream();
        pc.getReceivers().forEach((receiver) => {
            if (receiver.track) {
                stream.addTrack(receiver.track);
            }
        });
        if (stream.getTracks().length > 0) {
            bindStream(stream);
            // Keep local ringback while destination is still ringing; stop only
            // when enterBothSidesConnected / markDestinationConnected runs.
            if (!this.awaitingDestinationBridge && this.state === 'in-call') {
                this.stopAllLocalRingers();
            }
            if (this.awaitingDestinationBridge) {
                this.startRemoteAnswerWatcher(session);
                this.startLiveTrackWatcher(session);
            }
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
                constraints: buildWebphoneMediaConstraints(),
            },
            sessionDescriptionHandlerModifiers: [
                (description) => {
                    description.sdp = description.sdp.replace(/^(m=video )\d+(.*)$/gm, '$10$2');
                    return Promise.resolve(description);
                },
            ],
        });
        this.ensureOutboundMicLive({ soft: true });
    }

    async hangup(reason = 'unknown') {
        if (this.hangupInFlight) {
            return;
        }
        this.hangupInFlight = true;

        // Snapshot BEFORE UI flush so destination/extension/uuids stay available for kill.
        const endedPhone = this.currentCallPeer || this.lastDialedDestination || '';
        const extension = selectedExtension() || this.currentExtension || '';
        const morpheusUuid = this.hangupCallUuid();
        const relatedUuids = [
            ...new Set(
                [
                    morpheusUuid,
                    this.bridgedCallUuid,
                    this.originateCallUuid,
                    this.morpheusCallUuid,
                    this.pstnPollUuid,
                    ...this.trackedCallUuids,
                ]
                    .filter(Boolean)
                    .map(String),
            ),
        ];
        const bridgedUuid = this.bridgedCallUuid || null;
        const session = this.session;
        const wasLiveCall =
            this.state === 'dialing' || this.state === 'ringing' || this.state === 'in-call';
        const wasConnected = this.timerPhase === 'connected' || this.state === 'in-call' || this._serverConfirmedDestination;
        const durationSec = wasConnected && this.callStartedAt && this.timerPhase === 'connected'
            ? Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000))
            : 0;
        const endedUuid = morpheusUuid || '';

        this.userInitiatedHangup = true;
        this.flushCallUiInstant();
        this._pendingCallEndMeta = {
            connected: wasConnected,
            result: wasConnected ? 'Connected' : 'No answer',
            durationSec,
        };
        // Show disposition popup immediately on hangup (before API/SIP cleanup).
        this.dispatchCallEndedOnce({ phone: endedPhone, callUuid: endedUuid });
        // Clear Call Monitoring in real time (before awaiting Morpheus hangup HTTP).
        this.notifyMonitoringHangup({ phone: endedPhone, callUuid: endedUuid, extension });

        this.logPhone('info', 'Hangup requested', {
            reason,
            originateCallUuid: morpheusUuid,
            bridgedCallUuid: bridgedUuid,
            sessionState: session?.state ?? null,
            peer: endedPhone,
            relatedUuids,
        });

        this.stopDestinationPoll();
        this.stopRemoteAnswerWatcher();
        this.stopAllLocalRingers();

        // Kill destination + agent legs in parallel — do not wait for Morpheus before SIP bye.
        await Promise.allSettled([
            this.endLocalSipSession(session),
            this.killDestinationLegsNow({
                uuids: relatedUuids,
                destination: endedPhone,
                extension,
                bridgedUuid,
            }),
        ]);

        this.pendingClickToCall = false;
        this.hangupInFlight = false;
        if (wasLiveCall && !usesCallSummaryFlow()) {
            showCommToast('Call ended.', 'info');
        }
        this.clearSession();
    }

    /**
     * End local WebRTC/SIP leg immediately (agent side).
     */
    async endLocalSipSession(session = this.session) {
        if (!session) {
            return;
        }

        try {
            const state = session.state;
            if (state === SessionState.Initial || state === SessionState.Establishing) {
                if (session instanceof Inviter) {
                    await session.cancel();
                } else {
                    await session.reject();
                }
            } else if (state !== SessionState.Terminating && state !== SessionState.Terminated) {
                await session.bye();
            }
        } catch (error) {
            this.logPhone('warn', 'Local SIP hangup failed', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    /**
     * Instant PSTN/destination teardown: hangup every known UUID + release by destination.
     * Fire-and-forget friendly with keepalive so the far end drops even if UI navigates.
     */
    async killDestinationLegsNow({
        uuids = [],
        destination = '',
        extension = '',
        bridgedUuid = null,
    } = {}) {
        const tasks = [];

        const uniqueUuids = [...new Set((uuids || []).filter(Boolean).map(String))];
        for (const uuid of uniqueUuids) {
            tasks.push(this.hangupMorpheusCall(uuid, {
                destination,
                extension,
                bridgedUuid,
                relatedUuids: uniqueUuids,
            }));
        }

        if (uniqueUuids.length === 0 && destination) {
            // No UUID yet (ringing) — still release by destination/extension.
            tasks.push(this.releaseExtensionCallsNow(extension, destination));
        } else {
            tasks.push(this.releaseExtensionCallsNow(extension, destination));
        }

        await Promise.allSettled(tasks);
    }

    async releaseExtensionCallsNow(extension, destination) {
        const url = this.releaseExtensionUrl();
        const ext = String(extension || selectedExtension() || this.currentExtension || '').trim();
        if (!url || !ext) {
            return;
        }

        try {
            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const timeout = window.setTimeout(() => {
                try {
                    controller?.abort('timeout');
                } catch {
                    try {
                        controller?.abort();
                    } catch {
                        // ignore
                    }
                }
            }, 12000);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                keepalive: true,
                signal: controller?.signal,
                body: JSON.stringify({
                    from_extension: ext,
                    destination: destination || this.currentCallPeer || this.lastDialedDestination || '',
                }),
            });
            window.clearTimeout(timeout);
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                this.logPhone('warn', 'Extension release API returned non-OK', {
                    extension: ext,
                    status: response.status,
                    data,
                });
            } else {
                this.logPhone('info', 'Extension + destination release OK', {
                    extension: ext,
                    destination,
                    hungup: data.hungup || [],
                });
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            const aborted = /aborted/i.test(message) || (error && error.name === 'AbortError');
            this.logPhone(
                aborted ? 'info' : 'warn',
                aborted ? 'Extension release timed out' : 'Extension release API failed',
                { extension: ext, error: message },
            );
        }
    }

    async releaseExtensionCalls() {
        await this.releaseExtensionCallsNow(
            selectedExtension() || this.currentExtension || '',
            this.currentCallPeer || this.lastDialedDestination || '',
        );
    }

    async hangupAllMorpheusLegs(primaryUuid) {
        const uuids = [
            ...new Set(
                [primaryUuid, this.bridgedCallUuid, this.originateCallUuid, this.morpheusCallUuid, this.pstnPollUuid, ...this.trackedCallUuids]
                    .filter(Boolean)
                    .map(String),
            ),
        ];

        // Parallel hangups — sequential awaits stacked AbortController timeouts.
        await Promise.allSettled(uuids.map((uuid) => this.hangupMorpheusCall(uuid)));
    }

    async hangupMorpheusCall(uuid, {
        destination = null,
        extension = null,
        bridgedUuid = null,
        relatedUuids = null,
    } = {}) {
        const template = this.hangupUrlTemplate();
        const callUuid = String(uuid || this.hangupCallUuid() || '').trim();
        if (!template || !callUuid) {
            this.logPhone('warn', 'No originate call_uuid for Morpheus hangup', {
                morpheusCallUuid: this.morpheusCallUuid,
                originateCallUuid: this.originateCallUuid,
            });

            return false;
        }

        const url = template.replace('__UUID__', encodeURIComponent(callUuid));
        const dest = destination
            ?? this.currentCallPeer
            ?? this.lastDialedDestination
            ?? '';
        const fromExt = extension
            ?? selectedExtension()
            ?? this.currentExtension
            ?? '';
        const related = Array.isArray(relatedUuids)
            ? relatedUuids
            : Array.from(this.trackedCallUuids);

        try {
            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            const timeout = window.setTimeout(() => {
                try {
                    controller?.abort('timeout');
                } catch {
                    try {
                        controller?.abort();
                    } catch {
                        // ignore
                    }
                }
            }, 12000);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                keepalive: true,
                signal: controller?.signal,
                body: JSON.stringify({
                    from_extension: fromExt,
                    destination: dest,
                    originate_uuid: callUuid,
                    bridged_uuid: bridgedUuid ?? this.bridgedCallUuid ?? null,
                    related_uuids: related,
                }),
            });
            window.clearTimeout(timeout);

            const data = await response.json().catch(() => ({}));
            const ok = response.ok && data.ok === true;

            this.logPhone(ok ? 'info' : 'warn', ok ? 'Morpheus hangup OK' : 'Morpheus hangup failed', {
                callUuid,
                destination: dest,
                status: response.status,
                hungup: data.hungup || [],
                data,
            });

            return ok;
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            const aborted =
                (error && typeof error === 'object' && error.name === 'AbortError')
                || /aborted/i.test(message);
            // UI already ended the call — timeout/abort must not look like a broken hangup.
            this.logPhone(
                aborted ? 'info' : 'warn',
                aborted ? 'Morpheus hangup request timed out (call already ended in UI)' : 'Morpheus hangup request failed',
                {
                    callUuid,
                    destination: dest,
                    error: message,
                },
            );

            return aborted;
        }
    }

    dispatchCallEndedOnce({ phone = '', callUuid = '', result = '', connected = null, durationSec = null } = {}) {
        if (this._callEndedDispatched) {
            return;
        }

        const endedPhone = String(phone || this.currentCallPeer || this.lastDialedDestination || '').trim();
        const endedUuid = String(callUuid || this.hangupCallUuid() || this.originateCallUuid || this.morpheusCallUuid || '').trim();

        // Prefer phone/uuid when present; still fire so Call Summary / disposition can open.
        if (!endedPhone && !endedUuid && !usesCallSummaryFlow()) {
            return;
        }

        const pending = this._pendingCallEndMeta || {};
        this._pendingCallEndMeta = null;
        const wasConnected = connected ?? pending.connected ?? (
            this.timerPhase === 'connected' || this._serverConfirmedDestination
        );
        const resolvedDuration = Number.isFinite(durationSec)
            ? Math.max(0, Number(durationSec))
            : (Number.isFinite(pending.durationSec)
                ? Math.max(0, Number(pending.durationSec))
                : (wasConnected && this.callStartedAt && this.timerPhase === 'connected'
                    ? Math.max(0, Math.floor((Date.now() - this.callStartedAt) / 1000))
                    : 0));
        const callResult = String(result || pending.result || '').trim()
            || (wasConnected ? 'Connected' : 'No answer');

        this._callEndedDispatched = true;
        const extension = selectedExtension() || this.currentExtension || '';
        const detail = {
            phone: endedPhone,
            callUuid: endedUuid,
            extension,
            result: callResult,
            connected: wasConnected,
            durationSec: wasConnected ? resolvedDuration : 0,
        };
        window.dispatchEvent(new CustomEvent('comm:call-ended', { detail }));
        window.dispatchEvent(new CustomEvent('comm:monitoring-hangup', { detail }));
        try {
            const channel = new BroadcastChannel('apex-call-monitoring');
            channel.postMessage({ type: 'call-ended', ...detail });
            channel.close();
        } catch {
            // BroadcastChannel unsupported — monitoring still polls.
        }
    }

    /**
     * Instantly clear Call Monitoring live state (no Morpheus hangup wait).
     */
    notifyMonitoringHangup({ phone = '', callUuid = '', extension = '' } = {}) {
        if (this._monitoringHangupNotified) {
            return;
        }

        const uuid = String(callUuid || this.hangupCallUuid() || this.originateCallUuid || this.morpheusCallUuid || '').trim();
        const destination = phone || this.currentCallPeer || this.lastDialedDestination || '';
        const ext = String(extension || selectedExtension() || this.currentExtension || '').trim();
        const related = [...this.trackedCallUuids].map(String).filter(Boolean);

        this._monitoringHangupNotified = true;

        const detail = { phone: destination, callUuid: uuid, extension: ext, relatedUuids: related };
        window.dispatchEvent(new CustomEvent('comm:monitoring-hangup', { detail }));
        try {
            const channel = new BroadcastChannel('apex-call-monitoring');
            channel.postMessage({ type: 'call-ended', ...detail });
            channel.close();
        } catch {
            // ignore
        }

        const template = this.panel?.dataset?.callEndedUrl
            || this.callActionUrlTemplate('callEnded')
            || '';
        if (!uuid || !template) {
            // Still try extension release path with any uuid placeholder if needed later.
            return;
        }

        const url = template.replace('__UUID__', encodeURIComponent(uuid));
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timeoutId = window.setTimeout(() => {
            try {
                controller?.abort();
            } catch {
                // ignore
            }
        }, 4000);

        void fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            signal: controller?.signal,
            body: JSON.stringify({
                from_extension: extension,
                destination,
                related_uuids: related,
                hangup_cause: 'NORMAL_CLEARING',
            }),
        }).catch(() => {
            // Hangup UI proceeds even if monitoring clear request fails.
        }).finally(() => {
            window.clearTimeout(timeoutId);
        });
    }

    clearSession({ emitEnded = true } = {}) {
        const endedPhone = this.currentCallPeer || this.lastDialedDestination || '';
        const endedUuid = this.hangupCallUuid();
        if (emitEnded) {
            this.dispatchCallEndedOnce({ phone: endedPhone, callUuid: endedUuid || '' });
        }

        const callEndedAlready = this._callEndedDispatched;

        this.session = null;
        this.clearWebrtcRecoverTimer();
        this._webrtcIceRestarted = false;
        this.stopAllLocalRingers();
        this.stopAntiEchoKeepalive();
        this.stopOutboundSpeechGate();
        this.releaseLocalMicStream();
        this.stopRemoteAnswerWatcher();
        this.stopDestinationPoll();
        this.stopCallEventsStream();
        hideLoadingOverlay();
        this.stopCallTimer();
        this.recordingActive = false;
        this.updateRecordingUi();
        this.setCallContext(null, '');
        this.morpheusCallUuid = null;
        this.originateCallUuid = null;
        this.pstnPollUuid = null;
        this.bridgedCallUuid = null;
        this.trackedCallUuids.clear();
        this.pendingClickToCall = false;
        this.clickToCallActive = false;
        this.awaitingDestinationBridge = false;
        this._lastSeenPstnBillsec = 0;
        this._statusPollInFlight = false;
        this._statusPollAbort = null;
        this._lastInboundAudioPackets = 0;
        this._rtpGrowthFrames = 0;
        this._serverConfirmedDestination = false;
        this._reportedDestinationConnected = null;
        this._connectedAtSyncScheduled = false;
        this._lastConnectedAtSyncAt = 0;
        // Keep disposition guard until the next outbound dial starts (prevents double popup).
        this._callEndedDispatched = callEndedAlready;
        this._monitoringHangupNotified = false;
        this._ringNoAnswerTimeoutFired = false;
        this.agentLegEstablishedAt = 0;
        this.hangupInFlight = false;
        this.userInitiatedHangup = false;
        this.remoteHangupHandled = false;
        this.lastDialedDestination = '';
        this.directDialActive = false;
        this.callOnHold = false;
        this.updateHoldUi();
        this.micMuted = false;
        this.updateMuteUi();

        this.updateCallCard({ visible: false });
        this.updateFloatingPopup({ visible: false });
        this.restoreDialerAfterCall();

        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
            this.ui.remoteAudio.muted = true;
            this.ui.remoteAudio.volume = REMOTE_PLAYBACK_VOLUME;
        }

        if (this.ui?.bridgePanel && !this.ui.bridgePanel.classList.contains('hidden')) {
            this.setState('registered', 'Embedded phone');
            this.restoreDialerAfterCall();

            return;
        }

        if (this.registerer?.state === RegistererState.Registered) {
            this.setState('registered');
        } else {
            this.setState('offline');
        }
        this.restoreDialerAfterCall();
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
