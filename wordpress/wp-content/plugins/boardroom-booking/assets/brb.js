(function(){
  var cal=document.getElementById('brb-cal'); if(!cal) return;
  var date=document.getElementById('brb-date'), busyEl=document.getElementById('brb-busy'), form=document.getElementById('brb-form');
  var cur=new Date(); cur.setDate(1); var data={};
  function pad(n){return (n<10?'0':'')+n}
  function key(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())}
  function syncType(){var t=form.querySelector('input[name=type]:checked')||form.querySelector('input[name=type]');var h=t&&t.value==='hourly';
    form.querySelector('.brb-day').style.display=h?'none':'';form.querySelector('.brb-hourly').style.display=h?'':'none';}
  form.addEventListener('change',function(e){if(e.target.name==='type')syncType(); if(e.target===date){showBusy();render();}}); syncType();
  function showBusy(){var d=data[date.value]; if(!busyEl) return; if(d&&d.busy.length){busyEl.textContent='Already booked: '+d.busy.map(function(b){return fmt(b[0])+'–'+fmt(b[1])}).join(', ');}else busyEl.textContent='';}
  function fmt(h){var a=h%24<12?'am':'pm',x=h%12||12;return x+a}
  function render(){
    var m=cur.getFullYear()+'-'+pad(cur.getMonth()+1);
    var h='<div class="brb-calhead"><button type="button" data-n="-1" aria-label="Previous month">‹</button><strong>'+cur.toLocaleString(undefined,{month:'long',year:'numeric'})+'</strong><button type="button" data-n="1" aria-label="Next month">›</button></div><div class="brb-calgrid">';
    'SMTWTFS'.split('').forEach(function(c){h+='<span class="dow">'+c+'</span>'});
    for(var i=0;i<cur.getDay();i++)h+='<span></span>';
    var d=new Date(cur); while(d.getMonth()===cur.getMonth()){var k=key(d),s=(data[k]||{}).status||'free';
      h+='<button type="button" class="d '+s+(date.value===k?' sel':'')+'" data-k="'+k+'" '+((s==='past'||s==='booked'||s==='blackout')?'disabled':'')+'>'+d.getDate()+'</button>'; d.setDate(d.getDate()+1);}
    cal.innerHTML=h+'</div>';
  }
  function load(){fetch(BRB.api+'?month='+cur.getFullYear()+'-'+pad(cur.getMonth()+1)).then(function(r){return r.json()}).then(function(j){Object.assign(data,j.days);render();showBusy();}).catch(render);}
  cal.addEventListener('click',function(e){var b=e.target.closest('button'); if(!b) return;
    if(b.dataset.n){cur.setMonth(cur.getMonth()+ +b.dataset.n);load();return;}
    date.value=b.dataset.k; showBusy(); render();});
  load();
})();
