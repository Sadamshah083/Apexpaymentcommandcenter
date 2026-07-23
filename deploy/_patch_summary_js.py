#!/usr/bin/env python3
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "resources/views/communications/agent-status/partials/panel.blade.php"
text = path.read_text(encoding="utf-8")
start = text.index("    let activeSummaryRow = null;")
end = text.index("    root.addEventListener('click', async (event) => {")

new_block = r'''    let activeSummaryRow = null;
    let plainSummary = '';
    let downloadDoc = '';
    let summaryMetaPayload = {};

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function highlightSummary(text) {
        return escapeHtml(text).replace(/\*\*(.+?)\*\*/g, '<span class="ai-call-summary-em">$1</span>').replace(/\n/g, '<br>');
    }

    function buildInstantFromRow(row) {
        const agent = row.dataset.agent || 'The agent';
        const toPhone = row.dataset.toPhone || row.children[4]?.textContent?.trim() || 'the contact';
        const fromPhone = row.dataset.fromPhone || '—';
        const status = row.dataset.status || row.querySelector('.agent-status-pill')?.textContent?.trim() || 'Unknown';
        const duration = row.dataset.durationLabel || row.children[3]?.textContent?.trim() || '0:00:00';
        const when = row.dataset.when || row.children[1]?.textContent?.trim() || '—';
        const cached = (row.dataset.aiSummaryText || '').trim();
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
        summaryModal.classList.remove('hidden');
        summaryModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ai-call-summary-open');
    }

    function closeSummaryModal() {
        if (!summaryModal) return;
        summaryModal.classList.add('hidden');
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
            const btn = activeSummaryRow.querySelector('[data-ai-summary]');
            btn?.classList.add('is-ready');
            if (plainSummary) {
                activeSummaryRow.dataset.aiSummaryText = plainSummary;
            }
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
    summaryClose?.addEventListener('click', closeSummaryModal);
    summaryModal?.addEventListener('click', (event) => {
        if (event.target === summaryModal) closeSummaryModal();
    });
    summaryDownload?.addEventListener('click', downloadSummaryText);
    summaryRegen?.addEventListener('click', () => {
        if (activeSummaryRow) void fetchAiSummary(activeSummaryRow, true);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && summaryModal && !summaryModal.classList.contains('hidden')) {
            closeSummaryModal();
        }
    });

'''

path.write_text(text[:start] + new_block + text[end:], encoding="utf-8")
print("JS_PATCHED")
