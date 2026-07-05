// xmr-pay guided setup wizard. Data (ajaxurl, nonces, hasConst, strings) comes from
// window.xmrpayWizard via wp_localize_script. Moved out of an inline <script> for Plugin Check.
(function () {
	var W = window.xmrpayWizard || {};
	var T = W.i18n || {};
	var ajaxurl = W.ajaxurl, testNonce = W.testNonce, nodeNonce = W.nodeNonce, saveNonce = W.saveNonce;
	var HAS_CONST = !!W.hasConst;
	var LAST = 3;
	var step = 0, tested = false;
	var steps = document.querySelectorAll('.xp-step');
	var dots  = document.querySelectorAll('#xp-steps .s');
	var back  = document.getElementById('xp-back');
	var next  = document.getElementById('xp-next');
	var foot  = document.getElementById('xp-foot');
	if (!next) { return; }

	function currentMode(){ var r=document.querySelector('input[name=xp-mode]:checked'); return r?r.value:'watch'; }
	function applyMode(){
		var agent = currentMode() === 'agent';
		var ns = document.querySelector('[data-panel=noserver]'), ag = document.querySelector('[data-panel=agent]');
		if (ns) ns.style.display = agent ? 'none' : 'block';
		if (ag) ag.style.display = agent ? 'block' : 'none';
	}
	function val(id){ var e=document.getElementById(id); return e?e.value:''; }

	function show(n){
		steps.forEach(function(s){ s.classList.toggle('show', s.getAttribute('data-step') === String(n)); });
		dots.forEach(function(d,i){ d.classList.toggle('active', i === n); d.classList.toggle('done', i < n); });
		back.style.visibility = (n > 0 && n <= LAST) ? 'visible' : 'hidden';
		next.textContent = (n === LAST) ? (T.finish||'Finish') : (T.next||'Next');
		if (n === 1) applyMode();
		step = n;
	}

	back.addEventListener('click', function(){ if (step > 0) show(step - 1); });

	next.addEventListener('click', function(){
		if (step === 1){
			if (currentMode() === 'agent'){
				if (!tested){ runTest(function(ok){ if (ok) show(2); }); return; }
			} else {
				if (!(val('xp-addr')||'').trim()){ var a=document.getElementById('xp-addr'); if(a)a.focus(); return; }
				if (!HAS_CONST && !(val('xp-view')||'').trim()){ var v=document.getElementById('xp-view'); if(v)v.focus(); return; }
			}
		}
		if (step === LAST){ finish(); return; }
		show(step + 1);
	});

	// live connection test (agent mode; reuses the gateway's ajax_test_agent)
	function runTest(cb){
		var out = document.getElementById('xp-test-result');
		var url = (val('xp-agent-url')||'').trim(), token = (val('xp-agent-token')||'').trim();
		if (!url){ if(out){out.style.color='#b91c1c';out.textContent='✗ '+(T.enterUrl||'enter the Agent URL first');} if(cb)cb(false); return; }
		if(out){out.style.color='#6b7280';out.textContent=(T.testing||'testing…');}
		var body = new URLSearchParams({action:'xmrpay_test_agent', _wpnonce:testNonce, url:url, token:token});
		fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
			.then(function(r){return r.json();})
			.then(function(d){
				if (d && d.success){ tested=true; if(out){out.style.color='#15803d';out.textContent='✓ '+((d.data&&d.data.msg)||'OK');} if(cb)cb(true); }
				else { tested=false; if(out){out.style.color='#b91c1c';out.textContent='✗ '+((d&&d.data&&d.data.msg)||'unreachable');} if(cb)cb(false); }
			})
			.catch(function(){ tested=false; if(out){out.style.color='#b91c1c';out.textContent='✗ '+(T.reqfail||'request failed');} if(cb)cb(false); });
	}
	// no-server "Test setup": node + network + view-key-matches-address
	function testNode(){
		var out = document.getElementById('xp-node-result'); if(!out) return;
		out.innerHTML = '<span style="color:#6b7280">'+(T.testing||'testing…')+'</span>';
		var body = new URLSearchParams({ action:'xmrpay_test_node', _wpnonce:nodeNonce,
			address:(val('xp-addr')||'').trim(), view_key:(val('xp-view')||'').trim(), nodes:(val('xp-nodes')||'').trim() });
		fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
			.then(function(r){return r.json();})
			.then(function(d){
				if(!d||!d.success){ out.innerHTML='<span style="color:#b91c1c">✗ '+((d&&d.data&&d.data.msg)||'error')+'</span>'; return; }
				out.innerHTML = (d.data.checks||[]).map(function(c){
					var col = c.ok ? '#15803d' : '#b91c1c';
					return '<div style="color:'+col+';font-size:13px;margin:2px 0">'+(c.ok?'✓':'✗')+' '+String(c.msg).replace(/[<>]/g,'')+'</div>';
				}).join('');
			})
			.catch(function(){ out.innerHTML='<span style="color:#b91c1c">✗ '+(T.reqfail||'request failed')+'</span>'; });
	}

	document.addEventListener('click', function(e){
		if (e.target && e.target.id === 'xp-test') runTest(null);
		if (e.target && e.target.id === 'xp-test-node') testNode();
	});
	document.addEventListener('input', function(e){ if (e.target && (e.target.id==='xp-agent-url' || e.target.id==='xp-agent-token')) tested=false; });

	document.querySelectorAll('.xp-copy-btn').forEach(function(b){
		b.addEventListener('click', function(){
			var el = document.getElementById(b.getAttribute('data-copy'));
			navigator.clipboard && navigator.clipboard.writeText(el ? el.textContent : '');
			var o = b.textContent; b.textContent=(T.copied||'Copied');
			setTimeout(function(){ b.textContent=o; }, 1400);
		});
	});

	document.querySelectorAll('.xp-radio').forEach(function(card){
		var radio = card.querySelector('input');
		card.addEventListener('click', function(){
			radio.checked = true;
			var name = radio.getAttribute('name');
			document.querySelectorAll('.xp-radio').forEach(function(c){
				var ci = c.querySelector('input');
				if (!ci || ci.getAttribute('name') !== name) return;
				var on = c === card;
				c.classList.toggle('sel', on);
				var cond = c.querySelector('.xp-cond'); if (cond) cond.style.display = on ? 'block' : 'none';
			});
			if (name === 'xp-mode') applyMode();
		});
	});
	var firstCond = document.querySelector('.xp-radio.sel .xp-cond'); if (firstCond) firstCond.style.display='block';

	function priceSource(){ var r=document.querySelector('input[name=xp-price]:checked'); return r?r.value:'coingecko'; }
	function finish(){
		next.disabled = true; next.textContent=(T.saving||'Saving…');
		var p = { action:'xmrpay_setup_save', _wpnonce:saveNonce, mode:currentMode(),
			price_source:priceSource(), fixed_rate:val('xp-fixed'), coingecko_api_key:val('xp-cg-key'),
			title:val('xp-title'), checkout_theme:val('xp-theme') };
		if (currentMode() === 'agent'){
			p.agent_url=val('xp-agent-url'); p.agent_token=val('xp-agent-token'); p.webhook_secret=val('xp-webhook-secret');
		} else {
			p.xmr_address=val('xp-addr'); p.view_key=val('xp-view'); p.nodes=val('xp-nodes'); p.proof_min_conf=val('xp-minconf');
		}
		fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)})
			.then(function(r){return r.json();})
			.then(function(d){
				next.disabled=false;
				if (d && d.success){
					document.getElementById('xp-link-shop').href = d.data.shop_url || '#';
					document.getElementById('xp-link-settings').href = d.data.settings_url || '#';
					foot.style.display='none';
					show('done');
					dots.forEach(function(dd){ dd.classList.add('done'); dd.classList.remove('active'); });
				} else {
					next.textContent=(T.finish||'Finish');
					alert((d&&d.data&&d.data.msg)||(T.couldNotSave||'Could not save. Try again.'));
				}
			})
			.catch(function(){ next.disabled=false; next.textContent=(T.finish||'Finish'); alert(T.requestFailed||'Request failed.'); });
	}

	show(0);
})();
