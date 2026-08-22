import './bootstrap';
import '../css/publisher-application.css';
import '../css/ux-launch.css';
import '../css/interface-density.css';

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

/* Task 51: CSP-safe Task-49 Admin client-test runtime. No token is read or sent to Horus. */
const trafficGateTestButton = document.getElementById('traffic-gate-client-test');
if (trafficGateTestButton) {
    const status = document.getElementById('traffic-gate-client-test-status');
    const activate = document.getElementById('traffic-gate-activate');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const resultUrl = '/admin/operations/traffic-quality/sitekey/test-result';
    const protocolVersion = 1;
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
        if (message.type === 'HORUS_TRAFFIC_GATE_PASS') finish('CLIENT PASS');
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
        if (status) status.textContent = 'Running client-only test…';
        nonce = makeNonce();
        frame = document.createElement('iframe');
        frame.src = `${origin}/traffic-gate/`;
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
