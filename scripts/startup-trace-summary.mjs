// Operator-invoked analysis only. Never imported into the advertising Loader.
const phases = new Set(['Horus init', 'CFG start', 'CFG ready', 'CFG error', 'CF start', 'CF retry', 'CF pass', 'CF reject', 'CF error', 'CF timeout', 'Core handoff', 'Core ready', 'Core error', 'GPT load', 'GPT ready', 'GPT request', 'GPT response', 'GPT render', 'GPT empty', 'GPT error', 'Prebid start', 'Prebid no-bid', 'Prebid timeout', 'Prebid error', 'Prebid frame', 'Direct start', 'Direct candidate failed', 'Direct exhausted', 'Direct render signal', 'House render', 'Video start', 'VAST call', 'VAST no-fill', 'VAST error']);
export function summarizeStartupTrace(input) {
    if (!input || input.schema !== 1 || !Array.isArray(input.events) || input.events.length > 160) throw new Error('Unsupported or oversized startup trace.');
    let previous = -1;
    const events = input.events.map(event => {
        if (!event || typeof event.ms !== 'number' || !Number.isFinite(event.ms) || event.ms < previous || event.ms < 0 || event.ms > 86400000 || typeof event.phase !== 'string') throw new Error('Invalid startup event timing.');
        previous = event.ms;
        return { ms: event.ms, phase: phases.has(event.phase) ? event.phase : null,
            resource: ['controls', 'config'].includes(event.resource) ? event.resource : null,
            attempt: Number.isSafeInteger(event.attempt) && event.attempt > 0 && event.attempt <= 1000000 ? event.attempt : null,
            slot: Number.isSafeInteger(event.slot) && event.slot > 0 && event.slot <= 1000000 ? event.slot : null };
    }).filter(event => event.phase);
    const first = phase => events.find(event => event.phase === phase)?.ms ?? null;
    const elapsed = (start, end) => start !== null && end !== null && end >= start ? end - start : null;
    const configStarts = events.filter(event => event.phase === 'CFG start');
    const configStart = configStarts[configStarts.length - 1];
    const readyAt = resource => !configStart || (configStart.attempt === null && configStarts.length > 1) ? null
        : events.find(event => event.phase === 'CFG ready' && event.resource === resource && event.attempt === configStart.attempt && event.ms >= configStart.ms)?.ms ?? null;
    const configReady = readyAt('config'), controlsReady = readyAt('controls');
    const configDuration = elapsed(configStart?.ms ?? null, configReady);
    const controlsDuration = elapsed(configStart?.ms ?? null, controlsReady);
    const init = first('Horus init');
    const firstRequest = first('GPT request');
    // A failed attempt can be followed by a verified retry. Describe the last
    // decision before this page's first request, rather than its first failure.
    const decisions = events.filter(event => ['CF pass', 'CF reject', 'CF error', 'CF timeout'].includes(event.phase)
        && (firstRequest === null || event.ms <= firstRequest));
    const cfEnd = decisions[decisions.length - 1];
    const pass = cfEnd?.phase === 'CF pass' ? cfEnd.ms : null;
    const slots = new Map();
    for (const event of events) {
        if (event.slot === null || !event.phase.startsWith('GPT ')) continue;
        if (!slots.has(event.slot)) slots.set(event.slot, { slot: event.slot, requested: null, response: null, rendered: null, empty: null, error: null });
        const slot = slots.get(event.slot);
        const field = { 'GPT request': 'requested', 'GPT response': 'response', 'GPT render': 'rendered', 'GPT empty': 'empty', 'GPT error': 'error' }[event.phase];
        if (field && slot[field] === null) slot[field] = event.ms;
    }
    const independent = (start, signal, noFill, error, timeout, emptyLabel, errorLabel) => {
        const entries = new Map();
        for (const event of events) {
            if (event.slot === null || ![start, signal, noFill, error, timeout].includes(event.phase)) continue;
            if (!entries.has(event.slot)) entries.set(event.slot, { slot: event.slot, started: null, signal: null, no_fill_recorded: false, error_recorded: false, timeout_recorded: false });
            const entry = entries.get(event.slot);
            if (event.phase === start && entry.started === null) entry.started = event.ms;
            if (event.phase === signal && entry.signal === null) entry.signal = event.ms;
            if (event.phase === noFill) entry.no_fill_recorded = true;
            if (event.phase === error) entry.error_recorded = true;
            if (event.phase === timeout) entry.timeout_recorded = true;
        }
        return [...entries.values()].map(entry => ({ slot: entry.slot,
            start_to_signal_ms: elapsed(entry.started, entry.signal), signal_recorded: entry.signal !== null,
            [emptyLabel]: entry.no_fill_recorded, [errorLabel]: entry.error_recorded, timeout_recorded: entry.timeout_recorded }));
    };
    return { schema: 1, scope: 'One supplied page trace; not population statistics or a fraud score',
        events_analyzed: events.length, dropped: Number.isSafeInteger(input.dropped) && input.dropped >= 0 ? input.dropped : 0,
        timing_ms: { config: configDuration, controls: controlsDuration,
            boot_preparation: configReady !== null && controlsReady !== null ? elapsed(configStart.ms, Math.max(configReady, controlsReady)) : null,
            cloudflare_total: elapsed(first('CF start'), cfEnd?.ms ?? null),
            after_pass_to_first_request: elapsed(pass, firstRequest),
            page_init_to_first_nonempty_render: elapsed(init, first('GPT render')),
            gpt_library: elapsed(first('GPT load'), first('GPT ready')) },
        cloudflare_outcome: cfEnd?.phase ?? 'No terminal decision recorded',
        engine_evidence: { scope: 'Bounded local startup evidence; signals are not verified impressions or provider revenue',
            prebid_standalone: independent('Prebid start', 'Prebid frame', 'Prebid no-bid', 'Prebid error', 'Prebid timeout', 'no_valid_bid_recorded', 'error_recorded'),
            direct_js: independent('Direct start', 'Direct render signal', 'Direct exhausted', 'Direct candidate failed', null, 'candidates_exhausted_recorded', 'candidate_failure_recorded'),
            house_signals_recorded: events.filter(event => event.phase === 'House render').length,
            video_starts_recorded: events.filter(event => event.phase === 'Video start').length },
        retries_recorded: events.filter(event => event.phase === 'CF retry').length,
        video_no_fill_recorded: events.filter(event => event.phase === 'VAST no-fill').length,
        slots: [...slots.values()].map(slot => ({ slot: slot.slot,
            request_to_response_ms: elapsed(slot.requested, slot.response),
            request_to_nonempty_render_ms: elapsed(slot.requested, slot.rendered),
            nonempty_render_recorded: slot.rendered !== null, no_fill_recorded: slot.empty !== null,
            error_recorded: slot.error !== null })),
    };
}
