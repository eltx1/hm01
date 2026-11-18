import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm,writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import {archiveLoaderRelease} from '../../scripts/archive-loader-release.mjs';
test('archives are content addressed, idempotent and keep older generations',async()=>{
 const path=await mkdtemp(join(tmpdir(),'horus-loader-'));
 const directory=pathToFileURL(path+'/');
 try{
  const identity={runtimeBuild:'sha256:'+'a'.repeat(64),version:'2.0.0'};
  const first=await archiveLoaderRelease(directory,'one',identity);
  await archiveLoaderRelease(directory,'one',identity);
  const second=await archiveLoaderRelease(directory,'two',{...identity,runtimeBuild:'sha256:'+'b'.repeat(64)});
  const records=JSON.parse(await readFile(new URL('releases.json',directory),'utf8'));
  assert.equal(Object.keys(records).length,2);
  assert.equal(await readFile(new URL(first.file,directory),'utf8'),'one');
  assert.notEqual(first.digest,second.digest);
  await writeFile(new URL(first.file,directory),'corrupt');
  await assert.rejects(archiveLoaderRelease(directory,'one',identity),/conflict/);
  await assert.rejects(archiveLoaderRelease(directory,'x',{...identity,protocol:1}),/Invalid/);
 }finally{await rm(path,{recursive:true,force:true});}
});
