import {createHash} from 'node:crypto';
import {readFile, appendFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
const WORKER='horus-performance-diagnostics', TAG='horus-performance-diagnostics', HOST='metrics.horusmedia.net';
export async function deployDiagnostics({account,token,source,apply=false,request=fetch}) {
    if (!/^[a-f0-9]{32}$/i.test(account||'') || !token || !source) throw Error('Diagnostics deployment inputs missing or invalid');
    const root='https://api.cloudflare.com/client/v4', base='/accounts/'+account;
    async function api(path,method='GET',body,missing=false) {
        if(method!=='GET'&&!apply)throw Error('Write attempted in dry-run');
        let response,data;
        try {
            response=await request(root+path,{method,redirect:'error',signal:AbortSignal.timeout(15000),headers:{Authorization:'Bearer '+token,...(body instanceof FormData?{}:{'Content-Type':'application/json'})},...(body?{body:body instanceof FormData?body:JSON.stringify(body)}:{})});
            if(missing&&response.status===404)return null;
            data=await response.json();
        } catch {throw Error('Diagnostics Cloudflare transport failed');}
        if(!response.ok||data.success!==true) {
            // Numeric provider codes diagnose account/binding access without
            // printing raw API messages (which may include private identifiers).
            const codes=Array.isArray(data.errors)?data.errors.slice(0,10).map(e=>e?.code).filter(c=>Number.isSafeInteger(c)&&c>=0):[];
            const messages=Array.isArray(data.errors)?data.errors.map(e=>typeof e?.message==='string'?e.message:'').join(' '):'';
            const category=/analytics.*(?:not enabled|enable|permission)|(?:not enabled|enable).*analytics/i.test(messages)?'ANALYTICS_AVAILABILITY'
                :/permission|not authorized|authentication|unauthorized/i.test(messages)?'AUTHORIZATION':'UPSTREAM_REJECTED';
            const operation=path.endsWith('/workers/scripts/'+WORKER)?'WORKER_UPLOAD':path.endsWith('/subdomain')?'WORKER_SUBDOMAIN':path.endsWith('/workers/domains')?'WORKER_DOMAIN':'READINESS';
            throw Error('Diagnostics Cloudflare request failed ('+response.status+'); operation='+operation+'; codes='+codes.join(',')+'; category='+category);
        }
        return data.result;
    }
    const zones=await api('/zones?name=horusmedia.net');
    if(!Array.isArray(zones)||zones.length!==1||zones[0].account?.id!==account)throw Error('Expected owned Horus zone');
    const domains=await api(base+'/workers/domains');
    if(!Array.isArray(domains))throw Error('Custom domain list unavailable');
    const domain=domains.find(d=>d.hostname===HOST);
    if(domain&&(domain.service!==WORKER||(domain.environment&&domain.environment!=='production')))throw Error('Refusing to replace another Worker custom domain');
    const settingsPath=base+'/workers/scripts/'+WORKER+'/settings';
    const current=await api(settingsPath,'GET',undefined,true);
    if(current&&!current.tags?.includes(TAG))throw Error('Refusing to overwrite an unrecognized Worker');
    if(current?.bindings?.some(b=>b.name!=='HORUS_DIAGNOSTICS'||b.type!=='analytics_engine'))throw Error('Unexpected existing bindings; deployment stopped');
    const hashTag='hm-diag-'+createHash('sha256').update(source).digest('hex').slice(0,40);
    const binding={name:'HORUS_DIAGNOSTICS',type:'analytics_engine',dataset:'horus_platform_diagnostics_v1'};
    const matches=settings=>settings?.tags?.includes(hashTag)&&!settings.observability?.enabled&&settings.bindings?.length===1&&settings.bindings[0].name===binding.name&&settings.bindings[0].type===binding.type&&settings.bindings[0].dataset===binding.dataset;
    if(!apply)return {mode:'DRY_RUN',worker_exists:!!current,domain_exists:!!domain,update_required:!matches(current)};
    if(!matches(current)) {
        const form=new FormData();
        form.set('metadata',new Blob([JSON.stringify({main_module:'index.mjs',compatibility_date:'2026-09-01',tags:[TAG,hashTag],bindings:[binding],observability:{enabled:false}})],{type:'application/json'}));
        form.set('index.mjs',new Blob([source],{type:'application/javascript+module'}),'index.mjs');
        await api(base+'/workers/scripts/'+WORKER,'PUT',form);
        await api(base+'/workers/scripts/'+WORKER+'/subdomain','POST',{enabled:false,previews_enabled:false});
    }
    if(!domain)await api(base+'/workers/domains','PUT',{hostname:HOST,service:WORKER,environment:'production',zone_id:zones[0].id});
    if(!matches(await api(settingsPath)))throw Error('Diagnostics binding/source readback failed');
    const readback=await api(base+'/workers/domains');
    if(!readback.some(d=>d.hostname===HOST&&d.service===WORKER))throw Error('Diagnostics domain readback failed');
    return {mode:'APPLIED',worker:WORKER,hostname:HOST,source_changed:!matches(current)};
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href) {
    try {
        const result=await deployDiagnostics({account:process.env.CLOUDFLARE_ACCOUNT_ID,token:process.env.CLOUDFLARE_API_TOKEN,
            source:await readFile(new URL('../workers/diagnostics/index.mjs',import.meta.url),'utf8'),apply:process.argv.includes('--apply')});
        console.log(JSON.stringify(result));
        if(process.env.GITHUB_STEP_SUMMARY)await appendFile(process.env.GITHUB_STEP_SUMMARY,JSON.stringify(result)+'\n');
    } catch(error) {console.error(error.message);process.exitCode=1;}
}
