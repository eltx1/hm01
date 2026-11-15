import {createHash} from 'node:crypto';
import {pathToFileURL} from 'node:url';
export async function auditPublicCache({account,token,request=fetch}) {
    const evidence={read_only:true,canonical:{},cloudflare:{}};
    try {
        const response=await request('https://cdn.horusmedia.net/hm-loader.js',{method:'GET',redirect:'error',signal:AbortSignal.timeout(15000)});
        const body=new Uint8Array(await response.arrayBuffer());
        const cache=response.headers.get('Cache-Control')||'';
        evidence.canonical={status:response.status,bytes:body.byteLength,sha256:createHash('sha256').update(body).digest('hex'),
            max_age:Number(/(?:^|,)\s*max-age=(\d+)/i.exec(cache)?.[1]??-1),no_cache:/(?:^|,)\s*no-cache(?:,|$)/i.test(cache),must_revalidate:/(?:^|,)\s*must-revalidate(?:,|$)/i.test(cache)};
    }catch{evidence.canonical={available:false};}
    if(!/^[a-f0-9]{32}$/i.test(account||'')||!token){evidence.cloudflare={available:false};return evidence;}
    async function read(path) {
        try {
            const response=await request('https://api.cloudflare.com/client/v4'+path,{method:'GET',redirect:'error',signal:AbortSignal.timeout(15000),headers:{Authorization:'Bearer '+token}});
            const body=await response.json();
            return response.ok&&body.success===true?{available:true,result:body.result}:{available:false,status:response.status};
        }catch{return {available:false};}
    }
    const zones=await read('/zones?name=horusmedia.net');
    if(!zones.available||!Array.isArray(zones.result)||zones.result.length!==1||zones.result[0].account?.id!==account){evidence.cloudflare={available:false};return evidence;}
    const zone=zones.result[0].id;
    if(!/^[a-f0-9]{32}$/i.test(zone)){evidence.cloudflare={available:false};return evidence;}
    const ttl=await read('/zones/'+zone+'/settings/browser_cache_ttl');
    evidence.cloudflare.browser_cache_ttl=ttl.available?{available:true,seconds:Number.isInteger(ttl.result?.value)?ttl.result.value:null,editable:ttl.result?.editable===true}:{available:false,status:ttl.status??null};
    for(const phase of ['http_request_cache_settings','http_response_headers_transform']){
        const rules=await read('/zones/'+zone+'/rulesets/phases/'+phase+'/entrypoint');
        evidence.cloudflare[phase]=rules.available&&Array.isArray(rules.result?.rules)?{available:true,rules:rules.result.rules.slice(0,100).map(rule=>{
            const expression=String(rule.expression||'');
            const browser=rule.action_parameters?.browser_ttl;
            const header=rule.action_parameters?.headers?.['cache-control'];
            return {enabled:rule.enabled!==false,mentions_loader:expression.includes('hm-loader'),mentions_cdn:expression.includes('cdn.horusmedia.net'),expression_is_true:expression.trim()==='true',
                browser_ttl_mode:['override','respect_origin','bypass'].includes(browser?.mode)?browser.mode:null,
                browser_ttl_default:Number.isInteger(browser?.default)?browser.default:null,sets_cache_control:header?.operation==='set',
                header_max_age:Number(/(?:^|,)\s*max-age=(\d+)/i.exec(header?.value||'')?.[1]??-1)};
        })}:{available:false,status:rules.status??null};
    }
    return evidence;
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
    console.log(JSON.stringify(await auditPublicCache({account:process.env.CLOUDFLARE_ACCOUNT_ID,token:process.env.CLOUDFLARE_API_TOKEN})));
}
