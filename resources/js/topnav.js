const TOPNAV_BOUND_KEY = 'topnavGlobalBound';

function closeTopnavDropdowns(except = null) {
    document.querySelectorAll('.app-topnav-dropdown[open]').forEach((dropdown) => {
        if (dropdown !== except) {
            dropdown.open = false;
        }
    });
}

export function initTopnav() {
    const dropdowns = document.querySelectorAll('.app-topnav-dropdown');
    if (dropdowns.length === 0) {
        return;
    }

    dropdowns.forEach((dropdown) => {
        if (dropdown.dataset.topnavToggleBound === '1') {
            return;
        }

        dropdown.dataset.topnavToggleBound = '1';
        dropdown.addEventListener('toggle', () => {
            if (dropdown.open) {
                closeTopnavDropdowns(dropdown);
            }
        });
    });

    if (document.documentElement.dataset[TOPNAV_BOUND_KEY] === '1') {
        closeTopnavDropdowns();
        return;
    }

    document.documentElement.dataset[TOPNAV_BOUND_KEY] = '1';

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.app-topnav-dropdown[open]').forEach((dropdown) => {
            if (!dropdown.open) {
                return;
            }

            if (!dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeTopnavDropdowns();
        }
    });
}
