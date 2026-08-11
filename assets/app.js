(function(){
  const cfg=window.EAN_APP||{};
  const $=s=>document.querySelector(s);
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function api(action){
    const body=new URLSearchParams({action,csrf:cfg.csrf,job:cfg.job});
    const r=await fetch('index.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body});
    const j=await r.json().catch(()=>({ok:false,error:'Risposta server non valida'}));
    if(!r.ok||!j.ok) throw new Error(j.error||'Errore server');
    return j;
  }
  function updateView(j){
    if(j.counts){
      $('#counts').innerHTML=Object.entries(j.counts).map(([k,v])=>`<span><b>${esc(k)}</b>: ${v}</span>`).join('');
      const done=(j.counts.pending||0)===0;
      const total=j.total||1, checked=total-(j.counts.pending||0);
      $('#bar').style.width=Math.round(checked/total*100)+'%';
      if(done&&$('#updateBtn')) $('#updateBtn').disabled=(j.counts.ready||0)===0;
    }
    if(j.rows){
      $('#tbody').innerHTML=j.rows.map(r=>`<tr><td>${r.excel_row}</td><td>${esc(r.sku)}</td><td>${esc(r.item_id)}</td><td>${esc(r.ean)}</td><td>${esc(r.remote?.existing_ean||'')}</td><td>${esc(r.remote?.title||'')}</td><td class="st-${esc(r.status)}">${esc(r.status_label)}</td><td>${esc(r.message||'')}</td></tr>`).join('');
    }
  }
  async function loop(action,button){
    button.disabled=true; const old=button.textContent;
    try{
      while(true){
        button.textContent=action==='check_batch'?'Controllo in corso…':'Aggiornamento in corso…';
        const j=await api(action); updateView(j);
        if(j.done) break;
      }
      button.textContent=action==='check_batch'?'Controllo completato':'Aggiornamento completato';
    }catch(e){ button.textContent=old; button.disabled=false; alert(e.message); }
  }
  const check=$('#checkBtn'); if(check) check.addEventListener('click',()=>loop('check_batch',check));
  const upd=$('#updateBtn'); if(upd) upd.addEventListener('click',()=>{if(confirm('Confermi l’inserimento degli EAN mancanti su eBay?')) loop('update_batch',upd)});
})();

