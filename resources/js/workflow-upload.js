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
}
