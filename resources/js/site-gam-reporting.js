document.querySelectorAll('[data-gam-report-binding]').forEach((form) => {
    const account = form.querySelector('[name="gam_connection_id"]');
    const input = form.querySelector('[name="ad_unit"]');
    const list = form.querySelector('datalist');
    const feedback = form.querySelector('[data-unit-feedback]');
    let timer;
    let request;
    let sequence = 0;
    async function search() {
        request?.abort();
        const current = ++sequence;
        list.replaceChildren();
        if (!account.value) return;
        request = new AbortController();
        const url = new URL(form.dataset.unitsUrl, window.location.origin);
        url.searchParams.set('gam_connection_id', account.value);
        url.searchParams.set('q', input.value.trim());
        feedback.textContent = 'Searching this Google Ad Manager account…';
        try {
            const response = await fetch(url, { signal: request.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Search unavailable');
            const data = await response.json();
            if (current !== sequence) return;
            for (const unit of data.units) {
                const option = document.createElement('option');
                option.value = unit.id;
                option.label = `${unit.name} · ${unit.code}`;
                list.append(option);
            }
            feedback.textContent = data.units.length ? 'Choose a matching unit, then connect reports.' : 'No matches. Enter the exact name, code or ID to verify when saving.';
        } catch (error) {
            if (error.name !== 'AbortError' && current === sequence) feedback.textContent = 'Search is unavailable. You can still enter an exact name, code or ID.';
        }
    }
    account.addEventListener('change', () => { input.value = ''; window.clearTimeout(timer); search(); });
    input.addEventListener('input', () => { window.clearTimeout(timer); request?.abort(); ++sequence; timer = window.setTimeout(search, 450); });
    input.addEventListener('focus', () => { if (!list.children.length) search(); });
});
