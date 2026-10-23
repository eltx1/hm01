import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

// Fault injection against fake Google responses; never sends an ad request.
const source = await readFile(new URL('../../public/assets/hm-gpt-direct.js', import.meta.url), 'utf8');
const actualSizes = [[300,250],[336,280],[320,100],[320,50],[300,100],[300,50],[250,250],[200,200],[240,400],[250,360],[728,90],[970,250],[970,90],[468,60],[300,600],'fluid'];

function harness(fault = '', options = {}) {
    const attributes = {
        'data-hm-gpt-direct': '1',
        'data-hm-gpt-ad-unit-path': '/1234567/audit/display',
        'data-hm-gpt-inner-id': 'audit-inner',
        'data-hm-gpt-sizes': JSON.stringify(actualSizes),
        'data-hm-gpt-fit-container': '1',
        'data-hm-gpt-responsive-fluid': '1',
        'data-hm-serving-context': '1',
    };
    const root = { clientWidth: 720, getBoundingClientRect: () => ({left:314,width:720}) };
    const target = {
        id:'audit-placement', style:{}, parentElement:root,
        getAttribute: name => attributes[name] ?? null,
        setAttribute: (name,value) => {
            if (options.diagnosticsThrow && name.startsWith('data-hm-gpt-failure-')) throw new Error('diagnostic-write-blocked');
            attributes[name] = String(value);
        },
        closest: () => root,
        getBoundingClientRect: () => ({left:314,width:720}),
        __hmCanRequestAds: () => options.allowed !== false,
    };
    const traces = [], requests = [], destroyed = [], scripts = [], handlers = [];
    let slot;
    const fail = () => { throw new Error('private-provider-token https://private.example/error'); };
    const pubads = {
        addEventListener(name, fn) {
            if (fault === 'listener-throws') fail();
            if (name === 'slotRenderEnded') handlers.push(fn);
        },
        removeEventListener() {},
    };
    if (fault === 'listener-missing') delete pubads.addEventListener;
    const gpt = {
        apiReady: true,
        cmd: {push(fn) { fn(); }},
        defineSlot() {
            if (fault === 'define-throws') fail();
            if (fault === 'define-null') return null;
            slot = {addService() { if (fault === 'service-throws') fail(); return slot; }};
            if (fault === 'service-missing') delete slot.addService;
            return slot;
        },
        pubads() {
            if (fault === 'pubads-throws') fail();
            return fault === 'pubads-null' ? null : pubads;
        },
        enableServices() { if (fault === 'enable-throws') fail(); },
        display(id) { if (fault === 'display-throws') fail(); requests.push(id); },
        destroySlots(slots) { destroyed.push(...slots); return true; },
    };
    if (fault === 'api-missing') delete gpt.defineSlot;
    if (fault === 'queue-invalid') gpt.cmd = {push:true};
    const head = {appendChild(script) { scripts.push(script); }};
    const document = {
        documentElement:{clientWidth:1348,clientHeight:936},
        head, body:head,
        querySelectorAll: query => query === '[data-hm-gpt-direct="1"]' ? [target] : [],
        getElementsByTagName: tag => tag === 'script' ? scripts : [],
        createElement: () => ({setAttribute(){}}),
    };
    const sandbox = {
        document, console, innerWidth:1348,innerHeight:936,
        MutationObserver:class { observe(){} },
        getComputedStyle:()=>({paddingLeft:'0',paddingRight:'0'}),
        HorusMediaLoader:{
            trace:(phase,detail)=>{ if (options.traceThrows) throw new Error('trace-unavailable'); traces.push({phase,detail}); },
            traceSlot:()=>1,
            traceGpt:()=>{},
        },
    };
    if (fault !== 'library-error' && fault !== 'library-parent-missing') sandbox.googletag=gpt;
    if (fault === 'library-parent-missing') document.head=document.body=null;
    sandbox.window=sandbox;
    vm.runInNewContext(source,sandbox,{filename:'hm-gpt-direct.js'});
    if (fault === 'library-error') scripts[0].onerror();
    return {
        attributes, requests, traces, destroyed, root, scripts,
        emit(event) { for (const fn of handlers.slice()) fn({slot,isEmpty:false,size:[300,250],...event}); },
    };
}

