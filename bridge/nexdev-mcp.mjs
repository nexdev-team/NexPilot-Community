#!/usr/bin/env node
// Dependency-free stdio MCP bridge. One process/token file per client and site.
import { readFile, stat } from 'node:fs/promises';
import { createInterface } from 'node:readline';
const endpoint = new URL(process.env.NSC_ENDPOINT || 'https://invalid.local');
const local = process.env.NSC_ALLOW_LOCAL_HTTP === '1' && ['127.0.0.1','localhost','[::1]'].includes(endpoint.hostname);
if (endpoint.username || endpoint.password || endpoint.search || endpoint.hash || (endpoint.protocol !== 'https:' && !(local && endpoint.protocol === 'http:'))) throw new Error('Use an HTTPS endpoint without credentials, query or fragment.');
const tokenPath = process.env.NSC_TOKEN_FILE;
if (!tokenPath) throw new Error('NSC_TOKEN_FILE is required.');
const info = await stat(tokenPath);
if (!info.isFile() || info.size > 256 || (process.platform !== 'win32' && (info.mode & 0o077))) throw new Error('Token file must be a small regular file readable only by its owner (chmod 600).');
const token = (await readFile(tokenPath,'utf8')).trim();
if (!/^[a-f0-9]{32}\.[a-f0-9]{64}$/.test(token)) throw new Error('Invalid credential format.');
const readOnly = process.env.NSC_READ_ONLY === '1';
const readTools = new Set(['health_check','editor_runtime','changes_list','change_get','resource_get','library_list','library_get','design_active','design_check','blocks_types','blocks_get','site_info','content_list','content_get','products_list','product_get','operations_list']);
const readMethods = new Set(['initialize','notifications/initialized','ping','tools/list','tools/call']);
const lines = createInterface({ input:process.stdin, crlfDelay:Infinity });
// Serial within each client; separate client processes remain concurrent.
for await (const line of lines) {
 let message;
 try {
  if (Buffer.byteLength(line)>196608) throw new Error('Request too large.');
  message = JSON.parse(line);
  if(readOnly && (!readMethods.has(message.method) || (message.method==='tools/call' && !readTools.has(message.params?.name)))) {
   if(Object.hasOwn(message,'id'))process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:message.id,error:{code:-32601,message:'This connection is read-only; the requested operation is unavailable.'}})+'\n');
   continue;
  }
  const response=await fetch(endpoint,{method:'POST',redirect:'error',headers:{'Authorization':`Bearer ${token}`,'Content-Type':'application/json','Accept':'application/json, text/event-stream'},body:line,signal:AbortSignal.timeout(60000)});
  if (response.status===202) continue;
  if (!response.ok) throw new Error(`Connector HTTP ${response.status}.`);
  const text=await response.text();
  if(Buffer.byteLength(text)>1048576)throw new Error('Response too large.');
  const data=JSON.parse(text);
  if(readOnly && message.method==='tools/list' && Array.isArray(data.result?.tools))data.result.tools=data.result.tools.filter(t=>readTools.has(t.name)&&t.annotations?.readOnlyHint===true);
  if(Object.hasOwn(message,'id')) process.stdout.write(JSON.stringify(data)+'\n');
 } catch(error) {
  // Never retry writes: a timeout does not mean the server did not execute them.
  if(message && Object.hasOwn(message,'id')) process.stdout.write(JSON.stringify({jsonrpc:'2.0',id:message.id,error:{code:-32000,message:'Connector request failed. Check connection and proposal status before retrying.'}})+'\n');
  process.stderr.write('Nexdev bridge: request failed; details omitted to protect credentials.\n');
 }
}
