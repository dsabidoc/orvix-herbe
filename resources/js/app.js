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

    const capitalAdvanceButton = dialog.querySelector('[data-capital-advance-action]');
    const allowsCapitalAdvance = form.dataset.capitalAdvanceAllowed === 'true';

    if (capitalAdvanceButton instanceof HTMLButtonElement) {
        capitalAdvanceButton.hidden = !allowsCapitalAdvance;
        capitalAdvanceButton.disabled = !allowsCapitalAdvance;
    }

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

    const moneyFieldNames = new Set([
        'additional_charge_amount',
        'administration_fee',
        'amount',
        'available_capital',
        'capital',
        'capital_amount',
        'contract_amount',
        'delinquency_amount',
        'external_concepts_amount',
        'generated_interest',
        'initial_capital',
        'opening_fee_value',
        'operator_surcharge_amount',
        'payment_amount',
        'received_total',
        'requested_capital',
        'returned_capital',
    ]);
    const normalizeMoney = (value) => String(value || '').replace(/,/g, '').replace(/[^\d.-]/g, '');
    const formatMoney = (value) => {
        const normalized = normalizeMoney(value);

        if (normalized === '' || normalized === '-' || normalized === '.') {
            return normalized;
        }

        const [integerPart, decimalPart] = normalized.split('.');
        const sign = integerPart.startsWith('-') ? '-' : '';
        const digits = integerPart.replace('-', '');
        const formattedInteger = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return `${sign}${formattedInteger}${decimalPart !== undefined ? `.${decimalPart.slice(0, 6)}` : ''}`;
    };
    const fieldBaseName = (name) => {
        const match = name.match(/(?:^|\[)([^\[\]]+)\]?$/);

        return match ? match[1] : name;
    };

    document.querySelectorAll('input').forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.type === 'file') {
            return;
        }

        const isMoneyInput = input.hasAttribute('data-money-input') || moneyFieldNames.has(fieldBaseName(input.name || ''));

        if (!isMoneyInput) {
            return;
        }

        if (input.type === 'number') {
            input.type = 'text';
        }

        input.inputMode = 'decimal';
        input.value = formatMoney(input.value);

        input.addEventListener('focus', () => {
            input.value = normalizeMoney(input.value);
        });

        input.addEventListener('blur', () => {
            input.value = formatMoney(input.value);
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.querySelectorAll('input').forEach((input) => {
            if (!(input instanceof HTMLInputElement) || input.type === 'file') {
                return;
            }

            const isMoneyInput = input.hasAttribute('data-money-input') || moneyFieldNames.has(fieldBaseName(input.name || ''));

            if (isMoneyInput) {
                input.value = normalizeMoney(input.value);
            }
        });
    }, { capture: true });

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

    document.querySelectorAll('[data-loan-purchase-date]').forEach((input) => {
        input.addEventListener('change', () => {
            if (!(input instanceof HTMLInputElement) || !input.value) {
                return;
            }

            const form = input.closest('form');
            const firstPaymentInput = form?.querySelector('input[name="first_payment_date"]');
            const disbursementInput = form?.querySelector('input[name="disbursement_delivered_on"]');
            const [year, month, day] = input.value.split('-').map((part) => Number(part));

            if (!year || !month || !day) {
                return;
            }

            const targetYear = month === 12 ? year + 1 : year;
            const targetMonthIndex = month === 12 ? 0 : month;
            const lastTargetDay = new Date(Date.UTC(targetYear, targetMonthIndex + 1, 0)).getUTCDate();
            const nextMonthDate = new Date(Date.UTC(targetYear, targetMonthIndex, Math.min(day, lastTargetDay)));

            const nextMonthValue = nextMonthDate.toISOString().slice(0, 10);

            if (firstPaymentInput instanceof HTMLInputElement) {
                firstPaymentInput.value = nextMonthValue;
                firstPaymentInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (disbursementInput instanceof HTMLInputElement) {
                disbursementInput.value = input.value;
            }
        });
    });

    document.querySelectorAll('[data-loan-calculation-method]').forEach((select) => {
        const form = select.closest('form');
        const rateType = form?.querySelector('select[name="rate_type"]');
        const rateValue = form?.querySelector('input[name="rate_value"]');
        const termMonths = form?.querySelector('select[name="term_months"]');
        const interestOnlyTerm = form?.querySelector('[data-interest-only-term-hidden]');
        const interestMethod = form?.querySelector('select[name="interest_calculation_method"]');
        const help = form?.querySelector('[data-interest-only-help]');
        const termWrapper = form?.querySelector('[data-term-months-wrapper]');

        const refreshInterestOnlyFields = () => {
            const isInterestOnly = select instanceof HTMLSelectElement && select.value === 'interest_only';

            if (help instanceof HTMLElement) {
                help.classList.toggle('hidden', !isInterestOnly);
            }

            if (termWrapper instanceof HTMLElement) {
                termWrapper.classList.toggle('hidden', isInterestOnly);
            }

            if (termMonths instanceof HTMLSelectElement) {
                termMonths.disabled = isInterestOnly;
                termMonths.required = !isInterestOnly;
            }

            if (interestOnlyTerm instanceof HTMLInputElement) {
                interestOnlyTerm.disabled = !isInterestOnly;
            }

            if (!isInterestOnly) {
                return;
            }

            if (rateType instanceof HTMLSelectElement) {
                rateType.value = 'monthly';
            }

            if (rateValue instanceof HTMLInputElement && (rateValue.value === '' || rateValue.value === '2' || rateValue.value === '2.00')) {
                rateValue.value = '3';
            }

            if (interestMethod instanceof HTMLSelectElement) {
                interestMethod.value = 'fixed_principal';
            }
        };

        select.addEventListener('change', refreshInterestOnlyFields);
        refreshInterestOnlyFields();
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

    const selectOverduePaymentsButton = document.querySelector('[data-select-overdue-payments]');

    if (selectOverduePaymentsButton instanceof HTMLButtonElement) {
        selectOverduePaymentsButton.addEventListener('click', () => {
            bulkPaymentCheckboxes.forEach((checkbox) => {
                if (checkbox instanceof HTMLInputElement && checkbox.hasAttribute('data-overdue-payment-checkbox')) {
                    checkbox.checked = true;
                }
            });

            if (bulkPaymentToggle instanceof HTMLInputElement) {
                bulkPaymentToggle.checked = bulkPaymentCheckboxes.length > 0
                    && bulkPaymentCheckboxes.every((item) => item instanceof HTMLInputElement && item.checked);
            }

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

            if (dialog.returnValue === 'confirm-capital-advance' && pendingPaidForm.dataset.capitalAdvanceAllowed !== 'true') {
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

    const parseJsonDataset = (element, key, fallback = {}) => {
        try {
            return JSON.parse(element.dataset[key] || JSON.stringify(fallback));
        } catch {
            return fallback;
        }
    };

    const normalizeLookup = (value) => String(value || '').trim().toLocaleUpperCase('es-MX');

    document.querySelectorAll('[data-client-prefill], [data-client-search]').forEach((element) => {
        const syncClient = () => {
            const clients = parseJsonDataset(element, 'clients');
            const form = element.closest('form');

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            let clientId = '';

            if (element instanceof HTMLSelectElement) {
                clientId = element.value;
            } else if (element instanceof HTMLInputElement) {
                const typed = normalizeLookup(element.value);
                const match = Object.entries(clients).find(([, client]) => normalizeLookup(client.display) === typed);
                clientId = match ? match[0] : '';

                const hiddenClientId = form.querySelector('[data-client-id]');

                if (hiddenClientId instanceof HTMLInputElement) {
                    hiddenClientId.value = clientId;
                }
            }

            const client = clients[clientId] || {};

            form.querySelectorAll('[data-client-field]').forEach((field) => {
                if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) {
                    return;
                }

                const value = client[field.dataset.clientField] || '';

                if (value !== '') {
                    field.value = value;
                } else if (!clientId && element instanceof HTMLSelectElement) {
                    field.value = '';
                }
            });
        };

        element.addEventListener('change', syncClient);
    });

    document.querySelectorAll('[data-guarantor-search]').forEach((input) => {
        input.addEventListener('change', () => {
            const guarantors = parseJsonDataset(input, 'guarantors', []);
            const typed = normalizeLookup(input.value);
            const guarantor = guarantors.find((option) => normalizeLookup(option.display) === typed);
            const form = input.closest('form');

            if (!guarantor || !(form instanceof HTMLFormElement)) {
                return;
            }

            form.querySelectorAll('[data-guarantor-field]').forEach((field) => {
                if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
                    return;
                }

                const value = guarantor[field.dataset.guarantorField] || '';

                if (value !== '') {
                    field.value = value;
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
