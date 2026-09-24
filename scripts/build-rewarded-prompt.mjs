import { readFile, writeFile } from 'node:fs/promises';

const source = (await readFile(new URL('../resources/js/ads/rewarded-prompt.js', import.meta.url), 'utf8')).trim();
const start = '    // BEGIN SHARED REWARDED PROMPT';
const end = '    // END SHARED REWARDED PROMPT';
for (const file of ['hm-gpt-direct.js', 'hm-video-direct.js']) {
    const path = new URL(`../public/assets/${file}`, import.meta.url);
    const runtime = await readFile(path, 'utf8');
    const from = runtime.indexOf(start), to = runtime.indexOf(end);
    if (from < 0 || to < from) throw new Error(`Missing rewarded prompt build markers in ${file}`);
    const replacement = `${start}\n${source.split('\n').map(line => `    ${line}`).join('\n')}\n${end}`;
    await writeFile(path, runtime.slice(0, from) + replacement + runtime.slice(to + end.length));
}
