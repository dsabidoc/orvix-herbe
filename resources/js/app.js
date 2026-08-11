let pendingPaidForm = null;
let pendingDocumentDeleteForm = null;

const applyTheme = (theme) => {
    const normalizedTheme = theme === 'dark' ? 'dark' : 'light';

    document.documentElement.dataset.theme = normalizedTheme;
    localStorage.setItem('orvix-theme', normalizedTheme);

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const label = button.querySelector('[data-theme-toggle-label]');
        const value = button.querySelector('[data-theme-toggle-value]');
        const sun = button.querySelector('.theme-toggle-sun');
        const moon = button.querySelector('.theme-toggle-moon');
        const isDark = normalizedTheme === 'dark';

        button.setAttribute('aria-pressed', String(isDark));

        if (label instanceof HTMLElement) {
            label.textContent = isDark ? 'Modo oscuro' : 'Modo claro';
        }

        if (value instanceof HTMLElement) {
            value.textContent = isDark ? 'Dark' : 'Light';
        }

        if (sun instanceof SVGElement) {
            sun.classList.toggle('hidden', isDark);
        }

        if (moon instanceof SVGElement) {
            moon.classList.toggle('hidden', !isDark);
        }
    });
};

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm-paid]')) {
        return;
    }

    if (form.dataset.confirmed === 'true') {
        return;
    }

    const dialog = document.getElementById('confirm-paid-dialog');

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    event.preventDefault();
    pendingPaidForm = form;

    const paymentDateInput = dialog.querySelector('#confirm-paid-date');
    const formPaymentDateInput = form.querySelector('input[name="operated_on"]');

    if (paymentDateInput instanceof HTMLInputElement && formPaymentDateInput instanceof HTMLInputElement) {
        paymentDateInput.value = formPaymentDateInput.value || new Date().toISOString().slice(0, 10);
    }

    dialog.showModal();
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm-document-delete]')) {
        return;
    }

    if (form.dataset.confirmed === 'true') {
        return;
    }

    const dialog = document.getElementById('confirm-document-delete-dialog');

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    event.preventDefault();
    pendingDocumentDeleteForm = form;
    dialog.showModal();
});

document.addEventListener('DOMContentLoaded', () => {
    applyTheme(document.documentElement.dataset.theme || localStorage.getItem('orvix-theme') || 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    });

    const mobileMenu = document.querySelector('[data-mobile-menu]');
    const mobileMenuOverlay = document.querySelector('[data-mobile-menu-overlay]');
    const openMobileMenu = () => {
        if (!(mobileMenu instanceof HTMLElement) || !(mobileMenuOverlay instanceof HTMLElement)) {
            return;
        }

        mobileMenu.classList.remove('-translate-x-full');
        mobileMenuOverlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    };
    const closeMobileMenu = () => {
        if (!(mobileMenu instanceof HTMLElement) || !(mobileMenuOverlay instanceof HTMLElement)) {
            return;
        }

        mobileMenu.classList.add('-translate-x-full');
        mobileMenuOverlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    };

    document.querySelectorAll('[data-open-mobile-menu]').forEach((button) => {
        button.addEventListener('click', openMobileMenu);
    });

    document.querySelectorAll('[data-close-mobile-menu]').forEach((button) => {
        button.addEventListener('click', closeMobileMenu);
    });

    if (mobileMenuOverlay instanceof HTMLElement) {
        mobileMenuOverlay.addEventListener('click', closeMobileMenu);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMobileMenu();
        }
    });

    window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
        if (event.matches) {
            closeMobileMenu();
        }
    });

    const dialog = document.getElementById('confirm-paid-dialog');

    if (dialog instanceof HTMLDialogElement) {
        dialog.addEventListener('close', () => {
            if (!['confirm', 'confirm-no-investors'].includes(dialog.returnValue) || !pendingPaidForm) {
                pendingPaidForm = null;
                return;
            }

            const paymentDateInput = dialog.querySelector('#confirm-paid-date');
            let formPaymentDateInput = pendingPaidForm.querySelector('input[name="operated_on"]');
            let affectsInvestorsInput = pendingPaidForm.querySelector('input[name="affects_investors"]');

            if (!(formPaymentDateInput instanceof HTMLInputElement)) {
                formPaymentDateInput = document.createElement('input');
                formPaymentDateInput.type = 'hidden';
                formPaymentDateInput.name = 'operated_on';
                pendingPaidForm.appendChild(formPaymentDateInput);
            }

            if (paymentDateInput instanceof HTMLInputElement && paymentDateInput.value) {
                formPaymentDateInput.value = paymentDateInput.value;
            }

            if (!(affectsInvestorsInput instanceof HTMLInputElement)) {
                affectsInvestorsInput = document.createElement('input');
                affectsInvestorsInput.type = 'hidden';
                affectsInvestorsInput.name = 'affects_investors';
                pendingPaidForm.appendChild(affectsInvestorsInput);
            }

            affectsInvestorsInput.value = dialog.returnValue === 'confirm-no-investors' ? '0' : '1';

            pendingPaidForm.dataset.confirmed = 'true';
            pendingPaidForm.requestSubmit();
            pendingPaidForm = null;
        });
    }

    const documentDeleteDialog = document.getElementById('confirm-document-delete-dialog');

    if (documentDeleteDialog instanceof HTMLDialogElement) {
        documentDeleteDialog.addEventListener('close', () => {
            if (documentDeleteDialog.returnValue !== 'confirm' || !pendingDocumentDeleteForm) {
                pendingDocumentDeleteForm = null;
                return;
            }

            pendingDocumentDeleteForm.dataset.confirmed = 'true';
            pendingDocumentDeleteForm.requestSubmit();
            pendingDocumentDeleteForm = null;
        });
    }

    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById(button.dataset.openModal);

            if (modal instanceof HTMLDialogElement) {
                modal.showModal();
            }
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = button.closest('dialog');

            if (modal instanceof HTMLDialogElement) {
                modal.close();
            }
        });
    });

    document.querySelectorAll('[data-client-prefill]').forEach((select) => {
        select.addEventListener('change', () => {
            let clients = {};

            try {
                clients = JSON.parse(select.dataset.clients || '{}');
            } catch {
                clients = {};
            }

            const client = clients[select.value] || {};
            const form = select.closest('form');

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            form.querySelectorAll('[data-client-field]').forEach((field) => {
                if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) {
                    return;
                }

                const value = client[field.dataset.clientField] || '';

                if (value !== '') {
                    field.value = value;
                } else if (!select.value) {
                    field.value = '';
                }
            });
        });
    });

    document.querySelectorAll('[data-generate-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            const field = form?.querySelector('[data-generated-password]');

            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@$%';
            const bytes = new Uint32Array(14);
            crypto.getRandomValues(bytes);
            field.value = Array.from(bytes, (value) => alphabet[value % alphabet.length]).join('');
            field.focus();
            field.select();
        });
    });

    document.querySelectorAll('form[data-submit-once]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = true;
                    button.classList.add('opacity-70');
                }
            });
        });
    });

    document.querySelectorAll('[data-quick-payment-select]').forEach((select) => {
        const container = select.closest('dialog') || document;
        const panels = Array.from(container.querySelectorAll('[data-quick-payment-panel]'));
        const empty = container.querySelector('[data-quick-payment-empty]');

        const syncPanels = () => {
            panels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.quickPaymentPanel !== select.value);
            });

            if (empty instanceof HTMLElement) {
                empty.classList.toggle('hidden', select.value !== '');
            }
        };

        select.addEventListener('change', syncPanels);
        syncPanels();
    });
});
