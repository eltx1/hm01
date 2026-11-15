import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {transformWithEsbuild} from 'vite';
const summary = (await readFile(new URL('./startup-trace-summary.mjs', import.meta.url), 'utf8')).replace('export function summarizeStartupTrace', 'function summarizeStartupTrace');
const observer = await readFile(new URL('../resources/js/ads/performance-observer.js', import.meta.url), 'utf8');
const result = await transformWithEsbuild('(function(){\n'+summary+'\n'+observer+'\n})();', 'hm-performance.js', {minify: true, target: 'es2018'});
const bytes=result.code+'\n';
await writeFile(new URL('../public/assets/hm-performance.js', import.meta.url), bytes);
// Keep committed content-addressed generations: stored site configurations may
// still refer to an earlier build until they are explicitly republished.
const directory=new URL('../public/assets/performance/',import.meta.url);
await mkdir(directory,{recursive:true});
const archive=new URL('hm-performance.'+createHash('sha256').update(bytes).digest('hex').slice(0,16)+'.js',directory);
try {await writeFile(archive,bytes,{flag:'wx'});}
catch(error){if(error.code!=='EEXIST'||await readFile(archive,'utf8')!==bytes)throw error;}
