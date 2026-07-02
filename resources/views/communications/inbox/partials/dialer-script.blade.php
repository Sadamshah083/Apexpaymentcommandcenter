<script>
window.initGhlDialer = function (config) {
    const numberInput = document.getElementById(config.numberInputId);
    const callerSelect = document.getElementById(config.callerSelectId);
    const dialBtn = document.getElementById(config.dialBtnId);
    const backspace = config.backspaceId ? document.getElementById(config.backspaceId) : null;
    const keypadRoot = config.keypadRootId ? document.getElementById(config.keypadRootId) : document;
    const form = numberInput ? numberInput.closest('form') : null;

    if (!numberInput || !dialBtn) {
        return;
    }

    const storageKey = 'communications.dialer_extension';

    if (callerSelect) {
        const savedCaller = localStorage.getItem(storageKey);
        if (savedCaller) {
            const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
            if (match) {
                callerSelect.value = savedCaller;
            }
        }

        callerSelect.addEventListener('change', function () {
            localStorage.setItem(storageKey, callerSelect.value || '');
        });
    }

    function normalizePhone(value) {
        const digits = String(value || '').replace(/[^\d+]/g, '');
        if (!digits) return '';
        if (digits.startsWith('+')) return digits;
        const numeric = digits.replace(/^0+/, '');
        if (numeric.length === 10) return '+1' + numeric;
        if (numeric.length <= 6) return numeric;
        return '+' + numeric;
    }

    function refreshDialButton() {
        const hasNumber = normalizePhone(numberInput.value) !== '';
        const hasExtension = !callerSelect || callerSelect.value !== '';

        if (!hasNumber || !hasExtension) {
            dialBtn.setAttribute('disabled', 'disabled');
            dialBtn.classList.add('opacity-50');
            return;
        }

        dialBtn.removeAttribute('disabled');
        dialBtn.classList.remove('opacity-50');
    }

    numberInput.addEventListener('input', refreshDialButton);

    keypadRoot.querySelectorAll('[data-dial-key]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value += button.getAttribute('data-dial-key');
            refreshDialButton();
        });
    });

    document.querySelectorAll('[data-dial-number]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value = button.getAttribute('data-dial-number') || '';
            refreshDialButton();
        });
    });

    if (backspace) {
        backspace.addEventListener('click', function () {
            numberInput.value = numberInput.value.slice(0, -1);
            refreshDialButton();
        });
    }

    if (form && dialBtn.type === 'submit') {
        form.addEventListener('submit', function () {
            numberInput.value = normalizePhone(numberInput.value) || numberInput.value;
        });
    }

    refreshDialButton();
};
</script>
