import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
import {performanceObservationRuntime} from '../../scripts/performance-observation-runtime.mjs';
const source = readFileSync(new URL('../../public/assets/hm-performance.js', import.meta.url), 'utf8');
const perf = {enabled:true,sampleBp:100,collectorOrigin:'https://metrics.horusmedia.net',scriptUrl:'https://cdn.horusmedia.net/runtime/performance/hm-performance.0123456789abcdef.js'};
function harness({enabled=true,status='PASSED',random=0}={}) {
    let now=0,seq=0; const timers=new Map(),scripts=[],requests=[],idle=[];
    const config={siteKey:'hm_performance_test',configVersion:7,status:'active',allowedHostnames:['publisher.test'],observability:{performance:{...perf,enabled}}};
    const state={config}, gate={status};
    let trace={schema:1,dropped:0,events:[['Horus init',0],['CFG start',1],['CFG ready',20],['CF start',21],['CF pass',200],['GPT request',210],['GPT render',500]].map(([phase,ms])=>({phase,ms,slot:phase.startsWith('GPT')?1:null,resource:phase==='CFG ready'?'config':null}))};
    const w={Uint32Array,Date:{now:()=>now},crypto:{getRandomValues(a){a[0]=random;return a;}},state,
        TRAFFIC_GATE_STATES:{passed:'PASSED'},trafficGateRuntimeState:()=>gate,hostAllowed:()=>true,currentHostname:()=> 'publisher.test',
        location:{hostname:'publisher.test'},document:{createElement(){return {setAttribute(){}};},head:{appendChild(s){scripts.push(s);}}},
        setTimeout(fn,ms){const id=++seq;timers.set(id,{fn,at:now+ms});return id;},
        requestIdleCallback(fn){idle.push(fn);}, fetch(url,options){requests.push({url,options});return Promise.reject(Error('PRIVATE_NETWORK_ERROR'));},
        HorusMediaLoader:{getConfig:()=>state.config,getTrafficGateState:()=>({state:gate.status}),getStartupTrace:()=>trace}};
    w.window=w; vm.runInNewContext(performanceObservationRuntime,w);
    return {w,config,state,gate,scripts,requests,timers,schedule:()=>w.schedulePerformanceObservation(config),run:()=>vm.runInNewContext(source,w),setTrace:value=>trace=value,
        tick(ms){now+=ms;for(const [id,timer]of [...timers])if(timer.at<=now){timers.delete(id);timer.fn();}},idle(){idle.splice(0).forEach(fn=>fn());}};
}
for(const status of ['DISABLED','PENDING','ERROR','TIMEOUT','BLOCKED']) test('no optional work without verified PASS: '+status,()=>{
    const h=harness({status});h.schedule();assert.equal(h.timers.size,0);assert.equal(h.state.performanceObservationAttempted,undefined);
});
test('disabled default creates no timers or network',()=>{
    const h=harness({enabled:false});h.schedule();assert.equal(h.timers.size,0);assert.equal(h.scripts.length,0);
});
test('one sampled script is deferred; unsampled pages create no timer',()=>{
    const h=harness();h.schedule();h.schedule();assert.equal(h.scripts.length,0);h.tick(1000);assert.equal(h.scripts.length,0);h.idle();assert.equal(h.scripts.length,1);
    const excluded=harness({random:4294967295});excluded.schedule();assert.equal(excluded.timers.size,0);
});
test('configuration replacement or PASS retirement cancels deferred work',()=>{
    for(const changed of ['config','gate']) {const h=harness();h.schedule();if(changed==='config')h.state.config={...h.config};else h.gate.status='ERROR';h.tick(1000);h.idle();assert.equal(h.scripts.length,0);}
});
test('untrusted asset or collector never creates optional work',()=>{
    for(const change of [{scriptUrl:'https://evil.test/script.js'},{collectorOrigin:'https://evil.test'},{sampleBp:1001}]){
        const h=harness();Object.assign(h.config.observability.performance,change);h.schedule();assert.equal(h.timers.size,0);
    }
});
test('observer sends only metrics once and does not retry a failed observation request',async()=>{
    const h=harness();h.run();h.run();await Promise.resolve();assert.equal(h.requests.length,1);h.tick(60000);assert.equal(h.requests.length,1);
    const {url,options}=h.requests[0];assert.equal(url,'https://metrics.horusmedia.net/collect/hm_performance_test');
    assert.equal(options.credentials,'omit');assert.equal(options.referrerPolicy,'no-referrer');assert.equal(options.redirect,'error');
    assert.deepEqual(JSON.parse(options.body),{schema:1,configVersion:7,sampleBp:100,metrics:{config:19,cloudflare_total:179,first_ad:500,after_pass:10},outcome:'rendered'});
    assert.equal(h.gate.status,'PASSED');
});
test('no GPT render remains incomplete after a bounded window, never zero or an ad failure',()=>{
    const h=harness();h.setTrace({schema:1,events:[{phase:'CF start',ms:0},{phase:'CF pass',ms:200}]});h.run();assert.equal(h.requests.length,0);h.tick(30000);
    assert.deepEqual(JSON.parse(h.requests[0].options.body).metrics,{cloudflare_total:200});assert.equal(JSON.parse(h.requests[0].options.body).outcome,'incomplete');
});
test('truncated, malformed and retired observations send nothing',()=>{
    for(const trace of [{schema:1,dropped:2,events:[]},{schema:1,events:[{phase:'PRIVATE',ms:-1}]}]){
        const h=harness();h.setTrace(trace);h.run();h.tick(30000);assert.equal(h.requests.length,0);
    }
    const h=harness();h.gate.status='TIMEOUT';h.run();assert.equal(h.requests.length,0);
});
test('exceptions and stalled transport cannot alter the admission state',()=>{
    for(const failure of ['throw','stalled']){
        const h=harness();h.w.fetch=()=>{if(failure==='throw')throw Error('PRIVATE');return new Promise(()=>{});};assert.doesNotThrow(h.run);assert.equal(h.gate.status,'PASSED');assert.equal(h.timers.size,0);
    }
});
