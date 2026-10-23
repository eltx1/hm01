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
        ...options.attributes,
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
    vm.runInNewContext((options.assignThrows ? 'Object.assign=function(){throw new Error("broken-object-assign");};' : '')+source,sandbox,{filename:'hm-gpt-direct.js'});
    if (fault === 'library-error') scripts[0].onerror();
    return {
        attributes, requests, traces, destroyed, root, scripts,
        emit(event) { for (const fn of handlers.slice()) fn({slot,isEmpty:false,size:[300,250],...event}); },
        emitRaw(event) { event.slot=slot; for (const fn of handlers.slice()) fn(event); },
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

for (const scenario of [
    {size: [0, 0], detail: 'dimension-non-positive', response: 'array(0,0)'},
    {size: [-1, 250], detail: 'dimension-non-positive', response: 'array(-1,250)'},
    {size: [300.5, 250], detail: 'dimension-non-integer', response: 'array(300.5,250)'},
    {size: [1e-7, 250], detail: 'dimension-non-integer', response: 'array(1e-7,250)'},
    {size: [NaN, 250], detail: 'dimension-non-finite', response: 'array(non-finite,250)'},
    {size: [300, Infinity], detail: 'dimension-non-finite', response: 'array(300,non-finite)'},
    {size: [10001, 250], detail: 'dimension-out-of-range', response: 'array(out-of-range,250)'},
    {size: ['300', '250'], detail: 'dimension-type', response: 'array(string,string)'},
    {size: ['private-provider-token', 250], detail: 'dimension-type', response: 'array(string,250)'},
    {size: [null, 250], detail: 'dimension-type', response: 'array(null,250)'},
    {size: [true, 250], detail: 'dimension-type', response: 'array(boolean,250)'},
    {size: [300], detail: 'size-shape', response: 'array-length:1'},
    {size: [300, 250, 1], detail: 'size-shape', response: 'array-length:3'},
    {size: [], detail: 'size-shape', response: 'array-length:0'},
    {size: Array(50).fill('private'), detail: 'size-shape', response: 'array-length:21'},
    {size: 'https://private.example/creative', detail: 'size-type', response: 'string'},
    {size: {width: 300, height: 250, url: 'https://private'}, detail: 'size-type', response: 'object'},
    {size: 300, detail: 'size-type', response: 'number'},
    {size: null, detail: 'size-missing', response: 'null', fixed: true},
    {size: undefined, detail: 'size-missing', response: 'undefined', fixed: true},
    {size: 'fluid', detail: 'fluid-not-requested', response: 'fluid', fixed: true},
    {size: ['fluid'], detail: 'fluid-not-requested', response: 'fluid-array', fixed: true},
    {size: [970, 600], detail: 'outside-size-policy', response: 'array(970,600)', fixed: true},
    {size: ['private', 250], detail: 'dimension-non-finite', response: 'array(string,250)', fixed: true},
]) {
    test('rejected response explains '+scenario.detail+' '+scenario.response+(scenario.fixed?' fixed':''),()=>{
        const r=harness('',scenario.fixed ? {attributes: {
            'data-hm-gpt-sizes': '[[300,250]]', 'data-hm-gpt-fit-container': '0', 'data-hm-gpt-responsive-fluid': '0',
        }} : {});
        r.emit({size:scenario.size});
        assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
        assertFailure(r,'render','response-rejected');
        assert.equal(r.requests.length,1);
        assert.equal(r.destroyed.length,1);
        assert.equal(r.attributes['data-hm-gpt-failure-detail'],scenario.detail);
        assert.equal(r.attributes['data-hm-gpt-failure-response'],scenario.response);
        assert.equal(r.attributes['data-hm-gpt-failure-policy'],scenario.fixed?'fixed':'flexible');
        const error=r.traces.find(e=>e.phase==='GPT error').detail;
        assert.equal(error.failureDetail,scenario.detail);
        assert.equal(error.responseSize,scenario.response);
        assert.equal(error.requestedSizes,scenario.fixed?'300x250':actualSizes.map(s=>s==='fluid'?'fluid':s.join('x')).join(','));
    });
}

test('accepted fixed and fluid responses remain successful without rejection context',()=>{
    for (const size of [[300,250],[970,600],'fluid',['fluid'],null,undefined]) {
        const r=harness(); r.emit({size});
        assert.equal(r.attributes['data-hm-gpt-runtime-state'],'rendered');
        assert.equal(r.attributes['data-hm-gpt-failure-detail'],undefined);
        assert.equal(r.destroyed.length,0);
        assert.equal(r.requests.length,1);
    }
});

test('throwing new context attributes cannot prevent rejection, cleanup or the local trace',()=>{
    const r=harness('',{diagnosticsThrow:true}); r.emit({size:[0,0]});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.destroyed.length,1);
    assert.equal(r.requests.length,1);
    assert.equal(r.traces.find(e=>e.phase==='GPT error').detail.failureDetail,'dimension-non-positive');
});

test('fixed-policy diagnostics never invoke custom provider coercion a second time or claim an unknown cause',()=>{
    let calls=0;
    const width={valueOf(){calls++;return 300;},toString(){throw new Error('private-provider-token');}};
    const r=harness('',{attributes:{'data-hm-gpt-fit-container':'0','data-hm-gpt-sizes':'[[300,250]]'}});
    r.emit({size:[width,0]});
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(calls,1);
    assert.equal(r.attributes['data-hm-gpt-failure-detail'],'dimension-uninspectable');
    assert.equal(r.attributes['data-hm-gpt-failure-response'],'array(object,0)');
    assertFailure(r,'render','response-rejected');
});

test('a broken trace dependency cannot stop the original rejection or destroySlots cleanup',()=>{
    const r=harness('',{assignThrows:true});
    assert.doesNotThrow(()=>r.emit({size:[0,0]}));
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.attributes['data-hm-gpt-failure-detail'],'dimension-non-positive');
    assert.equal(r.requests.length,1);
    assert.equal(r.destroyed.length,1);
});

test('a response getter failing only during optional inspection cannot interrupt terminal cleanup',()=>{
    let reads=0;
    const r=harness();
    const event={isEmpty:false,get size(){if(++reads>3)throw new Error('private-response');return [0,0];}};
    assert.doesNotThrow(()=>r.emitRaw(event));
    assert.equal(r.attributes['data-hm-gpt-runtime-state'],'failed');
    assert.equal(r.attributes['data-hm-gpt-failure-detail'],undefined);
    assert.equal(r.requests.length,1);
    assert.equal(r.destroyed.length,1);
    assertFailure(r,'render','response-rejected');
});

