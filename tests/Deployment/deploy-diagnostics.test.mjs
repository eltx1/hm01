import test from 'node:test';
import assert from 'node:assert/strict';
import {deployDiagnostics} from '../../scripts/deploy-diagnostics.mjs';
function fixture({conflict=false,unknown=false,failure=false}={}) {
    const calls=[];let settings=null,domains=conflict?[{hostname:'metrics.horusmedia.net',service:'someone-else'}]:[];
    if(unknown)settings={tags:['unrelated']};
    return {calls,run:apply=>deployDiagnostics({account:'a'.repeat(32),token:'PRIVATE_TOKEN',source:'export default {}',apply,request:async(url,options)=>{
        calls.push({url,method:options.method});
        if(failure)throw Error('PRIVATE_TOKEN');
        let result;
        if(url.includes('/zones?'))result=[{id:'zone',account:{id:'a'.repeat(32)}}];
        else if(url.endsWith('/workers/domains')) {
            if(options.method==='PUT')domains.push(JSON.parse(options.body));
            result=domains;
        } else if(url.endsWith('/settings')) {
            if(!settings)return new Response('{}',{status:404});
            result=settings;
        } else if(options.method==='PUT') {settings=JSON.parse(await options.body.get('metadata').text());result={};}
        else if(url.endsWith('/subdomain'))result={};
        else throw Error('unexpected route');
        return Response.json({success:true,result});
    }})};
}
test('dry-run reads ownership without a single external write',async()=>{
    const h=fixture();assert.equal((await h.run(false)).mode,'DRY_RUN');assert.equal(h.calls.every(c=>c.method==='GET'),true);
});
test('apply is idempotent after binding/source/domain readback',async()=>{
    const h=fixture();assert.equal((await h.run(true)).mode,'APPLIED');
    const writes=h.calls.filter(c=>c.method!=='GET').length;assert.equal(writes,3);
    assert.equal((await h.run(true)).source_changed,false);assert.equal(h.calls.filter(c=>c.method!=='GET').length,writes);
    assert.equal(h.calls.some(c=>/siteverify|turnstile|firewall|rulesets|dns_records/.test(c.url)),false);
});
for(const options of [{conflict:true},{unknown:true}])test('unrecognized ownership cannot be overwritten',async()=>{
    const h=fixture(options);await assert.rejects(h.run(true),/Refusing/);assert.equal(h.calls.every(c=>c.method==='GET'),true);
});
test('transport exceptions cannot disclose credentials',async()=>{
    const h=fixture({failure:true});await assert.rejects(h.run(false),error=>!error.message.includes('PRIVATE'));
});
