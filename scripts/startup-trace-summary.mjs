// Operator-invoked analysis only. Never imported into the advertising Loader.
const phases = new Set(['Horus init', 'CFG start', 'CFG ready', 'CFG error', 'CF start', 'CF retry', 'CF pass', 'CF reject', 'CF error', 'CF timeout', 'Core handoff', 'Core ready', 'Core error', 'GPT load', 'GPT ready', 'GPT request', 'GPT response', 'GPT render', 'GPT empty', 'GPT error', 'VAST call', 'VAST no-fill', 'VAST error']);
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
    const configStart = configStarts.at(-1);
    const readyAt = resource => !configStart || (configStart.attempt === null && configStarts.length > 1) ? null
        : events.find(event => event.phase === 'CFG ready' && event.resource === resource && event.attempt === configStart.attempt && event.ms >= configStart.ms)?.ms ?? null;
    const configReady = readyAt('config'), controlsReady = readyAt('controls');
    const configDuration = elapsed(configStart?.ms ?? null, configReady);
    const controlsDuration = elapsed(configStart?.ms ?? null, controlsReady);
    const init = first('Horus init');
    const pass = first('CF pass');
    const cfEnd = events.find(event => ['CF pass', 'CF reject', 'CF error', 'CF timeout'].includes(event.phase));
    const slots = new Map();
    for (const event of events) {
        if (event.slot === null || !event.phase.startsWith('GPT ')) continue;
        if (!slots.has(event.slot)) slots.set(event.slot, { slot: event.slot, requested: null, response: null, rendered: null, empty: null, error: null });
        const slot = slots.get(event.slot);
        const field = { 'GPT request': 'requested', 'GPT response': 'response', 'GPT render': 'rendered', 'GPT empty': 'empty', 'GPT error': 'error' }[event.phase];
        if (field && slot[field] === null) slot[field] = event.ms;
    }
    return { schema: 1, scope: 'One supplied page trace; not population statistics or a fraud score',
        events_analyzed: events.length, dropped: Number.isSafeInteger(input.dropped) && input.dropped >= 0 ? input.dropped : 0,
        timing_ms: { config: configDuration, controls: controlsDuration,
            boot_preparation: configReady !== null && controlsReady !== null ? elapsed(configStart.ms, Math.max(configReady, controlsReady)) : null,
            cloudflare_total: elapsed(first('CF start'), cfEnd?.ms ?? null),
            after_pass_to_first_request: elapsed(pass, first('GPT request')),
            page_init_to_first_nonempty_render: elapsed(init, first('GPT render')),
            gpt_library: elapsed(first('GPT load'), first('GPT ready')) },
        cloudflare_outcome: cfEnd?.phase ?? 'No terminal decision recorded',
        retries_recorded: events.filter(event => event.phase === 'CF retry').length,
        video_no_fill_recorded: events.filter(event => event.phase === 'VAST no-fill').length,
        slots: [...slots.values()].map(slot => ({ slot: slot.slot,
            request_to_response_ms: elapsed(slot.requested, slot.response),
            request_to_nonempty_render_ms: elapsed(slot.requested, slot.rendered),
            nonempty_render_recorded: slot.rendered !== null, no_fill_recorded: slot.empty !== null,
            error_recorded: slot.error !== null })),
    };
}
