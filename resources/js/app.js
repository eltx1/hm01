import './bootstrap';
import '../css/publisher-application.css';
import '../css/ux-launch.css';

const navigation = document.querySelector('#control-navigation');
const navigationToggle = document.querySelector('[data-nav-toggle]');
const navigationScrim = document.querySelector('[data-nav-close]');
let returnFocusToNavigationToggle = false;

const setNavigation = (open, { restoreFocus = false } = {}) => {
    if (!navigation || !navigationToggle || !navigationScrim) return;

    navigation.classList.toggle('is-open', open);
    navigationScrim.classList.toggle('is-open', open);
    navigationToggle.setAttribute('aria-expanded', String(open));
    document.body.classList.toggle('navigation-open', open);

    if (open) {
        returnFocusToNavigationToggle = true;
        window.requestAnimationFrame(() => navigation.querySelector('a, button, summary')?.focus());
    } else if (restoreFocus && returnFocusToNavigationToggle) {
        returnFocusToNavigationToggle = false;
        navigationToggle.focus();
    }
};

navigationToggle?.addEventListener('click', () => setNavigation(!navigation?.classList.contains('is-open')));
navigationScrim?.addEventListener('click', () => setNavigation(false, { restoreFocus: true }));
navigation?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setNavigation(false)));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && navigation?.classList.contains('is-open')) {
        setNavigation(false, { restoreFocus: true });
    }
});

const setCopyFeedback = (button, message) => {
    const original = button.dataset.copyLabel || button.textContent.trim() || 'Copy';
    button.dataset.copyLabel = original;
    button.textContent = message;
    button.setAttribute('aria-live', 'polite');
    window.setTimeout(() => {
        button.textContent = original;
        button.removeAttribute('aria-live');
    }, 1800);
};

document.querySelectorAll('[data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
    const target = document.getElementById(button.dataset.copyTarget);
    if (!target) return;
    const content = target.textContent;
    try {
        await navigator.clipboard.writeText(content);
        setCopyFeedback(button, 'Copied');
    } catch {
        const range = document.createRange();
        range.selectNodeContents(target);
        window.getSelection()?.removeAllRanges();
        window.getSelection()?.addRange(range);
        document.execCommand('copy');
        window.getSelection()?.removeAllRanges();
        setCopyFeedback(button, 'Copied');
    }
}));

/* Progressive enhancement only: the server remains authoritative. */
document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const confirmation = form.dataset.confirm;
        if (confirmation && !window.confirm(confirmation)) {
            event.preventDefault();
            return;
        }

        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }

        if (!form.checkValidity()) return;

        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        const submitter = event.submitter;
        if (submitter instanceof HTMLElement) {
            submitter.setAttribute('aria-disabled', 'true');
            if (submitter.dataset.submittingLabel) {
                submitter.dataset.originalLabel = submitter.textContent;
                submitter.textContent = submitter.dataset.submittingLabel;
            }
        }
    });
});
