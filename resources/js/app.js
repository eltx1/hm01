import './bootstrap';
import './site-gam-reporting';
import './payment-profile';
import '../css/publisher-application.css';
import '../css/ux-launch.css';
import '../css/interface-density.css';
import '../css/dashboard-theme.css';
import '../css/reporting-experience.css';
import '../css/form-experience.css';
import '../css/workspace-navigation.css';

const navigation = document.querySelector('#control-navigation');
const navigationToggle = document.querySelector('[data-nav-toggle]');
const navigationScrim = document.querySelector('.sidebar-scrim');
const mobileNavigation = window.matchMedia('(max-width: 1100px)');
const navigationBackground = [...document.querySelectorAll('#main-content, .hm-whatsapp-contact')];
let returnFocusToNavigationToggle = false;

const fitNavigation = () => {
    const viewport = window.visualViewport;
    // Browser chrome and the software keyboard can reduce the visible viewport.
    // Keep normal browser zoom available instead of resizing around a pinch gesture.
    if (viewport && viewport.scale === 1) {
        navigation?.style.setProperty('--navigation-height', `${viewport.height}px`);
        navigation?.style.setProperty('--navigation-top', `${viewport.offsetTop}px`);
    } else {
        navigation?.style.removeProperty('--navigation-height');
        navigation?.style.removeProperty('--navigation-top');
    }
};

const setNavigation = (requested, { restoreFocus = false } = {}) => {
    if (!navigation || !navigationToggle || !navigationScrim) return;
    const mobile = mobileNavigation.matches;
    const open = requested && mobile;
    navigation.classList.toggle('is-open', open);
    navigationScrim.classList.toggle('is-open', open);
    navigationToggle.setAttribute('aria-expanded', String(open));
    navigation.inert = mobile && !open;
    navigation.toggleAttribute('aria-hidden', mobile && !open);
    if (mobile && !open) navigation.setAttribute('aria-hidden', 'true');
    navigationBackground.forEach(element => { element.inert = open; });
    document.body.classList.toggle('navigation-open', open);
    document.documentElement.classList.toggle('navigation-open', open);
    fitNavigation();

    if (open) {
        returnFocusToNavigationToggle = true;
        // Focusing search would immediately open the mobile keyboard.
        navigation.querySelector('.sidebar-close')?.focus({ preventScroll: true });
    } else if (restoreFocus && returnFocusToNavigationToggle && mobile) {
        returnFocusToNavigationToggle = false;
        navigationToggle.focus();
    }
};

navigationToggle?.addEventListener('click', () => setNavigation(!navigation?.classList.contains('is-open')));
document.querySelectorAll('[data-nav-close]').forEach(button => button.addEventListener('click', () => setNavigation(false, { restoreFocus: true })));
navigation?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setNavigation(false)));
mobileNavigation.addEventListener('change', () => setNavigation(false));
window.visualViewport?.addEventListener('resize', fitNavigation);
window.visualViewport?.addEventListener('scroll', fitNavigation);
window.addEventListener('resize', fitNavigation);
window.addEventListener('pageshow', () => setNavigation(false));
setNavigation(false);

