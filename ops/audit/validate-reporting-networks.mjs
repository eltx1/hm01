import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

export function validate(report) {
    assert.deepEqual(Object.keys(report).sort(), ['groups', 'release', 'schema_version']);
    assert.equal(report.schema_version, 1);
    assert.match(report.release, /^[a-f0-9]{40}$/);
    assert.ok(report.groups && !Array.isArray(report.groups) && typeof report.groups === 'object');
    assert.ok(Object.keys(report.groups).length <= 2000);
    const columns = [
        ['MAIN', 'VIDEO'], ['GBP', 'AED', 'OTHER'], ['ACTIVE', 'HISTORICAL'],
        ['CURRENT', 'FUTURE', 'ENDED', 'UNKNOWN'],
        ['NONE', 'OTHER', 'MISSING_CONNECTION', 'DISABLED', 'NON_CANONICAL_CURRENCY', 'AUTHORIZATION',
            'CURRENCY_PROOF', 'INVALID_MONEY', 'NETWORK_IDENTITY', 'REPORT_WINDOW', 'REPORT_DIMENSIONS',
            'CSV_SHAPE', 'REPORT_SCOPE', 'DOWNLOAD', 'GOOGLE_JOB', 'LOCK', 'QUOTA'],
        ['TODAY', 'YESTERDAY', 'OLDER', 'NONE'],
    ];
    for (const [key, count] of Object.entries(report.groups)) {
        const parts = key.split('|');
        assert.equal(parts.length, columns.length);
        parts.forEach((part, i) => assert.ok(columns[i].includes(part)));
        assert.ok(Number.isSafeInteger(count) && count > 0 && count <= 2000);
    }
    return report;
}

if (process.argv[1]?.endsWith('validate-reporting-networks.mjs')) {
    try {
        console.log(JSON.stringify(validate(JSON.parse(readFileSync(process.argv[2], 'utf8'))), null, 2));
    } catch {
        console.error('Reporting diagnosis failed disclosure validation; raw output withheld.');
        process.exitCode = 1;
    }
}
