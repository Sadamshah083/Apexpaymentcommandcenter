function toggleNewCampaignField(select) {
    const wrap = document.getElementById('new-campaign-wrap');
    if (!wrap || !select) {
        return;
    }

    wrap.classList.toggle('hidden', select.value !== '');
}

function syncCampaignDropdown(wrapper) {
    const select = wrapper.querySelector('[data-import-campaign-select]');
    const label = wrapper.querySelector('[data-import-campaign-label]');
    if (!select || !label) {
        return;
    }

    const option = select.selectedOptions?.[0];
    const text = option?.textContent?.trim() || 'Select campaign';
    label.textContent = text === '— Create new campaign —' ? 'Create new campaign' : text;

    wrapper.querySelectorAll('[data-import-campaign-option]').forEach((button) => {
        const selected = (button.dataset.value || '') === (select.value || '');
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
}

function clearCampaignMenuPosition(menu) {
    if (!menu) {
        return;
    }

    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.width = '';
    menu.style.minWidth = '';
    menu.style.maxWidth = '';
    menu.style.zIndex = '';
}

function positionCampaignMenu(wrapper) {
    const trigger = wrapper.querySelector('.import-campaign-dropdown__trigger');
    const menu = wrapper.querySelector('.import-campaign-dropdown__menu');
    if (!trigger || !menu || menu.hidden) {
        return;
    }

    const gap = 8;
    const edge = 8;
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(Math.max(rect.width, 280), window.innerWidth - edge * 2);

    menu.style.position = 'fixed';
    menu.style.zIndex = '12150';
    menu.style.width = `${width}px`;
    menu.style.minWidth = `${Math.min(width, 240)}px`;
    menu.style.maxWidth = `calc(100vw - ${edge * 2}px)`;
    menu.style.right = 'auto';

    let left = rect.left;
    if (left + width > window.innerWidth - edge) {
        left = Math.max(edge, window.innerWidth - edge - width);
    }

    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(rect.bottom + gap)}px`;

    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        if (menuRect.bottom > window.innerHeight - edge) {
            const upTop = rect.top - menuRect.height - gap;
            if (upTop >= edge) {
                menu.style.top = `${Math.round(upTop)}px`;
            }
        }
    });
}

function closeCampaignDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.import-campaign-dropdown__trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper.querySelector('.import-campaign-dropdown__menu');
    if (menu) {
        menu.hidden = true;
        clearCampaignMenuPosition(menu);
    }
}

function closeAllCampaignDropdowns(except = null) {
    document.querySelectorAll('[data-import-campaign-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeCampaignDropdown(wrapper);
        }
    });
}

function bindCampaignDropdown(wrapper) {
    if (!wrapper || wrapper.dataset.bound === '1') {
        return;
    }

    wrapper.dataset.bound = '1';

    const select = wrapper.querySelector('[data-import-campaign-select]');
    const trigger = wrapper.querySelector('.import-campaign-dropdown__trigger');
    const menu = wrapper.querySelector('.import-campaign-dropdown__menu');

    if (!select || !trigger || !menu) {
        return;
    }

    syncCampaignDropdown(wrapper);
    toggleNewCampaignField(select);

    trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const willOpen = !wrapper.classList.contains('is-open');
        closeAllCampaignDropdowns(willOpen ? wrapper : null);

        wrapper.classList.toggle('is-open', willOpen);
        trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        menu.hidden = !willOpen;

        if (willOpen) {
            positionCampaignMenu(wrapper);
        } else {
            clearCampaignMenuPosition(menu);
        }
    });

    menu.querySelectorAll('[data-import-campaign-option]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const nextValue = button.dataset.value ?? '';
            select.value = nextValue;
            Array.from(select.options).forEach((option) => {
                option.selected = option.value === nextValue;
            });

            syncCampaignDropdown(wrapper);
            toggleNewCampaignField(select);
            closeCampaignDropdown(wrapper);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
}

function bindWorkflowUploadForm(form) {
    if (!form || form.dataset.workflowUploadBound === '1') {
        return;
    }

    form.dataset.workflowUploadBound = '1';

    const fileInput = form.querySelector('#file, input[type="file"][name="file"]');
    const placeholder = form.querySelector('#upload-placeholder');
    const selected = form.querySelector('#file-selected');
    const fileName = form.querySelector('#file-name');
    const resetBtn = form.querySelector('[data-reset-upload]');
    const nameInput = form.querySelector('#name');

    form.querySelectorAll('[data-import-campaign-dropdown]').forEach(bindCampaignDropdown);

    function showSelected(name) {
        if (fileName) {
            fileName.textContent = name;
        }
        placeholder?.classList.add('hidden');
        selected?.classList.remove('hidden');
    }

    function resetUpload() {
        if (fileInput) {
            fileInput.value = '';
        }
        placeholder?.classList.remove('hidden');
        selected?.classList.add('hidden');
        if (fileName) {
            fileName.textContent = '';
        }
    }

    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) {
            return;
        }
        showSelected(file.name);
    });

    resetBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        resetUpload();
    });

    form.addEventListener('turbo:submit-end', (event) => {
        if (event.detail?.success) {
            resetUpload();
            if (nameInput) {
                nameInput.value = '';
            }
        }
    });
}

export function initWorkflowUpload() {
    document.querySelectorAll('form[data-workflow-upload]').forEach(bindWorkflowUploadForm);

    if (document.body.dataset.importCampaignDropdownGlobalBound === '1') {
        return;
    }

    document.body.dataset.importCampaignDropdownGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-import-campaign-dropdown]')) {
            return;
        }
        closeAllCampaignDropdowns();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllCampaignDropdowns();
        }
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('[data-import-campaign-dropdown].is-open').forEach(positionCampaignMenu);
    }, true);

    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-import-campaign-dropdown].is-open').forEach(positionCampaignMenu);
    });
}
