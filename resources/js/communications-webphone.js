import { Inviter, Registerer, RegistererState, SessionState, UserAgent } from 'sip.js';
import { hideLoadingOverlay } from './form-loading.js';
import { showToast, showCommToast } from './toast.js';

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
        this.callOnHold = false;
        this._lastPollSnapshot = '';
        this._lastSeenPstnBillsec = 0;
        this._remoteAnswerWatcher = null;
        this._liveTrackWatcher = null;
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

        this.ui.transferBtn?.addEventListener('click', () => {
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

    stopAllLocalRingers() {
        this.stopRingtone();
        this.stopRingback();
    }

    peerDigits(value) {
        return String(value || '').replace(/\D/g, '');
    }

    destinationMatchesPeer(data) {
        const peerDigits = this.peerDigits(this.currentCallPeer);
        const snapDigits = this.peerDigits(data?.destination_number || data?.to || '');

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
        const hasAnswerTime = Boolean(data.answer_time);

        if (billsec > this._lastSeenPstnBillsec) {
            this._lastSeenPstnBillsec = billsec;
        }

        const destinationConnected =
            data.destination_connected === true
            || data.destination_answered === true
            || data.outcome === 'connected'
            || (remoteState === 'ACTIVE' && data.live !== false && (destMatches || this.awaitingDestinationBridge))
            || (answeredState && (destMatches || this.awaitingDestinationBridge))
            || (this.awaitingDestinationBridge && data.live !== false && billsec >= 1 && !data.hangup_cause)
            || (data.live && destMatches && hasAnswerTime && billsec >= 1)
            || (data.live && destMatches && billsec >= 2 && !data.hangup_cause)
            || (data.live && destMatches && this._lastSeenPstnBillsec >= 2 && !data.hangup_cause);

        if (destinationConnected) {
            this.markDestinationConnected({ source });

            return true;
        }

        return false;
    }

    isRemoteCallEnded(data) {
        if (!data || data.ok === false) {
            return false;
        }

        if (data.call_ended === true || data.outcome === 'ended') {
            return true;
        }

        if (!data.live && data.hangup_cause) {
            const cause = String(data.hangup_cause);
            const billsec = Number(data.billsec ?? 0);
            const elapsed = Date.now() - (this.outboundDialStartedAt || Date.now());

            if (this.state === 'in-call' && !this.awaitingDestinationBridge) {
                return billsec >= 1 || cause === 'NORMAL_CLEARING';
            }

            if (this.awaitingDestinationBridge || this.clickToCallActive) {
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
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    async handleRemotePartyHangup(data = {}, { source = 'poll' } = {}) {
        if (this.hangupInFlight || this.remoteHangupHandled) {
            return;
        }

        this.remoteHangupHandled = true;
        this.userInitiatedHangup = true;

        this.flushCallUiInstant();

        this.logPhone('info', 'Remote party hung up', {
            source,
            hangup_cause: data.hangup_cause ?? null,
            originateCallUuid: this.originateCallUuid,
            state: this.state,
        });

        this.stopDestinationPoll();
        this.stopRemoteAnswerWatcher();
        this.stopAllLocalRingers();

        const morpheusUuid = this.hangupCallUuid();
        if (morpheusUuid) {
            await this.hangupAllMorpheusLegs(morpheusUuid);
        }
        await this.releaseExtensionCalls();

        try {
            if (this.session) {
                const sessionState = this.session.state;
                if (sessionState === SessionState.Initial || sessionState === SessionState.Establishing) {
                    if (this.session instanceof Inviter) {
                        await this.session.cancel();
                    } else {
                        await this.session.reject();
                    }
                } else if (sessionState !== SessionState.Terminating && sessionState !== SessionState.Terminated) {
                    await this.session.bye();
                }
            }
        } catch (error) {
            this.logPhone('warn', 'Local SIP cleanup after remote hangup failed', {
                source,
                error: error instanceof Error ? error.message : String(error),
            });
        }

        if (this.timerPhase === 'connected' || this.state === 'in-call') {
            const cause = data.hangup_cause
                ? String(data.hangup_cause).replace(/_/g, ' ').toLowerCase()
                : '';
            showCommToast(
                cause ? humanCallEndMessage(data.hangup_cause) : 'Other party hung up.',
                'info',
            );
        }

        this.clearSession();
    }

    stopRemoteAnswerWatcher() {
        if (this._remoteAnswerWatcher) {
            window.clearInterval(this._remoteAnswerWatcher);
            this._remoteAnswerWatcher = null;
        }

        this.stopLiveTrackWatcher();

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
        if (!this.awaitingDestinationBridge || this._remoteAnswerWatcher) {
            return;
        }

        const pc = session?.sessionDescriptionHandler?.peerConnection;
        const remoteAudio = this.ui?.remoteAudio;
        if (!pc || !remoteAudio) {
            return;
        }

        const startedAt = Date.now();
        this._voiceEnergyFrames = 0;

        this._remoteAnswerWatcher = window.setInterval(async () => {
            if (!this.awaitingDestinationBridge) {
                this.stopRemoteAnswerWatcher();

                return;
            }

            if (Date.now() - startedAt < 2000) {
                return;
            }

            try {
                const context = await this.ensureAudioContext();
                if (!context) {
                    return;
                }

                const stream = remoteAudio.srcObject;
                if (!(stream instanceof MediaStream) || stream.getAudioTracks().length === 0) {
                    return;
                }

                if (!this._remoteAnswerAnalyser) {
                    this._remoteAnswerSource = context.createMediaStreamSource(stream);
                    this._remoteAnswerAnalyser = context.createAnalyser();
                    this._remoteAnswerAnalyser.fftSize = 512;
                    this._remoteAnswerSource.connect(this._remoteAnswerAnalyser);
                }

                const bins = new Uint8Array(this._remoteAnswerAnalyser.frequencyBinCount);
                this._remoteAnswerAnalyser.getByteTimeDomainData(bins);
                let sum = 0;
                for (let i = 0; i < bins.length; i += 1) {
                    const centered = (bins[i] - 128) / 128;
                    sum += centered * centered;
                }
                const rms = Math.sqrt(sum / bins.length);

                if (rms > 0.02) {
                    this._voiceEnergyFrames += 1;
                    if (this._voiceEnergyFrames >= 4) {
                        this.markDestinationConnected({ source: 'remote-audio' });
                        this.stopRemoteAnswerWatcher();
                    }
                } else {
                    this._voiceEnergyFrames = 0;
                }
            } catch {
                // Keep polling fallback active.
            }
        }, 250);
    }

    startLiveTrackWatcher(session) {
        if (!this.awaitingDestinationBridge || this._liveTrackWatcher) {
            return;
        }

        const startedAt = Date.now();
        this._liveTrackWatcher = window.setInterval(() => {
            if (!this.awaitingDestinationBridge) {
                this.stopLiveTrackWatcher();

                return;
            }

            const pc = session?.sessionDescriptionHandler?.peerConnection;
            const hasLiveAudio = Boolean(
                pc?.getReceivers?.().some(
                    (receiver) => receiver.track?.kind === 'audio' && receiver.track.readyState === 'live',
                ),
            );

            if (!hasLiveAudio) {
                return;
            }

            if (Date.now() - startedAt >= 2000) {
                this.markDestinationConnected({ source: 'live-track' });
                this.stopLiveTrackWatcher();
            }
        }, 400);
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

    setDialerCallControlsVisible(visible, { showRecord = false, showEndCall = false, showAnswer = false, showHold = false, showTransfer = false } = {}) {
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

            if (event.target.closest('[data-dialer-call-transfer]')) {
                event.preventDefault();
                this.promptTransfer().catch((error) => {
                    showToast(error.message || 'Could not transfer call.', 'error');
                });
            }
        });
    }

    setCallControlsVisible(visible, { showRecord = false, showEndCall = false, showAnswer = false, showHold = false, showTransfer = false } = {}) {
        this.ui?.callControls?.classList.toggle('hidden', !visible);
        this.ui?.recordBtn?.classList.toggle('hidden', !showRecord);
        this.ui?.endCallBtn?.classList.toggle('hidden', !showEndCall);
        this.ui?.holdBtn?.classList.toggle('hidden', !showHold);
        this.ui?.transferBtn?.classList.toggle('hidden', !showTransfer);
        this.setDialerCallControlsVisible(visible, { showRecord, showEndCall, showAnswer, showHold, showTransfer });
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
        this.session = null;
        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
        }

        const deadline = Date.now() + 45_000;
        let connected = false;

        while (Date.now() < deadline && uuid) {
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
                        const ringingCopy = peer
                            ? `Your cell ${peer} is ringing — check your phone now.`
                            : 'Destination is ringing — check your phone now.';
                        this.updateCallCard({
                            title: 'Outgoing call',
                            subtitle: ringingCopy,
                            detail: this.buildCallRouteDetail(peer),
                            visible: true,
                        });
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

        if (!connected) {
            await this.showPstnLegFailedToast(uuid, peer);
        }

        this.clearSession();
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
            timerLabel: 'Ringing time',
            showAnswer: false,
            showHangup: true,
            showRecord: true,
            state: 'dialing',
        });
        this.startRingTimer();
        this.setState('dialing');
        this.markClickToCallPending();
        this.startDestinationPoll();
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

                if (this.applyRemoteCallStatus(data, { source: 'webhook-stream' })) {
                    if (data.billsec) {
                        this.syncCallTimerFromBillsec(Number(data.billsec));
                    }
                    if (this.isRemoteCallEnded(data)) {
                        this.stopCallEventsStream();
                    }

                    return;
                }

                if (this.isRemoteCallEnded(data)) {
                    void this.handleRemotePartyHangup(data, { source: 'webhook-stream' });
                    this.stopCallEventsStream();
                }
            };

            source.onerror = () => {
                this.stopCallEventsStream();
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
            btn.textContent = label;
            btn.classList.toggle('is-active', this.callOnHold);
        });
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

        const destination = window.prompt('Transfer to extension or phone number:', '');
        if (!destination || !String(destination).trim()) {
            return;
        }

        const digits = String(destination).replace(/\D/g, '');
        const payload = digits.length <= 6 ? digits : digits;

        await this.postCallAction('transfer', { destination: payload });
        showCommToast(`Call transferred to ${destination}.`, 'info');
        await this.hangup().catch(() => {});
    }

    stopDestinationPoll() {
        this._callMonitorActive = false;
        this.stopCallEventsStream();

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

    scheduleCallMonitor(delayMs = 1200) {
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
            const delayMs = this.state === 'in-call' ? 450 : 550;
            this.scheduleCallMonitor(delayMs);
        }
    }

    async pollDestinationOnce() {
        const uuid = this.activeCallUuid();
        const template = this.callStatusUrlTemplate();

        if (!uuid || !template || !this._callMonitorActive) {
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
                && Number(data.billsec ?? 0) >= 1
                && data.live !== false
                && !data.hangup_cause
            ) {
                this.markDestinationConnected({ source: 'poll-billsec' });

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
            ) {
                const ringingCopy = this.currentCallPeer
                    ? `Your cell ${this.currentCallPeer} is ringing — check your phone now.`
                    : 'Destination is ringing — check your phone now.';
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
        } catch {
            // Keep polling while the SIP leg is still active.
        }
    }

    markDestinationConnected({ source = 'poll' } = {}) {
        const promoting =
            this.awaitingDestinationBridge || this.state === 'dialing' || this.state === 'connecting';

        if (!promoting && this.state === 'in-call') {
            this.stopAllLocalRingers();
            if (!this.callTimer || !this.callStartedAt) {
                this.startCallTimer({ restart: true });
            }

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
        this.stopAllLocalRingers();
        if (!this._callMonitorActive) {
            this.startDestinationPoll();
        }
        // Keep morpheusCallUuid for hold/transfer/hangup until the user ends the call.

        if (this.session) {
            this.attachRemoteAudio(this.session);
        }

        this.startCallTimer({ restart: true });

        this.updateCallCard({
            title: 'Call live',
            subtitle: 'Destination answered — two-way audio is active.',
            detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
            visible: true,
            timer: '00:00',
        });
        this.updateFloatingPopup({
            title: 'Call live',
            subtitle: 'Destination answered — two-way audio is active.',
            detail: this.currentCallPeer ? `To: ${this.currentCallPeer}` : '',
            visible: true,
            timer: '00:00',
            timerLabel: 'Connected time',
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

    async releaseExtensionCalls() {
        const url = this.releaseExtensionUrl();
        const extension = selectedExtension() || this.currentExtension || '';
        if (!url || !extension) {
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    from_extension: extension,
                    destination: this.currentCallPeer || this.lastDialedDestination || '',
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.ok === false) {
                this.logPhone('warn', 'Extension release API returned non-OK', {
                    extension,
                    status: response.status,
                    data,
                });
            }
        } catch (error) {
            this.logPhone('warn', 'Extension release API failed', {
                extension,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    async hangupAllMorpheusLegs(primaryUuid) {
        const uuids = [
            ...new Set(
                [primaryUuid, this.bridgedCallUuid, this.originateCallUuid, this.morpheusCallUuid, this.pstnPollUuid, ...this.trackedCallUuids]
                    .filter(Boolean)
                    .map(String),
            ),
        ];

        for (const uuid of uuids) {
            await this.hangupMorpheusCall(uuid);
        }
    }

    async hangupMorpheusCall(uuid) {
        const template = this.hangupUrlTemplate();
        const callUuid = this.hangupCallUuid() || uuid;
        if (!template || !callUuid) {
            this.logPhone('warn', 'No originate call_uuid for Morpheus hangup', {
                morpheusCallUuid: this.morpheusCallUuid,
                originateCallUuid: this.originateCallUuid,
            });

            return false;
        }

        const url = template.replace('__UUID__', encodeURIComponent(callUuid));

        try {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 8000);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: JSON.stringify({
                    from_extension: selectedExtension() || this.currentExtension || '',
                    destination: this.currentCallPeer || this.lastDialedDestination || '',
                    originate_uuid: callUuid,
                    bridged_uuid: this.bridgedCallUuid || null,
                    related_uuids: Array.from(this.trackedCallUuids),
                }),
            });
            window.clearTimeout(timeout);

            const data = await response.json().catch(() => ({}));
            const ok = response.ok && data.ok === true;

            this.logPhone(ok ? 'info' : 'warn', ok ? 'Morpheus hangup OK' : 'Morpheus hangup failed', {
                callUuid,
                status: response.status,
                data,
            });

            return ok;
        } catch (error) {
            this.logPhone('warn', 'Morpheus hangup request failed', {
                callUuid,
                error: error instanceof Error ? error.message : String(error),
            });

            return false;
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
        this.currentCallPeer = peer;
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
        timerLabel = 'Connected time',
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
                timerEl.textContent = timer;
                timerEl.classList.toggle('hidden', !showTimer);
                timerEl.dataset.timerLabel = timerLabel;
            }
        });

        this.setDialerCallControlsVisible(visible, {
            showRecord,
            showEndCall: showHangup,
            showAnswer,
            showHold,
            showTransfer,
        });

        this.updateActiveCallScreen({
            visible,
            state,
            peer,
            timer,
            showTimer,
            showHold,
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
        timer = '00:00',
        showTimer = false,
        showHold = false,
        showTransfer = false,
        showRecord = false,
        statusLabel = '',
    } = {}) {
        const phoneMode = isPhoneDialerMode();
        const onCall = visible && phoneMode;
        const connected = state === 'in-call' && this.timerPhase === 'connected';
        const statusText =
            statusLabel ||
            (connected ? 'Connected' : state === 'ringing' || state === 'dialing' ? 'Ringing…' : 'Connecting…');

        document.querySelectorAll('[data-dialer-active-screen]').forEach((screen) => {
            screen.classList.toggle('hidden', !onCall);
            screen.setAttribute('aria-hidden', onCall ? 'false' : 'true');
            screen.classList.toggle('is-ringing', onCall && !connected);
            screen.classList.toggle('is-connected', onCall && connected);

            const peerEl = screen.querySelector('[data-dialer-active-peer]');
            if (peerEl) {
                peerEl.textContent = peer || '—';
            }

            const statusEl = screen.querySelector('[data-dialer-active-status]');
            if (statusEl) {
                statusEl.textContent = statusText;
            }

            const timerEl = screen.querySelector('[data-dialer-active-timer]');
            if (timerEl) {
                timerEl.textContent = timer;
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
        const statusLabel =
            state === 'in-call'
                ? 'Connected'
                : state === 'ringing'
                  ? 'Ringing'
                  : outboundActive && this.awaitingDestinationBridge
                    ? 'Ringing'
                    : state === 'dialing'
                      ? 'Connecting'
                      : 'Ringing';
        const timerEl = document.querySelector('[data-dialer-call-timer]');

        this.updateFloatingPopup({
            title: state === 'in-call' ? 'Call live' : 'Outgoing call',
            subtitle: peer ? `Ringing ${peer}…` : 'Ringing destination…',
            detail: peer ? `To: ${peer}` : '',
            visible: true,
            timer: timerEl?.textContent || '00:00',
            timerLabel: state === 'in-call' ? 'Connected time' : 'Ringing time',
            statusLabel,
            showRingingVisual: state !== 'in-call',
            showConnectedTimer: state === 'in-call' && this.timerPhase === 'connected',
            showAnswer: state === 'ringing' && !outboundActive,
            showHangup: true,
            showRecord: true,
            showHold: state === 'in-call' && Boolean(this.morpheusCallUuid),
            showTransfer: state === 'in-call' && Boolean(this.morpheusCallUuid),
            state,
        });
    }

    flushCallUiInstant() {
        this.stopCallTimer();
        this.updateCallCard({ visible: false });
        this.updateFloatingPopup({ visible: false });
        if (this.ui?.remoteAudio) {
            this.ui.remoteAudio.srcObject = null;
            this.ui.remoteAudio.pause?.();
        }
    }

    startRingTimer() {
        this.stopCallTimer();
        this.timerPhase = 'ringing';
        this.callStartedAt = Date.now();
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            timerEl.classList.add('hidden');
            timerEl.textContent = '00:00';
        });
        this.callTimer = null;
    }

    startCallTimer({ restart = false } = {}) {
        if (this.awaitingDestinationBridge && !restart) {
            return;
        }

        this.stopCallTimer();
        this.timerPhase = 'connected';
        this.callStartedAt = Date.now();
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

        this.tickCallTimer();
        this.callTimer = window.setInterval(() => this.tickCallTimer(), 1000);
        this.refreshDialerCallOverlay('in-call');
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
        if (this.ui?.callTimer) {
            this.ui.callTimer.textContent = formatted;
        }
        document.querySelectorAll('[data-dialer-call-timer], [data-dialer-active-timer]').forEach((timerEl) => {
            timerEl.textContent = formatted;
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
            } else {
                delete btn.dataset.webphoneDialState;
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
            contactName: String(this.config.extension || sipUser || ''),
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
                    constraints: { audio: true, video: false },
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

                const isTrackedOriginatedOutbound =
                    this.currentCallDirection === 'outbound'
                    && !this.directDialActive
                    && Boolean(this.activeCallUuid());

                if (isTrackedOriginatedOutbound && this.state === 'in-call') {
                    this.attachRemoteAudio(session);

                    return;
                }

                if (isTrackedOriginatedOutbound && this.awaitingDestinationBridge) {
                    this.clickToCallActive = true;
                    this._lastSeenPstnBillsec = 0;
                    this.stopAllLocalRingers();
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
                    this.startRemoteAnswerWatcher(session);

                    return;
                }

                if (isTrackedOriginatedOutbound && !this.awaitingDestinationBridge) {
                    this.markDestinationConnected({ source: 'sip-established' });
                    this.attachRemoteAudio(session);

                    return;
                }

                this.stopRingback();
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
                    showConnectedTimer: false,
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
                const statusUuid = this.hangupCallUuid();
                const peer = this.currentCallPeer || '';
                const stillRegistered = this.registerer?.state === RegistererState.Registered;
                const clickToCallDrop =
                    wasOutboundRinging &&
                    !this.directDialActive &&
                    stillRegistered &&
                    this.clickToCallActive &&
                    statusUuid &&
                    !this.userInitiatedHangup;

                this.stopRingback();

                if (clickToCallDrop) {
                    void this.finalizeClickToCallAfterBye(statusUuid, peer);
                } else if (wasOutboundRinging && !this.directDialActive && !this.userInitiatedHangup) {
                    const digits = peer.replace(/\D/g, '');

                    if (stillRegistered && this.clickToCallActive && digits.length >= 10) {
                        void this.showPstnLegFailedToast(statusUuid, peer);
                    } else if (digits.length >= 10) {
                        showCommToast(
                            humanCallEndMessage('NO_ANSWER', { outbound: true }),
                            'warning',
                        );
                    } else {
                        showCommToast('Call ended before the destination answered.', 'warning');
                    }
                    this.clearSession();
                } else if (wasOutboundRinging && this.directDialActive) {
                    if (this.suppressTerminateToast || this.outboundDialInProgress) {
                        this.suppressTerminateToast = false;
                    } else {
                        const rejectDetail = this.lastInviteReject || this.outboundTerminateDetail;
                        const detailSuffix = rejectDetail ? ` (${rejectDetail})` : '';
                        const message = this.sawOutboundRinging
                            ? humanCallEndMessage('NO_ANSWER', { outbound: true })
                            : 'Call ended before the destination answered.';
                        showCommToast(message, 'warning');
                    }
                }

                if (statusUuid && !this.userInitiatedHangup && !clickToCallDrop) {
                    if (this.state === 'in-call') {
                        void this.handleRemotePartyHangup(
                            { hangup_cause: 'NORMAL_CLEARING' },
                            { source: 'sip-terminated' },
                        );

                        return;
                    }

                    void this.hangupMorpheusCall(statusUuid).finally(() => {
                        this.clearSession();
                    });

                    return;
                }

                if (!clickToCallDrop) {
                    this.clearSession();
                }
            }
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
            remoteAudio.muted = false;
            remoteAudio.autoplay = true;
            remoteAudio.play().catch(() => {});
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0] || new MediaStream([event.track]);
            bindStream(stream);
            this.stopAllLocalRingers();
            if (this.awaitingDestinationBridge) {
                this.startRemoteAnswerWatcher(session);
                this.startLiveTrackWatcher(session);
            }
        };

        if (!pc._commHubHangupBound) {
            pc._commHubHangupBound = true;
            pc.addEventListener('connectionstatechange', () => {
                const connectionState = pc.connectionState;
                if (
                    this.awaitingDestinationBridge
                    && connectionState === 'connected'
                    && pc.getReceivers?.().some((receiver) => receiver.track?.readyState === 'live')
                ) {
                    this.markDestinationConnected({ source: 'webrtc-connected' });
                }

                if (
                    this.state === 'in-call'
                    && !this.hangupInFlight
                    && !this.remoteHangupHandled
                    && (connectionState === 'disconnected' || connectionState === 'failed' || connectionState === 'closed')
                ) {
                    void this.handleRemotePartyHangup(
                        { hangup_cause: 'NORMAL_CLEARING' },
                        { source: 'webrtc-state' },
                    );
                }
            });
        }

        const stream = new MediaStream();
        pc.getReceivers().forEach((receiver) => {
            if (receiver.track) {
                stream.addTrack(receiver.track);
            }
        });
        if (stream.getTracks().length > 0) {
            bindStream(stream);
            this.stopAllLocalRingers();
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

    async hangup(reason = 'unknown') {
        if (this.hangupInFlight) {
            return;
        }
        this.hangupInFlight = true;
        const morpheusUuid = this.hangupCallUuid();

        this.userInitiatedHangup = true;
        this.flushCallUiInstant();

        this.logPhone('info', 'Hangup requested', {
            reason,
            originateCallUuid: morpheusUuid,
            bridgedCallUuid: this.bridgedCallUuid,
            sessionState: this.session?.state ?? null,
            awaitingDestinationBridge: this.awaitingDestinationBridge,
            peer: this.currentCallPeer,
        });

        this.stopDestinationPoll();
        this.stopRemoteAnswerWatcher();
        this.stopAllLocalRingers();

        if (morpheusUuid) {
            await this.hangupAllMorpheusLegs(morpheusUuid);
        }
        await this.releaseExtensionCalls();

        try {
            if (this.session) {
                const state = this.session.state;
                if (state === SessionState.Initial || state === SessionState.Establishing) {
                    if (this.session instanceof Inviter) {
                        await this.session.cancel();
                    } else {
                        await this.session.reject();
                    }
                } else if (state !== SessionState.Terminating && state !== SessionState.Terminated) {
                    await this.session.bye();
                }
            }
        } catch (error) {
            this.logPhone('warn', 'Local SIP hangup failed after Morpheus hangup', {
                reason,
                error: error instanceof Error ? error.message : String(error),
                morpheusCallUuid: morpheusUuid,
            });
        } finally {
            this.pendingClickToCall = false;
            this.hangupInFlight = false;
            this.clearSession();
        }
    }

    clearSession() {
        const endedPhone = this.currentCallPeer || '';
        const endedUuid = this.hangupCallUuid();
        if (endedPhone || endedUuid) {
            window.dispatchEvent(new CustomEvent('comm:call-ended', {
                detail: {
                    phone: endedPhone,
                    callUuid: endedUuid || '',
                },
            }));
        }

        this.session = null;
        this.stopAllLocalRingers();
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
        this.hangupInFlight = false;
        this.userInitiatedHangup = false;
        this.remoteHangupHandled = false;
        this.lastDialedDestination = '';
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
