import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { recaptchaObservationRuntime } from '../../scripts/recaptcha-observation-runtime.mjs';
function harness({enabled=true,status='PASSED',existing=false,idle=true}={}) {
    const scripts=[],timers=new Map(),idleTasks=[];let seq=0,now=0;
    const config={status:'active',allowedHostnames:['publisher.test'],recaptchaObservation:{enabled,siteKey:'6LcTestObservationKey_123456789'}};
    const state={config};const gate={status};
    const w={document:{querySelector(){return existing;},createElement(tag){return {tagName:tag.toUpperCase(),setAttribute(){}};},head:{appendChild(s){if(s.tagName==='SCRIPT')scripts.push(s);}}},
        state,TRAFFIC_GATE_STATES:{passed:'PASSED'},trafficGateRuntimeState:()=>gate,hostAllowed:()=>true,currentHostname:()=> 'publisher.test',
        setTimeout(fn,ms){const id=++seq;timers.set(id,{fn,at:now+ms});return id;},clearTimeout(id){timers.delete(id);}};
    if(idle)w.requestIdleCallback=fn=>idleTasks.push(fn);
    w.window=w;vm.runInNewContext(recaptchaObservationRuntime,w);
    return {w,state,config,gate,scripts,schedule:()=>w.scheduleRecaptchaObservation(config),
        tick(ms){now+=ms;for(const [id,t]of timers)if(t.at<=now){timers.delete(id);t.fn();}},idle(){idleTasks.splice(0).forEach(f=>f());}};
}
for(const status of ['DISABLED','PENDING','ERROR','DENIED','TIMEOUT']) test(`no SDK or work for gate ${status}`,()=>{
    const h=harness({status});h.schedule();h.tick(20000);h.idle();assert.equal(h.state.recaptchaObservation,undefined);assert.equal(h.scripts.length,0);
});
test('disabled default creates no background task',()=>{const h=harness({enabled:false});h.schedule();assert.equal(h.state.recaptchaObservation,undefined);});
test('one deferred execution per document discards token and never changes PASS',async()=>{
    const h=harness();h.schedule();h.schedule();assert.equal(h.scripts.length,0);h.tick(1000);assert.equal(h.scripts.length,0);h.idle();assert.equal(h.scripts.length,1);
    let calls=0;h.w.grecaptcha={ready:f=>f(),execute:(key,o)=>{calls++;assert.equal(key,h.config.recaptchaObservation.siteKey);assert.equal(o.action,'horus_cf_pass');return Promise.resolve('DO_NOT_STORE_TOKEN');}};
    h.scripts[0].onload();await new Promise(resolve=>setImmediate(resolve));h.schedule();assert.equal(calls,1);assert.equal(h.state.recaptchaObservation.phase,'completed');assert.equal(h.gate.status,'PASSED');assert.equal(JSON.stringify(h.state).includes('DO_NOT_STORE_TOKEN'),false);
});
test('existing publisher CAPTCHA is never touched',()=>{const h=harness({existing:true});h.schedule();h.tick(1000);h.idle();assert.equal(h.scripts.length,0);assert.equal(h.state.recaptchaObservation.phase,'publisher_recaptcha_present');});
test('configuration replacement before idle cancels old work',()=>{const h=harness();h.schedule();h.state.config={...h.config};h.tick(1000);h.idle();assert.equal(h.scripts.length,0);});
test('PASS retirement during SDK download prevents execution',()=>{const h=harness();h.schedule();h.tick(1000);h.idle();h.gate.status='ERROR';h.scripts[0].onload();assert.equal(h.state.recaptchaObservation.phase,'cancelled');});
for(const failure of ['throw','reject','network','timeout'])test(`optional failure ${failure} leaves PASS untouched`,async()=>{
    const h=harness({idle:false});h.schedule();h.tick(1000);const s=h.scripts[0];
    if(failure==='network')s.onerror();
    else if(failure==='timeout')h.tick(10000);
    else {h.w.grecaptcha={ready:f=>f(),execute:()=>{if(failure==='throw')throw Error('provider error');return Promise.reject(Error('provider error'));}};s.onload();await new Promise(resolve=>setImmediate(resolve));}
    assert.equal(h.gate.status,'PASSED');assert.ok(['timeout','unavailable'].includes(h.state.recaptchaObservation.phase));
});
test('late ready callback after timeout cannot execute',()=>{const h=harness();let ready,calls=0;h.schedule();h.tick(1000);h.idle();h.w.grecaptcha={ready:f=>{ready=f;},execute:()=>calls++};h.scripts[0].onload();h.tick(10000);ready();assert.equal(calls,0);});
