let pendingPaidForm = null;
let pendingDeleteForm = null;

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

    if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm-delete], [data-confirm-document-delete]')) {
        return;
    }

    if (form.dataset.confirmed === 'true') {
        return;
    }

    const dialog = document.getElementById('confirm-delete-dialog');

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    event.preventDefault();
    pendingDeleteForm = form;

    const title = dialog.querySelector('[data-confirm-delete-title]');
    const message = dialog.querySelector('[data-confirm-delete-message]');

    if (title instanceof HTMLElement) {
        title.textContent = form.dataset.confirmTitle || '¿Eliminar este registro?';
    }

    if (message instanceof HTMLElement) {
        message.textContent = form.dataset.confirmMessage || 'Esta accion no se puede deshacer.';
    }

    dialog.showModal();
});

document.addEventListener('DOMContentLoaded', () => {
    applyTheme(document.documentElement.dataset.theme || localStorage.getItem('orvix-theme') || 'light');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
        });
    });

    document.querySelectorAll('[data-sync-payment-day-source]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!(input instanceof HTMLInputElement) || !input.value) {
                return;
            }

            const form = input.closest('form');
            const target = form?.querySelector('[data-sync-payment-day-target]');
            const [, , day] = input.value.split('-');

            if (target instanceof HTMLInputElement && day) {
                target.value = String(Number(day));
            }
        });
    });

    const bulkPaymentForm = document.querySelector('[data-bulk-payment-form]');
    const bulkPaymentToggle = document.querySelector('[data-bulk-payment-toggle]');
    const bulkPaymentCheckboxes = Array.from(document.querySelectorAll('[data-bulk-payment-checkbox]'));
    const refreshBulkPaymentForm = () => {
        if (!(bulkPaymentForm instanceof HTMLFormElement)) {
            return;
        }

        bulkPaymentForm.querySelectorAll('[data-bulk-payment-hidden]').forEach((input) => input.remove());
        const selected = bulkPaymentCheckboxes.filter((checkbox) => checkbox instanceof HTMLInputElement && checkbox.checked);

        selected.forEach((checkbox) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'installment_ids[]';
            input.value = checkbox.value;
            input.dataset.bulkPaymentHidden = 'true';
            bulkPaymentForm.appendChild(input);
        });

        bulkPaymentForm.classList.toggle('hidden', selected.length === 0);
    };

    if (bulkPaymentToggle instanceof HTMLInputElement) {
        bulkPaymentToggle.addEventListener('change', () => {
            bulkPaymentCheckboxes.forEach((checkbox) => {
                if (checkbox instanceof HTMLInputElement) {
                    checkbox.checked = bulkPaymentToggle.checked;
                }
            });
            refreshBulkPaymentForm();
        });
    }

    bulkPaymentCheckboxes.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            if (bulkPaymentToggle instanceof HTMLInputElement) {
                bulkPaymentToggle.checked = bulkPaymentCheckboxes.length > 0
                    && bulkPaymentCheckboxes.every((item) => item instanceof HTMLInputElement && item.checked);
            }
            refreshBulkPaymentForm();
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
            if (!['confirm', 'confirm-no-investors', 'confirm-capital-advance'].includes(dialog.returnValue) || !pendingPaidForm) {
                pendingPaidForm = null;
                return;
            }

            const paymentDateInput = dialog.querySelector('#confirm-paid-date');
            let formPaymentDateInput = pendingPaidForm.querySelector('input[name="operated_on"]');
            let affectsInvestorsInput = pendingPaidForm.querySelector('input[name="affects_investors"]');
            let paymentEffectInput = pendingPaidForm.querySelector('input[name="payment_effect"]');

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

            if (!(paymentEffectInput instanceof HTMLInputElement)) {
                paymentEffectInput = document.createElement('input');
                paymentEffectInput.type = 'hidden';
                paymentEffectInput.name = 'payment_effect';
                pendingPaidForm.appendChild(paymentEffectInput);
            }

            affectsInvestorsInput.value = dialog.returnValue === 'confirm-no-investors' ? '0' : '1';
            paymentEffectInput.value = {
                'confirm-no-investors': 'no_investors',
                'confirm-capital-advance': 'capital_advance',
            }[dialog.returnValue] || 'normal';

            pendingPaidForm.dataset.confirmed = 'true';
            pendingPaidForm.requestSubmit();
            pendingPaidForm = null;
        });
    }

    const deleteDialog = document.getElementById('confirm-delete-dialog');

    if (deleteDialog instanceof HTMLDialogElement) {
        deleteDialog.addEventListener('close', () => {
            if (deleteDialog.returnValue !== 'confirm' || !pendingDeleteForm) {
                pendingDeleteForm = null;
                return;
            }

            pendingDeleteForm.dataset.confirmed = 'true';
            pendingDeleteForm.requestSubmit();
            pendingDeleteForm = null;
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

    document.querySelectorAll('[data-investor-user-select]').forEach((select) => {
        const syncInvestorUser = () => {
            let users = {};

            try {
                users = JSON.parse(select.dataset.investorUsers || '{}');
            } catch {
                users = {};
            }

            const form = select.closest('form');

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const selectedUser = users[select.value] || null;

            if (selectedUser) {
                form.querySelectorAll('[data-investor-user-field]').forEach((field) => {
                    if (!(field instanceof HTMLInputElement)) {
                        return;
                    }

                    field.value = selectedUser[field.dataset.investorUserField] || '';
                });
            }

            const createUserInput = form.querySelector('input[name="create_user"]');
            const createUserRow = form.querySelector('[data-create-investor-user-row]');
            const passwordRow = form.querySelector('[data-create-investor-password-row]');
            const hasSelectedUser = Boolean(select.value);

            if (createUserInput instanceof HTMLInputElement) {
                createUserInput.checked = hasSelectedUser ? false : createUserInput.checked;
                createUserInput.disabled = hasSelectedUser;
            }

            [createUserRow, passwordRow].forEach((row) => {
                if (row instanceof HTMLElement) {
                    row.classList.toggle('hidden', hasSelectedUser);
                }
            });
        };

        select.addEventListener('change', syncInvestorUser);
        syncInvestorUser();
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
