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

// Local diagnostics: HorusMediaLoader.getRecaptchaObservation() explains each outcome
// without changing the PASS-gated flow and without recording the token or the key.
const debug = h => JSON.parse(JSON.stringify(h.w.recaptchaObservationDebugState()));
test('before Cloudflare PASS the console explains that observation is waiting',()=>{
    const h=harness({status:'PENDING'});assert.equal(debug(h).phase,'not_scheduled');assert.equal(debug(h).reason,'WAITING_FOR_CLOUDFLARE_PASS');
    h.schedule();assert.equal(h.scripts.length,0);assert.deepEqual([debug(h).phase,debug(h).reason,debug(h).cloudflare],['not_started','CLOUDFLARE_PENDING','PENDING']);
    assert.equal(h.state.recaptchaObservation,undefined,'a skipped attempt must not block a later PASS');
    h.gate.status='PASSED';h.schedule();h.tick(1000);h.idle();assert.equal(h.scripts.length,1);assert.equal(debug(h).phase,'loading');
});
test('disabled site and invalid key are reported',()=>{
    assert.equal(debug(harness({enabled:false})).reason,'DISABLED_FOR_SITE');
    const h=harness();h.config.recaptchaObservation.siteKey='short';h.schedule();assert.equal(debug(h).reason,'INVALID_SITE_KEY');assert.equal(h.scripts.length,0);
});
test('completed observation reports a received token without storing it',async()=>{
    const h=harness();h.schedule();h.tick(1000);h.idle();h.w.grecaptcha={ready:f=>f(),execute:()=>Promise.resolve('SECRET_TOKEN_VALUE_123')};h.scripts[0].onload();await new Promise(r=>setImmediate(r));
    const d=debug(h);assert.deepEqual([d.phase,d.reason,d.tokenReceived,d.cloudflare,d.action],['completed',null,true,'PASSED','horus_cf_pass']);
    assert.equal(JSON.stringify(h.state).includes('SECRET_TOKEN_VALUE_123')||JSON.stringify(d).includes('SECRET_TOKEN_VALUE_123'),false);
    assert.equal(JSON.stringify(d).includes(h.config.recaptchaObservation.siteKey),false);
});
test('an empty token is distinguished from success',async()=>{
    const h=harness();h.schedule();h.tick(1000);h.idle();h.w.grecaptcha={ready:f=>f(),execute:()=>Promise.resolve('')};h.scripts[0].onload();await new Promise(r=>setImmediate(r));
    assert.deepEqual([debug(h).phase,debug(h).reason,debug(h).tokenReceived],['completed','EMPTY_TOKEN',false]);
});
test('Google rejection keeps its message with the site key redacted',async()=>{
    const h=harness();const key=h.config.recaptchaObservation.siteKey;h.schedule();h.tick(1000);h.idle();
    h.w.grecaptcha={ready:f=>f(),execute:()=>Promise.reject(new Error('Invalid site key or not loaded in api.js: '+key))};h.scripts[0].onload();await new Promise(r=>setImmediate(r));
    const d=debug(h);assert.deepEqual([d.phase,d.reason],['unavailable','EXECUTE_REJECTED']);assert.equal(d.error,'Invalid site key or not loaded in api.js: <site-key>');
});
test('each stall point has its own timeout reason',()=>{
    const load=harness();load.schedule();load.tick(1000);load.idle();load.tick(10000);assert.equal(debug(load).reason,'SDK_LOAD_TIMEOUT');
    const ready=harness();ready.schedule();ready.tick(1000);ready.idle();ready.w.grecaptcha={ready(){},execute(){}};ready.scripts[0].onload();ready.tick(10000);assert.equal(debug(ready).reason,'GOOGLE_READY_TIMEOUT');
    const exec=harness();exec.schedule();exec.tick(1000);exec.idle();exec.w.grecaptcha={ready:f=>f(),execute:()=>new Promise(()=>{})};exec.scripts[0].onload();exec.tick(10000);
    assert.deepEqual([debug(exec).phase,debug(exec).reason],['timeout','EXECUTE_TIMEOUT']);
});
test('network failure, cancellation and publisher CAPTCHA have explicit reasons',()=>{
    const net=harness();net.schedule();net.tick(1000);net.idle();net.scripts[0].onerror();assert.equal(debug(net).reason,'SDK_NETWORK_ERROR');
    const swap=harness();swap.schedule();swap.state.config={...swap.config};swap.tick(1000);swap.idle();assert.equal(swap.state.recaptchaObservation.reason,'CONFIG_REPLACED');
    const gate=harness();gate.schedule();gate.tick(1000);gate.idle();gate.gate.status='ERROR';gate.scripts[0].onload();assert.equal(debug(gate).reason,'CLOUDFLARE_ERROR');
    const pub=harness({existing:true});pub.schedule();pub.tick(1000);pub.idle();assert.equal(debug(pub).reason,'PUBLISHER_RECAPTCHA_PRESENT');
});
test('Google request counts come from local resource timing only',()=>{
    const h=harness();h.w.performance={now:()=>1234.4,getEntriesByType:()=>[{name:'https://www.google.com/recaptcha/api2/anchor?k=x'},{name:'https://www.google.com/recaptcha/api2/reload?k=x'},{name:'https://example.com/recaptcha/api2/reload'}]};
    h.schedule();assert.deepEqual(debug(h).googleRequests,{anchor:1,reload:1});assert.equal(debug(h).timingsMs.scheduled,1234);
});
test('debug-enabled sites log each phase to the console',async()=>{
    const h=harness();const lines=[];h.w.log=(config,...args)=>lines.push(args.join(' '));h.schedule();h.tick(1000);h.idle();
    h.w.grecaptcha={ready:f=>f(),execute:()=>Promise.resolve('t')};h.scripts[0].onload();await new Promise(r=>setImmediate(r));
    assert.deepEqual(lines.map(l=>l.split(' ').slice(0,3).join(' ').trim()),['reCAPTCHA observation loading','reCAPTCHA observation executing','reCAPTCHA observation completed']);
});
