import {pathToFileURL} from 'node:url';
export const loaderCacheRule = {
    ref:'horus_permanent_loader_browser_cache', description:'Horus permanent Loader: respect origin browser revalidation',
    expression:'(http.host eq "cdn.horusmedia.net" and http.request.uri.path in {"/hm-loader.js" "/assets/hm-loader.min.js"})',
    action:'set_cache_settings', action_parameters:{browser_ttl:{mode:'respect_origin'}}, enabled:true,
};
export async function configureLoaderBrowserCache({account,token,apply=false,request=fetch}) {
    if(!/^[a-f0-9]{32}$/i.test(account||'')||!token)throw Error('Cache configuration credentials missing');
    async function api(path,method='GET',body,missing=false){
        if(method!=='GET'&&!apply)throw Error('Write attempted during dry-run');
        try {
            const r=await request('https://api.cloudflare.com/client/v4'+path,{method,redirect:'error',signal:AbortSignal.timeout(15000),headers:{Authorization:'Bearer '+token,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});
            if(missing&&r.status===404)return null;
            const d=await r.json();
            if(!r.ok||d.success!==true)throw Error('API_REJECTED');
            return d.result;
        }catch{throw Error('Cloudflare cache request failed; no raw API data disclosed');}
    }
    const zones=await api('/zones?name=horusmedia.net');
    if(!Array.isArray(zones)||zones.length!==1||zones[0].account?.id!==account||!/^[a-f0-9]{32}$/i.test(zones[0].id))throw Error('Expected owned Horus zone');
    const root='/zones/'+zones[0].id+'/rulesets', phase='http_request_cache_settings';
    const endpoint=root+'/phases/'+phase+'/entrypoint';
    const current=await api(endpoint,'GET',undefined,true);
    if(current&&(current.phase!==phase||current.kind!=='zone'||!Array.isArray(current.rules)||!/^[a-f0-9]{32}$/i.test(current.id)))throw Error('Unrecognized cache ruleset');
    const owned=current?.rules.filter(r=>r.ref===loaderCacheRule.ref)||[];
    if(owned.length>1)throw Error('Ambiguous owned cache rule');
    if(owned.length){
        const r=owned[0];
        if(r.expression!==loaderCacheRule.expression||r.action!==loaderCacheRule.action||r.enabled!==true||JSON.stringify(r.action_parameters)!==JSON.stringify(loaderCacheRule.action_parameters))throw Error('Existing owned rule differs; inspect before changing it');
        // A later broad rule could override this one. Do not reorder user rules.
        if(current.rules[current.rules.length-1].id!==r.id)throw Error('Owned cache rule is not last; precedence review required');
        return {mode:apply?'UNCHANGED':'DRY_RUN',change_required:false,scope:'permanent Loader browser TTL only'};
    }
    if(!apply)return {mode:'DRY_RUN',change_required:true,existing_rules:current?.rules.length||0,scope:'permanent Loader browser TTL only'};
    if(current)await api(root+'/'+current.id+'/rules','POST',loaderCacheRule);
    else await api(root,'POST',{name:'Horus cache settings',kind:'zone',phase,rules:[loaderCacheRule]});
    const readback=await api(endpoint);
    const rule=readback?.rules?.[readback.rules.length-1];
    if(rule?.ref!==loaderCacheRule.ref||rule.expression!==loaderCacheRule.expression||rule.action!==loaderCacheRule.action||rule.enabled!==true||JSON.stringify(rule.action_parameters)!==JSON.stringify(loaderCacheRule.action_parameters))throw Error('Cache rule readback failed');
    return {mode:'APPLIED',change_required:true,scope:'permanent Loader browser TTL only'};
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
    try{console.log(JSON.stringify(await configureLoaderBrowserCache({account:process.env.CLOUDFLARE_ACCOUNT_ID,token:process.env.CLOUDFLARE_API_TOKEN,apply:process.argv.includes('--apply')})));}
    catch(error){console.error(error.message);process.exitCode=1;}
}
