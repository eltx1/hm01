import test from 'node:test';
import assert from 'node:assert/strict';
import {auditPublicCache} from '../../ops/audit/public-cache.mjs';
test('cache audit performs GET only and returns bounded sanitized policy evidence',async()=>{
    const calls=[];
    const result=await auditPublicCache({account:'a'.repeat(32),token:'PRIVATE_TOKEN',request:async(url,options)=>{
        calls.push({url,method:options.method});
        if(url.includes('cdn.horusmedia.net'))return new Response('synthetic-loader',{headers:{'Cache-Control':'public, max-age=14400, must-revalidate'}});
        const result=url.includes('/zones?')?[{id:'b'.repeat(32),account:{id:'a'.repeat(32)}}]:url.endsWith('browser_cache_ttl')?{value:14400,editable:true}:{rules:[{enabled:true,expression:'http.host eq "cdn.horusmedia.net" /* PRIVATE_EXPRESSION */',action_parameters:{browser_ttl:{mode:'override',default:14400},headers:{'x-private':{value:'PRIVATE_TOKEN'}}}}]};
        return Response.json({success:true,result});
    }});
    assert.equal(calls.length,5);assert.equal(calls.every(c=>c.method==='GET'),true);
    assert.equal(result.canonical.max_age,14400);assert.equal(result.cloudflare.browser_cache_ttl.seconds,14400);
    assert.equal(JSON.stringify(result).includes('PRIVATE'),false);
    assert.equal(calls.some(c=>/firewall|dns_records|challenges|scripts\//.test(c.url)),false);
});
test('permission failures are unknown, not evidence of an absent rule',async()=>{
    const result=await auditPublicCache({account:'a'.repeat(32),token:'PRIVATE_TOKEN',request:async()=>Response.json({success:false,error:'PRIVATE_PAYLOAD'},{status:403})});
    assert.equal(result.cloudflare.available,false);assert.equal(JSON.stringify(result).includes('PRIVATE'),false);
});
