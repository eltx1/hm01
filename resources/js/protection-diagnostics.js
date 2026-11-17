import { summarizeProtectionEvidence } from '../../scripts/protection-evidence-summary.mjs';

const input = document.querySelector('[data-protection-input]');
const button = document.querySelector('[data-protection-analyze]');
const output = document.querySelector('[data-protection-result]');
if (input && button && output) button.addEventListener('click', () => {
    try {
        if (input.value.length > 262144) throw new Error('LIMIT');
        output.textContent = JSON.stringify(summarizeProtectionEvidence(JSON.parse(input.value)), null, 2);
    } catch {
        output.textContent = 'Evidence could not be analyzed. Use schema 1, 1–100 samples, at most 160 trace events each, and no more than 256 KB. No input was submitted.';
    }
});
