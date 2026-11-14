import { summarizeStartupTrace } from '../../scripts/startup-trace-summary.mjs';

const input = document.querySelector('[data-startup-trace-input]');
const button = document.querySelector('[data-startup-trace-analyze]');
const output = document.querySelector('[data-startup-trace-result]');
button?.addEventListener('click', () => {
    if (!input || !output) return;
    try {
        if (input.value.length > 65536) throw new Error('oversized');
        output.textContent = JSON.stringify(summarizeStartupTrace(JSON.parse(input.value)), null, 2);
    } catch {
        output.textContent = 'Trace could not be analyzed. Paste a schema-1 startup trace with at most 160 events and 64 KB.';
    }
});
