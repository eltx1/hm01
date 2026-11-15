import test from 'node:test';
import assert from 'node:assert/strict';
import {configureLoaderBrowserCache,loaderCacheRule} from '../../scripts/configure-loader-browser-cache.mjs';
function fixture({existing=false,conflict=false,failure=false}={}){
    const calls=[];let state=existing?{id:'c'.repeat(32),kind:'zone',phase:'http_request_cache_settings',rules:[{id:'existing',ref:'unrelated',expression:'true',action:'set_cache_settings',action_parameters:{browser_ttl:{mode:'override_origin',default:14400}},enabled:true}]}:null;
    if(conflict)state={id:'c'.repeat(32),kind:'zone',phase:'http_request_cache_settings',rules:[{...loaderCacheRule,expression:'true'}]};
    return {calls,run:apply=>configureLoaderBrowserCache({account:'a'.repeat(32),token:'PRIVATE_TOKEN',apply,request:async(url,options)=>{
        calls.push({url,method:options.method,body:options.body});
        if(failure)throw Error('PRIVATE_TOKEN');
        let result;
        if(url.includes('/zones?'))result=[{id:'b'.repeat(32),account:{id:'a'.repeat(32)}}];
        else if(options.method==='POST'){
            const data=JSON.parse(options.body);
            if(url.endsWith('/rules'))state.rules.push({...data,id:'owned'});
            else state={...data,id:'c'.repeat(32),rules:data.rules.map(r=>({...r,id:'owned'}))};
            result=state;
        }else {if(!state)return new Response('{}',{status:404});result=state;}
        return Response.json({success:true,result});
    }})};
}
for(const existing of [false,true])test('dry-run is read-only; apply is scoped, preserves rules and is idempotent: '+existing,async()=>{
    const h=fixture({existing});assert.equal((await h.run(false)).mode,'DRY_RUN');assert.equal(h.calls.every(c=>c.method==='GET'),true);
    assert.equal((await h.run(true)).mode,'APPLIED');assert.equal(h.calls.filter(c=>c.method==='POST').length,1);
    assert.equal((await h.run(true)).mode,'UNCHANGED');assert.equal(h.calls.filter(c=>c.method==='POST').length,1);
    assert.equal(h.calls.some(c=>/settings\/browser_cache_ttl|firewall|transform|challenges|workers|dns_records|purge_cache/.test(c.url)),false);
    const payload=JSON.parse(h.calls.find(c=>c.method==='POST').body);const rule=existing?payload:payload.rules[0];
    assert.deepEqual(rule.action_parameters,{browser_ttl:{mode:'respect_origin'}});
    assert.equal(rule.expression,loaderCacheRule.expression);
});
test('conflicting owned rules cannot be silently overwritten',async()=>{const h=fixture({conflict:true});await assert.rejects(h.run(true),/differs/);assert.equal(h.calls.every(c=>c.method==='GET'),true);});
test('transport failures do not leak credentials',async()=>{const h=fixture({failure:true});await assert.rejects(h.run(false),e=>!e.message.includes('PRIVATE'));});
