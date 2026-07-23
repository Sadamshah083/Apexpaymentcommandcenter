#!/usr/bin/env python3
"""Fix Summary popup: unique selectors, body portal, high z-index, reliable click."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
panel = ROOT / "resources/views/communications/agent-status/partials/panel.blade.php"
css = ROOT / "resources/css/app.css"

text = panel.read_text(encoding="utf-8")

# Fix row cache attribute name (was colliding with modal body selector)
text = text.replace('data-ai-summary-text="{{ e($log[\'ai_summary\'] ?? \'\') }}"', 'data-summary-cache="{{ e($log[\'ai_summary\'] ?? \'\') }}"')
text = text.replace(
    'class="agent-status-summary-btn{{ !empty($log[\'has_ai_summary\']) ? \' is-ready\' : \'\' }}"\n                                            data-ai-summary\n                                            title="AI call summary"',
    'class="agent-status-summary-btn{{ !empty($log[\'has_ai_summary\']) ? \' is-ready\' : \'\' }}"\n                                            data-ai-summary-btn\n                                            title="AI call summary"',
)
text = text.replace(
    '<p class="ai-call-summary-text" data-ai-summary-text></p>',
    '<p class="ai-call-summary-text" data-ai-summary-body></p>',
)

# Replace entire script push block
start = text.index("@push('scripts')")
end = text.index("@endpush") + len("@endpush")

new_script = r'''@push('scripts')
<script>
(() => {
    function initAllCallLogsSummary() {
        const root = document.querySelector('[data-all-call-logs]');
        if (!root || root.dataset.summaryBound === '1') return;
        root.dataset.summaryBound = '1';

        const player = root.querySelector('[data-all-call-logs-player]');
        const audio = root.querySelector('[data-all-call-logs-audio]');
        const closeBtn = root.querySelector('[data-all-call-logs-close]');
        const loadingEl = root.querySelector('[data-all-call-logs-loading]');
        const loadingText = root.querySelector('[data-all-call-logs-loading-text]');
        const syncUrl = root.dataset.recordingSyncUrl || '';
        const summaryUrl = root.dataset.aiSummaryUrl || '';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || '';

        let summaryModal = root.querySelector('[data-ai-summary-modal]');
        if (summaryModal && summaryModal.parentElement !== document.body) {
            document.body.appendChild(summaryModal);
        }
        const summaryMeta = summaryModal?.querySelector('[data-ai-summary-meta]');
        const summaryText = summaryModal?.querySelector('[data-ai-summary-body]');
        const summaryError = summaryModal?.querySelector('[data-ai-summary-error]');
        const summaryLoading = summaryModal?.querySelector('[data-ai-summary-loading]');
        const summaryDownload = summaryModal?.querySelector('[data-ai-summary-download]');
        const summaryRegen = summaryModal?.querySelector('[data-ai-summary-regen]');
        const summaryClose = summaryModal?.querySelector('[data-ai-summary-close]');

        const playIcon = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 001.5.86l11-6.86a1 1 0 000-1.72l-11-6.86A1 1 0 008 5.14z"/></svg><span>Play</span>';
        const downloadIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg><span>Download</span>';
        const loadingIcon = '<span class="agent-status-rec-btn__spinner" aria-hidden="true"></span><span>Loading…</span>';

        let activePlayBtn = null;
        let playToken = 0;
        const urlCache = new Map();
        let activeSummaryRow = null;
        let plainSummary = '';
        let downloadDoc = '';
        let summaryMetaPayload = {};

        function toDownloadUrl(url) {
            if (!url) return '';
            if (url.includes('action=download')) return url;
            if (url.includes('action=play')) return url.replace('action=play', 'action=download');
            return url + (url.includes('?') ? '&' : '?') + 'action=download';
        }

        function setPlayerLoading(isLoading, message) {
            player?.classList.toggle('is-loading', Boolean(isLoading));
            if (loadingEl) loadingEl.hidden = !isLoading;
            if (loadingText && message) loadingText.textContent = message;
            if (activePlayBtn) {
                activePlayBtn.classList.toggle('is-loading', Boolean(isLoading));
                activePlayBtn.disabled = Boolean(isLoading);
                if (isLoading) activePlayBtn.innerHTML = loadingIcon;
                else if (activePlayBtn.hasAttribute('data-recording-play') || activePlayBtn.dataset.recordingPlay === '1') {
                    activePlayBtn.innerHTML = playIcon;
                }
            }
        }

        function closePlayer() {
            playToken += 1;
            if (!audio) return;
            try { audio.pause(); } catch (_) {}
            audio.removeAttribute('src');
            try { audio.load(); } catch (_) {}
            setPlayerLoading(false);
            activePlayBtn = null;
            player?.classList.add('hidden');
        }

        function waitForCanPlay(el, token) {
            return new Promise((resolve, reject) => {
                if (!el) { reject(new Error('No audio element')); return; }
                if (el.readyState >= 2) { resolve(); return; }
                const onReady = () => { cleanup(); resolve(); };
                const onError = () => { cleanup(); reject(new Error('Recording failed to load')); };
                const cleanup = () => {
                    el.removeEventListener('canplay', onReady);
                    el.removeEventListener('loadeddata', onReady);
                    el.removeEventListener('error', onError);
                    clearTimeout(timer);
                };
                const timer = setTimeout(() => {
                    if (token !== playToken) { cleanup(); reject(new Error('cancelled')); return; }
                    cleanup(); resolve();
                }, 2500);
                el.addEventListener('canplay', onReady, { once: true });
                el.addEventListener('loadeddata', onReady, { once: true });
                el.addEventListener('error', onError, { once: true });
            });
        }

        async function playUrl(url, triggerBtn) {
            if (!audio || !url) return;
            const token = ++playToken;
            activePlayBtn = triggerBtn || activePlayBtn;
            player?.classList.remove('hidden');
            player?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setPlayerLoading(true, 'Loading recording…');
            try { audio.pause(); } catch (_) {}
            try {
                let playSrc = urlCache.get(url) || '';
                if (!playSrc) {
                    const res = await fetch(url, {
                        credentials: 'same-origin',
                        headers: { Accept: 'audio/*,application/octet-stream,*/*' },
                    });
                    if (token !== playToken) return;
                    if (!res.ok) throw new Error('Recording failed to load');
                    const blob = await res.blob();
                    if (token !== playToken) return;
                    playSrc = URL.createObjectURL(blob);
                    urlCache.set(url, playSrc);
                }
                audio.preload = 'auto';
                audio.src = playSrc;
                audio.load();
                await waitForCanPlay(audio, token);
                if (token !== playToken) return;
                await audio.play();
                if (token === playToken) setPlayerLoading(false);
            } catch (err) {
                if (token !== playToken) return;
                setPlayerLoading(false);
                if (String(err?.message || '') !== 'cancelled') {
                    window.showToast?.('Could not play recording. Try Download or Find again.', 'error');
                }
            }
        }

        async function downloadRecording(url, filenameHint, triggerBtn) {
            const downloadUrl = toDownloadUrl(url);
            if (!downloadUrl) { window.showToast?.('Download link missing.', 'error'); return; }
            const btn = triggerBtn;
            const original = btn?.innerHTML;
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.innerHTML = loadingIcon.replace('Loading…', 'Downloading…');
            }
            try {
                const res = await fetch(downloadUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'audio/*,application/octet-stream,*/*' },
                });
                if (!res.ok) throw new Error('Download failed');
                const blob = await res.blob();
                const objectUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = objectUrl;
                a.download = filenameHint || 'call-recording.mp3';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(objectUrl), 2000);
                window.showToast?.('Recording download started.', 'success');
            } catch (_) {
                const a = document.createElement('a');
                a.href = downloadUrl;
                a.setAttribute('download', filenameHint || 'call-recording.mp3');
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                a.remove();
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.innerHTML = original || downloadIcon;
                }
            }
        }

        function renderReady(cell, playUrlValue, downloadUrl) {
            cell.innerHTML = '';
            const play = document.createElement('button');
            play.type = 'button';
            play.className = 'agent-status-rec-btn is-play';
            play.dataset.recordingPlay = '1';
            play.setAttribute('data-recording-play', '');
            play.title = 'Play recording';
            play.innerHTML = playIcon;
            cell.appendChild(play);
            const dl = document.createElement('button');
            dl.type = 'button';
            dl.className = 'agent-status-rec-btn is-download';
            dl.dataset.recordingDownload = '1';
            dl.setAttribute('data-recording-download', '');
            dl.title = 'Download recording';
            dl.innerHTML = downloadIcon;
            cell.appendChild(dl);
            const row = cell.closest('[data-call-log-row]');
            if (row) {
                row.dataset.playUrl = playUrlValue || '';
                row.dataset.downloadUrl = toDownloadUrl(downloadUrl || playUrlValue || '');
                row.dataset.hasRecording = '1';
            }
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function highlightSummary(text) {
            return escapeHtml(text)
                .replace(/\*\*(.+?)\*\*/g, '<span class="ai-call-summary-em">$1</span>')
                .replace(/\n/g, '<br>');
        }

        function buildInstantFromRow(row) {
            const agent = row.dataset.agent || 'The agent';
            const toPhone = row.dataset.toPhone || row.children[4]?.textContent?.trim() || 'the contact';
            const fromPhone = row.dataset.fromPhone || '—';
            const status = row.dataset.status || row.querySelector('.agent-status-pill')?.textContent?.trim() || 'Unknown';
            const duration = row.dataset.durationLabel || row.children[3]?.textContent?.trim() || '0:00:00';
            const when = row.dataset.when || row.children[1]?.textContent?.trim() || '—';
            const cached = (row.dataset.summaryCache || '').trim();
            const summary = cached || (
                `The **agent, ${agent}**, called **${toPhone}**. The call lasted **${duration}** and was logged as **${status}**. Outcome details are based on the recorded disposition and call data.`
            );
            return {
                summary,
                summary_html: highlightSummary(summary),
                agent,
                from_phone: fromPhone,
                to_phone: toPhone,
                phone: toPhone,
                status,
                duration_label: duration,
                duration_sec: Number(row.dataset.durationSec || 0),
                when,
                instant: !cached,
                cached: Boolean(cached),
            };
        }

        function buildDownloadDoc(data) {
            if (data.download_text) return String(data.download_text);
            const summary = String(data.summary || '').replace(/\*\*/g, '');
            return [
                'Call summary',
                '================================================',
                `Caller (agent): ${data.agent || '—'}`,
                `From number: ${data.from_phone || '—'}`,
                `Called number: ${data.to_phone || data.phone || '—'}`,
                `Disposition: ${data.status || '—'}`,
                `Duration: ${data.duration_label || '—'}`,
                `When: ${data.when || '—'}`,
                '',
                'Summary',
                '------------------------------------------------',
                summary.trim(),
                '',
            ].join('\n');
        }

        function openSummaryModal() {
            if (!summaryModal) return;
            if (summaryModal.parentElement !== document.body) {
                document.body.appendChild(summaryModal);
            }
            summaryModal.classList.remove('hidden');
            summaryModal.classList.add('is-open');
            summaryModal.style.display = 'flex';
            summaryModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('ai-call-summary-open');
        }

        function closeSummaryModal() {
            if (!summaryModal) return;
            summaryModal.classList.add('hidden');
            summaryModal.classList.remove('is-open');
            summaryModal.style.display = 'none';
            summaryModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('ai-call-summary-open');
            activeSummaryRow = null;
        }

        function setSummaryLoading(isLoading) {
            if (summaryLoading) summaryLoading.hidden = !isLoading;
            if (summaryText) summaryText.hidden = isLoading && !plainSummary;
            if (summaryDownload) summaryDownload.disabled = !plainSummary;
            if (summaryRegen) summaryRegen.disabled = isLoading || !activeSummaryRow;
        }

        function showSummaryResult(data) {
            plainSummary = String(data.summary || '').trim();
            summaryMetaPayload = data;
            downloadDoc = buildDownloadDoc(data);
            const phone = String(data.to_phone || data.phone || '').trim();
            const durLabel = String(data.duration_label || '').trim();
            if (summaryMeta) {
                summaryMeta.textContent = phone
                    ? `${phone}${durLabel ? ` (${durLabel})` : ''}`
                    : durLabel;
            }
            if (summaryError) {
                summaryError.hidden = true;
                summaryError.textContent = '';
            }
            if (summaryText) {
                summaryText.hidden = false;
                summaryText.innerHTML = data.summary_html || highlightSummary(plainSummary);
            }
            if (summaryDownload) summaryDownload.disabled = !plainSummary;
            if (summaryRegen) summaryRegen.disabled = !activeSummaryRow;
            if (activeSummaryRow) {
                const btn = activeSummaryRow.querySelector('[data-ai-summary-btn]');
                btn?.classList.add('is-ready');
                if (plainSummary) activeSummaryRow.dataset.summaryCache = plainSummary;
            }
        }

        async function fetchAiSummary(row, force = false) {
            if (!row) return;
            activeSummaryRow = row;
            const instant = buildInstantFromRow(row);
            openSummaryModal();
            showSummaryResult(instant);
            setSummaryLoading(false);

            if (!summaryUrl) return;
            if (summaryLoading && force) {
                summaryLoading.hidden = false;
                const label = summaryLoading.querySelector('span:last-child');
                if (label) label.textContent = 'Refreshing AI summary…';
                if (summaryRegen) summaryRegen.disabled = true;
            }

            try {
                const res = await fetch(summaryUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        call_log_ref: row.dataset.callLogRef || '',
                        call_uuid: row.dataset.callUuid || '',
                        force: Boolean(force),
                        instant: !force,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.summary) {
                    if (force) throw new Error(data.message || 'Could not generate summary.');
                    return;
                }
                showSummaryResult(data);
            } catch (err) {
                if (force) {
                    if (summaryError) {
                        summaryError.hidden = false;
                        summaryError.textContent = err?.message || 'Could not generate AI summary.';
                    }
                    window.showToast?.(err?.message || 'Could not generate AI summary.', 'error');
                }
            } finally {
                if (summaryLoading) summaryLoading.hidden = true;
                setSummaryLoading(false);
            }
        }

        function downloadSummaryText() {
            const content = downloadDoc || buildDownloadDoc({
                ...summaryMetaPayload,
                summary: plainSummary,
            });
            if (!content.trim()) return;
            const phone = String(summaryMetaPayload.to_phone || summaryMetaPayload.phone || 'call')
                .replace(/[^\dA-Za-z]+/g, '_')
                .replace(/^_|_$/g, '') || 'call';
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const objectUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = objectUrl;
            a.download = `call-summary_${phone}.txt`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 1500);
            window.showToast?.('Summary download started.', 'success');
        }

        closeBtn?.addEventListener('click', closePlayer);
        summaryClose?.addEventListener('click', (e) => { e.preventDefault(); closeSummaryModal(); });
        summaryModal?.addEventListener('click', (event) => {
            if (event.target === summaryModal) closeSummaryModal();
        });
        summaryDownload?.addEventListener('click', (e) => { e.preventDefault(); downloadSummaryText(); });
        summaryRegen?.addEventListener('click', (e) => {
            e.preventDefault();
            if (activeSummaryRow) void fetchAiSummary(activeSummaryRow, true);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && summaryModal?.classList.contains('is-open')) {
                closeSummaryModal();
            }
        });

        root.addEventListener('click', async (event) => {
            const summaryBtn = event.target.closest('[data-ai-summary-btn]');
            if (summaryBtn) {
                event.preventDefault();
                event.stopPropagation();
                const row = summaryBtn.closest('[data-call-log-row]');
                if (row) await fetchAiSummary(row, false);
                return;
            }

            const playBtn = event.target.closest('[data-recording-play]');
            const downloadBtn = event.target.closest('[data-recording-download]');
            const syncBtn = event.target.closest('[data-recording-sync]');
            const row = event.target.closest('[data-call-log-row]');
            if (!row) return;

            if (playBtn) {
                event.preventDefault();
                await playUrl(row.dataset.playUrl || '', playBtn);
                return;
            }

            if (downloadBtn) {
                event.preventDefault();
                const phone = (row.dataset.toPhone || 'call').trim().replace(/\W+/g, '_');
                await downloadRecording(row.dataset.downloadUrl || row.dataset.playUrl || '', `recording_${phone}.mp3`, downloadBtn);
                return;
            }

            if (!syncBtn || !syncUrl) return;
            event.preventDefault();
            const cell = row.querySelector('[data-recording-cell]');
            const label = cell?.querySelector('[data-recording-label]');
            syncBtn.disabled = true;
            syncBtn.classList.add('is-loading');
            syncBtn.innerHTML = loadingIcon.replace('Loading…', 'Looking…');
            if (label) label.textContent = '';
            try {
                const res = await fetch(syncUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        call_log_ref: row.dataset.callLogRef || '',
                        call_uuid: row.dataset.callUuid || '',
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (data.has_recording && data.play_url) {
                    renderReady(cell, data.play_url, data.download_url || toDownloadUrl(data.play_url));
                    const newPlayBtn = cell.querySelector('[data-recording-play]');
                    await playUrl(data.play_url, newPlayBtn);
                    window.showToast?.('Recording ready.', 'success');
                } else {
                    syncBtn.disabled = false;
                    syncBtn.classList.remove('is-loading');
                    syncBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-3-6.7M21 3v6h-6"/></svg><span>Find</span>';
                    if (label) label.textContent = data.recording_status === 'pending' ? 'Pending' : 'Not found';
                    window.showToast?.('Recording not available yet. Try Find again in a moment.', 'error');
                }
            } catch (_) {
                syncBtn.disabled = false;
                syncBtn.classList.remove('is-loading');
                syncBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-3-6.7M21 3v6h-6"/></svg><span>Find</span>';
                window.showToast?.('Could not look up recording.', 'error');
            }
        });

        async function autoFindVisible() {
            if (!syncUrl) return;
            const buttons = [...root.querySelectorAll('[data-recording-sync]')].slice(0, 12);
            for (const syncBtn of buttons) {
                const row = syncBtn.closest('[data-call-log-row]');
                if (!row || row.dataset.hasRecording === '1') continue;
                const cell = row.querySelector('[data-recording-cell]');
                const label = cell?.querySelector('[data-recording-label]');
                syncBtn.disabled = true;
                if (syncBtn.querySelector('span')) syncBtn.querySelector('span').textContent = 'Looking…';
                try {
                    const res = await fetch(syncUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            call_log_ref: row.dataset.callLogRef || '',
                            call_uuid: row.dataset.callUuid || '',
                        }),
                    });
                    const data = await res.json().catch(() => ({}));
                    if (data.has_recording && data.play_url) {
                        renderReady(cell, data.play_url, data.download_url || toDownloadUrl(data.play_url));
                    } else {
                        syncBtn.disabled = false;
                        if (syncBtn.querySelector('span')) syncBtn.querySelector('span').textContent = 'Find';
                        if (label) label.textContent = data.recording_status === 'pending' ? 'Pending' : '—';
                    }
                } catch (_) {
                    syncBtn.disabled = false;
                    if (syncBtn.querySelector('span')) syncBtn.querySelector('span').textContent = 'Find';
                }
            }
        }

        void autoFindVisible();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllCallLogsSummary, { once: true });
    } else {
        initAllCallLogsSummary();
    }
    document.addEventListener('turbo:load', () => {
        const root = document.querySelector('[data-all-call-logs]');
        if (root) root.dataset.summaryBound = '';
        initAllCallLogsSummary();
    });
    document.addEventListener('turbo:render', () => {
        const root = document.querySelector('[data-all-call-logs]');
        if (root) root.dataset.summaryBound = '';
        initAllCallLogsSummary();
    });
})();
</script>
@endpush'''

panel.write_text(text[:start] + new_script + text[end:], encoding="utf-8")

css_text = css.read_text(encoding="utf-8")
old = """.ai-call-summary-overlay {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
}

.ai-call-summary-overlay.hidden {
    display: none;
}"""

new = """.ai-call-summary-overlay {
    position: fixed !important;
    inset: 0;
    z-index: 99999 !important;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
}

.ai-call-summary-overlay.hidden {
    display: none !important;
}

.ai-call-summary-overlay.is-open {
    display: flex !important;
}"""

if old not in css_text:
    raise SystemExit("CSS block not found")
css.write_text(css_text.replace(old, new), encoding="utf-8")
print("FIXED")
