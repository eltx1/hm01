// Labels are progressive guidance; the form remains usable without JavaScript.
document.querySelectorAll('[data-payment-profile-form]').forEach(form => {
    const accountLabel = form.querySelector('label[for="field-account-reference"]');
    const account = form.elements.namedItem('account_reference');
    const note = form.querySelector('[data-payment-change-note]');
    const labels = {
        BANK_TRANSFER: ['Account number or IBAN', 'Enter your bank account number or IBAN'],
        PAYPAL: ['PayPal email address', 'name@example.com'],
        WISE: ['Wise account or payment reference', 'Enter the account details agreed with Finance'],
        OTHER: ['Payment reference', 'Enter the reference agreed with Finance'],
    };
    const update = () => {
        const method = form.querySelector('[name="payment_method"]:checked')?.value;
        if (accountLabel && account && labels[method]) {
            accountLabel.textContent = labels[method][0];
            account.placeholder = labels[method][1];
        }
        if (note) note.hidden = !form.dataset.savedMethod || method === form.dataset.savedMethod;
    };
    form.querySelectorAll('[name="payment_method"]').forEach(radio => radio.addEventListener('change', update));
    update();
});
