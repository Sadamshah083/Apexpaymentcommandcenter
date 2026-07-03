export function updateAdminDetailPanel(detail) {
    if (!detail) {
        return;
    }

    const countEl = document.getElementById('detail-total-count');
    if (countEl && detail.total !== undefined) {
        countEl.textContent = Number(detail.total || 0).toLocaleString();
    }

    if (Array.isArray(detail.stats)) {
        detail.stats.forEach((stat, index) => {
            const el = document.getElementById(`detail-stat-${index}`);
            if (!el) {
                return;
            }

            el.textContent = typeof stat.value === 'number'
                ? Number(stat.value).toLocaleString()
                : stat.value;
        });
    }

    if (Array.isArray(detail.leads)) {
        const body = document.getElementById('detail-leads-body');
        if (body) {
            body.innerHTML = detail.leads.length > 0
                ? detail.leads.map((lead) => `
                    <tr>
                        <td>
                            <span class="dash-detail-lead-name">${lead.name}</span>
                            ${lead.workflow_name ? `<span class="dash-detail-muted">${lead.workflow_name}</span>` : ''}
                        </td>
                        <td><span class="dash-detail-badge">${lead.pipeline_phase}</span></td>
                        <td class="dash-detail-muted">${lead.stage}</td>
                        <td>${lead.assignee}</td>
                        <td class="dash-detail-muted">${lead.updated}</td>
                        <td class="text-right">
                            <a href="/admin/workflows/${lead.workflow_id}?lead=${lead.id}" class="dash-detail-link">Open</a>
                        </td>
                    </tr>`).join('')
                : '<tr><td colspan="6" class="dash-detail-empty">No matching records in this view.</td></tr>';
        }
    }

    if (Array.isArray(detail.activities)) {
        const body = document.getElementById('detail-activities-body');
        if (body) {
            body.innerHTML = detail.activities.length > 0
                ? detail.activities.map((row) => `
                    <tr>
                        <td>${row.user_name}</td>
                        <td>
                            ${row.workflow_id
                                ? `<a href="/admin/workflows/${row.workflow_id}?lead=${row.lead_id}" class="dash-detail-link">${row.lead_name}</a>`
                                : row.lead_name}
                        </td>
                        <td><span class="dash-detail-badge">${row.type}</span></td>
                        <td class="dash-detail-muted">${row.when}</td>
                    </tr>`).join('')
                : '<tr><td colspan="4" class="dash-detail-empty">No activity logged yet today.</td></tr>';
        }
    }
}
