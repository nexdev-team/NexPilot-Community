import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root=fileURLToPath(new URL('../',import.meta.url));
async function child(env,input){
 return await new Promise((resolve,reject)=>{
  const p=spawn(process.execPath,[path.join(root,'bridge/nexdev-mcp.mjs')],{env:{...process.env,...env}});let out='',err='';
  p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('error',reject);p.on('close',code=>resolve({out,err,code}));p.stdin.end(input);
 });
}
test('four concurrent clients retain independent identities, IDs and JSON-only stdout',async()=>{
 const dir=await mkdtemp(path.join(root,'tests/.bridge-'));const seen=new Set();let active=0,max=0;
 const server=createServer(async(req,res)=>{
  let body='';for await(const chunk of req)body+=chunk;
  seen.add(req.headers.authorization);const m=JSON.parse(body);active++;max=Math.max(max,active);
  await new Promise(r=>setTimeout(r,50));active--;
  if(!Object.hasOwn(m,'id')){res.writeHead(202);res.end();return;}
  res.setHeader('Content-Type','application/json');res.end(JSON.stringify({jsonrpc:'2.0',id:m.id,result:{client:req.headers.authorization.slice(7,39)}}));
 });
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 try{
  const results=await Promise.all([1,2,3,4].map(async i=>{
   const token=String(i).repeat(32)+'.'+'a'.repeat(64),file=path.join(dir,i+'.token');await writeFile(file,token,{mode:0o600});
   const r=await child({NSC_TOKEN_FILE:file,NSC_ALLOW_LOCAL_HTTP:'1',NSC_ENDPOINT:`http://127.0.0.1:${server.address().port}/mcp`},JSON.stringify({jsonrpc:'2.0',method:'initialize',id:i,params:{protocolVersion:'2025-03-26'}})+'\n'+JSON.stringify({jsonrpc:'2.0',method:'notifications/initialized'})+'\n');
   assert.equal(r.code,0);assert.equal(r.err,'');const data=JSON.parse(r.out);assert.equal(data.id,i);assert.equal(data.result.client,String(i).repeat(32));return r;
  }));
  assert.equal(results.length,4);assert.equal(seen.size,4);assert.ok(max>=2,'requests actually overlap');
 }finally{await new Promise(r=>server.close(r));await rm(dir,{recursive:true});}
});
test('bridge refuses insecure remote HTTP and does not print credentials',async()=>{
 const r=await child({NSC_ENDPOINT:'http://example.com/mcp',NSC_TOKEN_FILE:'/unused'},'');assert.notEqual(r.code,0);assert.match(r.err,/HTTPS/);
});
test('bridge refuses token files readable by other users',async()=>{
 const dir=await mkdtemp(path.join(root,'tests/.bridge-'));try{
  const token='a'.repeat(32)+'.'+'b'.repeat(64);const file=path.join(dir,'bad.token');await writeFile(file,token,{mode:0o644});
  const r=await child({NSC_ENDPOINT:'https://example.com/mcp',NSC_TOKEN_FILE:file},'');assert.notEqual(r.code,0);assert.ok(!r.err.includes(token));
 }finally{await rm(dir,{recursive:true});}
});
test('bridge never follows a credential-bearing redirect',async()=>{
 const dir=await mkdtemp(path.join(root,'tests/.bridge-'));let redirected=false;
 const server=createServer((req,res)=>{if(req.url==='/leak'){redirected=true;res.end('{}');}else{res.writeHead(307,{Location:'/leak'});res.end();}});await new Promise(r=>server.listen(0,'127.0.0.1',r));
 try{const file=path.join(dir,'test.token');await writeFile(file,'a'.repeat(32)+'.'+'b'.repeat(64),{mode:0o600});
 const r=await child({NSC_TOKEN_FILE:file,NSC_ALLOW_LOCAL_HTTP:'1',NSC_ENDPOINT:`http://127.0.0.1:${server.address().port}/mcp`},'{"jsonrpc":"2.0","id":1,"method":"ping"}\n');assert.equal(redirected,false);assert.equal(JSON.parse(r.out).error.code,-32000);
 }finally{await new Promise(r=>server.close(r));await rm(dir,{recursive:true});}
});
test('read-only mode hides mutation tools and blocks calls before any network request',async()=>{
 const dir=await mkdtemp(path.join(root,'tests/.bridge-'));let requests=0;
 const server=createServer(async(req,res)=>{requests++;let raw='';for await(const c of req)raw+=c;const m=JSON.parse(raw);res.setHeader('Content-Type','application/json');res.end(JSON.stringify({jsonrpc:'2.0',id:m.id,result:{tools:[{name:'site_info',annotations:{readOnlyHint:true}},{name:'change_execute',annotations:{readOnlyHint:false}},{name:'unknown',annotations:{readOnlyHint:true}}]}}));});await new Promise(r=>server.listen(0,'127.0.0.1',r));
 try{const file=path.join(dir,'read.token');await writeFile(file,'a'.repeat(32)+'.'+'b'.repeat(64),{mode:0o600});
 const messages=[{id:1,method:'tools/list'},{id:2,method:'tools/call',params:{name:'change_execute',arguments:{id:'never-sent'}}},{id:3,method:'unknown/write'}];
 const r=await child({NSC_READ_ONLY:'1',NSC_TOKEN_FILE:file,NSC_ALLOW_LOCAL_HTTP:'1',NSC_ENDPOINT:`http://127.0.0.1:${server.address().port}/mcp`},messages.map(m=>JSON.stringify({jsonrpc:'2.0',...m})).join('\n')+'\n');
 const out=r.out.trim().split('\n').map(JSON.parse);assert.equal(requests,1);assert.deepEqual(out[0].result.tools.map(t=>t.name),['site_info']);assert.equal(out[1].error.code,-32601);assert.equal(out[2].error.code,-32601);
 }finally{await new Promise(r=>server.close(r));await rm(dir,{recursive:true});}
});
