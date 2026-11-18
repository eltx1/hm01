import {createHash} from 'node:crypto';
import {mkdir,readFile,writeFile,lstat} from 'node:fs/promises';

export async function archiveLoaderRelease(directory,bytes,{runtimeBuild,version,protocol=2}) {
    if(!/^sha256:[a-f0-9]{64}$/.test(runtimeBuild)||!/^\d+\.\d+\.\d+$/.test(version)||protocol!==2) throw Error('Invalid Loader archive identity');
    const digest=createHash('sha256').update(bytes).digest('hex');
    await mkdir(directory,{recursive:true});
    const registryPath=new URL('releases.json',directory);
    let records={};
    try {records=JSON.parse(await readFile(registryPath,'utf8'));}catch(error){if(error.code!=='ENOENT')throw error;}
    if(!records||Array.isArray(records)||typeof records!=='object'||Object.keys(records).length>256)throw Error('Invalid or oversized Loader archive registry');
    for(const [previous,entry] of Object.entries(records)) {
        if(!/^[a-f0-9]{64}$/.test(previous)||!entry||entry.file!=='hm-loader.'+previous.slice(0,16)+'.min.js'
            ||!/^sha256:[a-f0-9]{64}$/.test(entry.runtimeBuild||'')||!/^\d+\.\d+\.\d+$/.test(entry.version||'')||entry.protocol!==2)throw Error('Invalid Loader archive record');
        const priorPath=new URL(entry.file,directory),stat=await lstat(priorPath);
        if(!stat.isFile()||stat.isSymbolicLink()||createHash('sha256').update(await readFile(priorPath)).digest('hex')!==previous)throw Error('Loader archive bytes conflict');
    }
    if(!records[digest]&&Object.keys(records).length>=256)throw Error('Loader archive registry capacity reached; review referenced releases before cleanup');
    const record={file:'hm-loader.'+digest.slice(0,16)+'.min.js',runtimeBuild,version,protocol};
    if(records[digest]&&(Object.keys(record).some(key=>records[digest][key]!==record[key])||Object.keys(records[digest]).some(key=>!Object.hasOwn(record,key))))throw Error('Loader archive identity conflict');
    const path=new URL(record.file,directory);
    try {await writeFile(path,bytes,{flag:'wx'});}catch(error){if(error.code!=='EEXIST'||!Buffer.from(await readFile(path)).equals(Buffer.from(bytes)))throw Error('Loader archive bytes conflict');}
    records[digest]=record;
    const ordered=Object.fromEntries(Object.entries(records).sort(([a],[b])=>a.localeCompare(b)));
    await writeFile(registryPath,JSON.stringify(ordered,null,2)+'\n');
    return {digest,...record};
}
