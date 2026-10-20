(() => {
 const beat=()=>fetch(window.nscEditorRuntime.endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'nsc_editor_heartbeat',_ajax_nonce:window.nscEditorRuntime.nonce})}).catch(()=>{});
 beat();const timer=setInterval(beat,20000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();