const beforeCall = [
    'api-missing','define-null','define-throws','pubads-null','pubads-throws',
    'service-missing','listener-missing','listener-throws','service-throws',
    'enable-throws','queue-invalid','library-error','library-parent-missing',
];
const expectedFailures = {
    'api-missing':['api','unavailable'], 'define-null':['define','null-slot'],
    'define-throws':['define','exception'], 'pubads-null':['service','unavailable'],
    'pubads-throws':['service','exception'], 'service-missing':['service','unavailable'],
    'listener-missing':['listener','unavailable'], 'listener-throws':['listener','exception'],
    'service-throws':['service','exception'], 'enable-throws':['enable','exception'],
    'queue-invalid':['queue','invalid-queue'], 'library-error':['library','load-error'],
    'library-parent-missing':['library','unavailable'],
};
function assertFailure(r,stage,reason) {
    assert.equal(r.attributes['data-hm-gpt-failure-stage'],stage);
    assert.equal(r.attributes['data-hm-gpt-failure-reason'],reason);
    const errors=r.traces.filter(e=>e.phase==='GPT error');
    assert.equal(errors.length,1);
    assert.equal(errors[0].detail.failureStage,stage);
    assert.equal(errors[0].detail.failureReason,reason);
    assert.equal(JSON.stringify([r.attributes,r.traces]).includes('private'),false);
}
for (const fault of beforeCall) {
    test('pre-request fault '+fault+' cannot explain a slot that already logged GPT call',()=>{
        const r=harness(fault);
        assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
        assert.equal(r.requests.length,0);
        assert.equal(r.traces.some(e=>e.phase==='GPT call'),false);
        assertFailure(r,...expectedFailures[fault]);
    });
}
test('display exception can fail after GPT call without a response',()=>{
    const r=harness('display-throws');
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.traces.filter(e=>e.phase==='GPT call').length,1);
    assert.equal(r.destroyed.length,1);
    assertFailure(r,'display','exception');
});
test('non-empty malformed response can fail after a successful display call',()=>{
    const r=harness();
    r.emit({size:[0,0]});
    assert.equal(r.requests.length,1);
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.destroyed.length,1);
    assertFailure(r,'render','response-rejected');
});
test('layout becoming unavailable after request can fail a non-empty response',()=>{
    const r=harness();
    r.root.clientWidth=0;
    r.emit({});
    assert.equal(r.requests.length,1);
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.destroyed.length,1);
    assertFailure(r,'render','host-unavailable');
});
test('known non-empty 970 by 600 response stays rendered under unchanged policy',()=>{
    const r=harness();
    r.emit({size:[970,600]});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'rendered');
    assert.equal(r.destroyed.length,0);
    assert.equal(r.attributes['data-hm-gpt-failure-reason'],undefined);
});
test('genuine Google no-fill remains empty rather than generic failed',()=>{
    const r=harness();
    r.emit({isEmpty:true,size:null});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'empty');
    assert.equal(r.destroyed.length,1);
    assert.equal(r.attributes['data-hm-gpt-failure-reason'],undefined);
});
test('serving gate rejection remains ineligible and never requests an ad',()=>{
    const r=harness('',{allowed:false});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'ineligible');
    assert.equal(r.requests.length,0);
    assert.equal(r.traces.some(e=>e.phase==='GPT call'),false);
    assert.equal(r.attributes['data-hm-gpt-failure-reason'],undefined);
});
test('late duplicate render events cannot change an accepted response',()=>{
    const r=harness();
    r.emit({});
    r.emit({isEmpty:true,size:null});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'rendered');
    assert.equal(r.destroyed.length,0);
});

test('broken diagnostic attributes and trace hooks cannot change failure cleanup',()=>{
    const r=harness('display-throws',{diagnosticsThrow:true,traceThrows:true});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.destroyed.length,1);
    assert.equal(r.requests.length,0);
});

test('repeated library error callbacks preserve one original failure event',()=>{
    const r=harness('library-error');
    r.scripts[0].onerror();
    assertFailure(r,'library','load-error');
    assert.equal(r.scripts.length,1);
    assert.equal(r.requests.length,0);
});

