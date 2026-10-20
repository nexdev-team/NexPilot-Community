/* Runs only in authenticated WordPress admin; never approves from an MCP tool. */
(() => {
 const sleep = ms => new Promise(resolve => setTimeout(resolve,ms));
 async function validateTarget(target) {
  const frame=document.createElement('iframe');
  frame.style.cssText='position:fixed;left:-10000px;width:1200px;height:800px';
  frame.title='Native editor validation';
  frame.src=window.nscBlocksEditor + '?post='+encodeURIComponent(target.id)+'&action=edit';
  document.body.append(frame);
  try {
   for(let i=0;i<150;i++) {
    await sleep(200);
    const win=frame.contentWindow;
    if(!win?.wp?.blocks || !win?.wp?.data?.select('core/editor')?.getCurrentPostId())continue;
    const blocks=win.wp.blocks.parse(target.content);
    const problems=[];
    const inspect=items=>items.forEach(block=>{
     if(block.isValid===false || !win.wp.blocks.getBlockType(block.name))problems.push(block.name);
     // Parsed static markup validates against the actual registered client save function.
     if(win.wp.blocks.validateBlock && win.wp.blocks.validateBlock(block)[0]===false)problems.push(block.name);
     inspect(block.innerBlocks||[]);
    });
    inspect(blocks);
    if(problems.length)throw new Error('Invalid blocks in post '+target.id+': '+[...new Set(problems)].join(', '));
    return {id:target.id,content:win.wp.blocks.serialize(blocks)};
   }
   throw new Error('Native editor was unavailable for post '+target.id+'. No approval was submitted.');
  }finally{frame.remove();}
 }
 document.querySelectorAll('form[data-nsc-blocks]').forEach(form=>{
  form.querySelector('button').disabled=false;
  form.addEventListener('submit',async event=>{
   if(form.dataset.validated==='yes')return;
   event.preventDefault();const button=form.querySelector('button');button.disabled=true;
   const status=form.querySelector('[role=status]');status.textContent='Validating in the native editor…';
   try {
    const targets=JSON.parse(form.dataset.nscBlocks);const serialized=[];
    for(const target of targets)serialized.push(await validateTarget(target));
    form.querySelector('[name=serialized]').value=JSON.stringify(serialized);
    form.dataset.validated='yes';status.textContent='Validated. Submitting the exact serialized change…';form.submit();
   }catch(error){status.textContent=error.message;button.disabled=false;}
  });
 });
})();