const navigationFilter = document.querySelector('[data-nav-filter]');
const navigationEmpty = document.querySelector('[data-nav-empty]');
navigationFilter?.addEventListener('input', () => {
    const query = navigationFilter.value.trim().toLocaleLowerCase();
    let visibleLinks = 0;
    navigation?.querySelectorAll('[data-nav-group]').forEach((group) => {
        let visible = 0;
        group.querySelectorAll('.navigation-links a').forEach((link) => {
            const matches = query === '' || link.textContent.trim().toLocaleLowerCase().includes(query);
            link.hidden = !matches;
            if (matches) {
                visible += 1;
                visibleLinks += 1;
            }
        });
        group.hidden = visible === 0;
        if (group instanceof HTMLDetailsElement) {
            group.open = query !== ''
                ? visible > 0
                : group.dataset.defaultOpen === 'true';
        }
    });
    if (navigationEmpty) navigationEmpty.hidden = visibleLinks !== 0;
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Tab' && navigation?.classList.contains('is-open')) {
        const focusable = [...navigation.querySelectorAll('a, button, input, summary, [tabindex="0"]')]
            .filter(element => !element.disabled && element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first?.focus();
        }
    }
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

/* Task 51: CSP-safe Task-49 Admin client-test runtime. No token is read or sent to Horus. */
const trafficGateTestButton = document.getElementById('traffic-gate-client-test');
if (trafficGateTestButton) {
    const status = document.getElementById('traffic-gate-client-test-status');
    const activate = document.getElementById('traffic-gate-activate');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const resultUrl = '/admin/operations/traffic-quality/sitekey/test-result';
    const protocolVersion = 2;
    let frame = null;
    let watchdog = null;
    let nonce = null;
    let running = false;

    const canonicalGateOrigin = (value) => {
        try {
            const parsed = new URL(String(value || ''));
            if (parsed.protocol !== 'https:' || parsed.hostname !== 'verify.horusmedia.net' || parsed.origin !== value) return null;
            if (parsed.username || parsed.password || parsed.port) return null;
            return parsed.origin;
        } catch {
            return null;
        }
    };

    const makeNonce = () => {
        const bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const record = async (result) => {
        if (status) status.textContent = result;
        if (activate) activate.disabled = result !== 'CLIENT PASS';
        const response = await window.fetch(resultUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf || '',
            },
            body: JSON.stringify({ result }),
        });
        if (!response.ok) throw new Error('Client test result could not be recorded.');
    };

    const cleanup = () => {
        if (watchdog) window.clearTimeout(watchdog);
        watchdog = null;
        window.removeEventListener('message', onMessage, false);
        if (frame?.parentNode) frame.parentNode.removeChild(frame);
        frame = null;
        running = false;
        trafficGateTestButton.disabled = false;
    };

    const finish = (result) => {
        if (!running) return;
        cleanup();
        record(result).catch(() => {
            if (status) status.textContent = `${result} · result audit could not be recorded`;
            if (activate) activate.disabled = true;
        });
    };

    function onMessage(event) {
        const origin = canonicalGateOrigin(trafficGateTestButton.dataset.origin);
        if (!frame || !origin || event.origin !== origin || event.source !== frame.contentWindow) return;
        const message = event.data;
        if (!message || typeof message !== 'object' || message.protocolVersion !== protocolVersion || message.pageNonce !== nonce) return;
        if (message.type === 'HORUS_TRAFFIC_GATE_PASS' && message.serverVerified === true) finish('CLIENT PASS');
        else if (message.type === 'HORUS_TRAFFIC_GATE_TIMEOUT') finish('CLIENT TIMEOUT');
        else if (message.type === 'HORUS_TRAFFIC_GATE_ERROR' || message.type === 'HORUS_TRAFFIC_GATE_DENIED') finish('CLIENT ERROR');
    }

    trafficGateTestButton.addEventListener('click', () => {
        if (running) return;
        const origin = canonicalGateOrigin(trafficGateTestButton.dataset.origin);
        if (!origin || !window.crypto?.getRandomValues) {
            record('GATE UNREACHABLE').catch(() => {
                if (status) status.textContent = 'GATE UNREACHABLE · result audit could not be recorded';
            });
            return;
        }

        running = true;
        trafficGateTestButton.disabled = true;
        if (activate) activate.disabled = true;
        if (status) status.textContent = 'Running server verification test…';
        nonce = makeNonce();
        frame = document.createElement('iframe');
        frame.src = `${origin}/traffic-gate/?protocol=2`;
        frame.title = 'Horus Traffic Gate Client Test';
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        frame.style.cssText = 'position:fixed;width:1px;height:1px;left:-10000px;top:-10000px;border:0;opacity:0;pointer-events:none';
        window.addEventListener('message', onMessage, false);
        frame.onerror = () => finish('GATE UNREACHABLE');
        frame.onload = () => frame.contentWindow?.postMessage({
            type: 'HORUS_TRAFFIC_GATE_HELLO',
            protocolVersion,
            pageNonce: nonce,
            sitePublicKey: 'admin-test',
            testMode: true,
            candidateSiteKey: trafficGateTestButton.dataset.candidate,
        }, origin);
        document.body.appendChild(frame);
        watchdog = window.setTimeout(() => finish('GATE UNREACHABLE'), 16000);
    });
}
