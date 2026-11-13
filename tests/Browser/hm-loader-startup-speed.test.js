import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';
import {applyTrafficGateTransform} from '../../scripts/transform-loader-traffic-gate.mjs';
const source=applyTrafficGateTransform(await readFile(new URL('../../public/assets/hm-loader.js',import.meta.url),'utf8'));
const base='https://cdn.horusmedia.net/configs/FAST';
const config={siteKey:'FAST',configVersion:7,status:'active',allowedHostnames:['publisher.example'],controls:{},trafficGate:{enabled:false},placements:[]};
const manifest={siteKey:'FAST',environments:{production:{version:7,path:'/configs/FAST/production.v7.aaaaaaaaaaaaaaaa.json',sha256:'a'.repeat(64)}}};
function setup(respond) {
    let now=0,sequence=0; const timers=new Map(),calls=[],hints=[];
    const script={src:'https://cdn.horusmedia.net/hm-loader.js',dataset:{siteKey:'FAST'},getAttribute(){return null;}};
    const parent={appendChild(n){hints.push(n);}};
    const document={readyState:'complete',head:parent,documentElement:parent,currentScript:script,querySelectorAll(){return [];},
        createElement(){return {attributes:{},setAttribute(k,v){this.attributes[k]=v;}};}};
    const w={URL,AbortController,document,location:{href:'https://publisher.example/article',hostname:'publisher.example'},
        __HM_DISABLE_AUTOBOOT__:true,setTimeout(fn,ms){const id=++sequence;timers.set(id,{fn,at:now+ms});return id;},clearTimeout(id){timers.delete(id);},
        fetch(url,options){calls.push({url,options,at:now});return respond(url,options);}};
    w.window=w;
    vm.runInNewContext(source.replace('    window.HorusMediaLoader = {','    window.speed={fetchConfig,prepareStaticConnections,delegatedReleaseUrl};\n    window.HorusMediaLoader = {'),w);
    const flush=async()=>{for(let n=0;n<25;n++)await Promise.resolve();};
    return {w,calls,hints,script,flush,fetch:()=>w.speed.fetchConfig(script,'FAST',false),
        async tick(ms){const end=now+ms;await flush();while(true){const next=[...timers].filter(([,t])=>t.at<=end).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;now=next[1].at;timers.delete(next[0]);next[1].fn();await flush();}now=end;await flush();}};
}
const json=value=>Promise.resolve({ok:true,json:async()=>value});
test('fast manifest path makes no speculative alias request',async()=>{
    const h=setup(url=>json(url.endsWith('manifest.json')?manifest:config));
    assert.equal((await h.fetch()).configVersion,7);await h.tick(10000);
    assert.equal(h.calls.length,2);assert.equal(h.calls.some(c=>c.url===base+'/production.json'),false);
});
test('slow manifest overlaps one fresh alias read but never trusts it before manifest failure',async()=>{
    const h=setup(url=>url.endsWith('manifest.json')?new Promise(()=>{}):json(config));
    let ready=false;const pending=h.fetch().then(c=>{ready=true;return c;});
    await h.tick(750);assert.equal(h.calls.length,2);assert.equal(ready,false);
    assert.equal(h.calls[1].options.cache,'reload');
    await h.tick(4250);assert.equal((await pending).configVersion,7);
    assert.equal(h.calls.filter(c=>c.url.endsWith('manifest.json')).length,1);
    console.log('CONTROLLED_CONFIG_FALLBACK_MS=5000 (one manifest timeout; alias already ready)');
});
for(const version of [6,7,8])test(`fallback version ${version} cannot roll back manifest 7`,async()=>{
    const h=setup(url=>url.endsWith('manifest.json')?json(manifest):url.includes('.v7.')?Promise.reject(new Error('edge failure')):json({...config,configVersion:version}));
    if(version<7)await assert.rejects(h.fetch(),/older than/);else assert.equal((await h.fetch()).configVersion,version);
});
test('a speculative alias cannot override a successful authoritative config',async()=>{
    let release;const h=setup(url=>url.endsWith('manifest.json')?new Promise(r=>{release=()=>r({ok:true,json:async()=>manifest});}):json(url.includes('.v7.')?config:{...config,configVersion:99}));
    const pending=h.fetch();await h.tick(750);release();await h.flush();
    assert.equal((await pending).configVersion,7);
});
test('the delegated release is preloaded with the identical execution URL but never executed',()=>{
    const h=setup(()=>json(config));const c={...config,loader:{version:'3.0.0',assetUrl:'https://cdn.horusmedia.net/assets/loader/hm-loader.0123456789abcdef.min.js'}};
    h.w.speed.prepareStaticConnections(c,false);
    assert.equal(h.hints.length,1);
    assert.equal(h.hints[0].attributes.href,h.w.speed.delegatedReleaseUrl(c,h.script));
    assert.equal(h.hints[0].attributes.rel,'preload');
    assert.equal(h.w.__HORUS_MEDIA_LOADER_STATE__.adInitializationStarted,false);
});
test('untrusted, credentialed and non-HTTPS release URLs cannot be preloaded',()=>{
    for(const url of ['https://evil.example/assets/hm-loader.min.js','http://cdn.horusmedia.net/assets/hm-loader.min.js','https://user:pass@cdn.horusmedia.net/assets/hm-loader.min.js','https://cdn.horusmedia.net/api/loader.js']){
        const h=setup(()=>json(config));h.w.speed.prepareStaticConnections({...config,loader:{version:'3',assetUrl:url}},false);assert.equal(h.hints.length,0);
    }
});
