// Live, REAL payment progress on the order-received page. Everything shown is
// real on-chain state polled from the merchant's agent (proxied server-side):
// the order status, the confirmation count, and the live chain tip height — no
// fake progress bars. Steps: Watching → Detected → Confirming N/M → Confirmed.
(function () {
	var host = document.getElementById('xmrpay-status');
	if (!host) return;
	var url = host.getAttribute('data-poll');
	var already = host.getAttribute('data-paid') === '1';
	if (!url) return;

	var L = window.xmrpayL10n || {};
	var STEPS = [L.watching || 'Watching', L.detected || 'Detected', L.confirming || 'Confirming', L.confirmed || 'Confirmed'];

	// build the UI once
	var css = '@keyframes xpPulse{0%,100%{opacity:1}50%{opacity:.35}}'
		+ '.xp-prog{margin:6px 0 2px}'
		+ '.xp-steps{display:flex;align-items:center;gap:0;font-size:12px;color:#9ca3af;margin-bottom:8px}'
		+ '.xp-steps .s{display:flex;align-items:center;gap:6px;white-space:nowrap}'
		+ '.xp-steps .bar{flex:1;height:2px;background:#e5e7eb;margin:0 8px;min-width:14px}'
		+ '.xp-steps .dot{width:11px;height:11px;border-radius:50%;background:#e5e7eb;flex:none}'
		+ '.xp-steps .s.done{color:#15803d}.xp-steps .s.done .dot{background:#15803d}'
		+ '.xp-steps .bar.done{background:#15803d}'
		+ '.xp-steps .s.active{color:#b45309}.xp-steps .s.active .dot{background:#f59e0b;animation:xpPulse 1.2s ease-in-out infinite}'
		+ '.xp-msg{font-weight:600;font-size:14px}'
		+ '.xp-tip{font-size:11px;color:#9ca3af;font-variant-numeric:tabular-nums;margin-top:3px}';
	var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

	var wrap = document.createElement('div'); wrap.className = 'xp-prog';
	var steps = document.createElement('div'); steps.className = 'xp-steps';
	var dots = [];
	for (var i = 0; i < STEPS.length; i++) {
		if (i > 0) { var bar = document.createElement('span'); bar.className = 'bar'; steps.appendChild(bar); }
		var s = document.createElement('span'); s.className = 's';
		var d = document.createElement('span'); d.className = 'dot';
		var t = document.createElement('span'); t.textContent = STEPS[i];
		s.appendChild(d); s.appendChild(t); steps.appendChild(s); dots.push(s);
	}
	var msg = document.createElement('div'); msg.className = 'xp-msg';
	var tip = document.createElement('div'); tip.className = 'xp-tip';
	wrap.appendChild(steps); wrap.appendChild(msg); wrap.appendChild(tip);
	host.textContent = ''; host.appendChild(wrap);

	function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

	// underpaid: regenerate the QR for the EXACT shortfall (same subaddress → the
	// agent sums the top-up with what already arrived). done once per shortfall.
	var lastShortfall = null;
	function applyTopup(shortfall) {
		if (!shortfall || shortfall === lastShortfall) return;
		var old = document.querySelector('.xmrpay-panel xmr-pay') || document.querySelector('xmr-pay');
		if (!old) return;
		var addr = old.getAttribute('address');
		if (!addr) return;
		lastShortfall = shortfall;
		var el = document.createElement('xmr-pay');
		el.setAttribute('address', addr);
		el.setAttribute('amount', shortfall);
		['label', 'theme', 'lang'].forEach(function (a) { var v = old.getAttribute(a); if (v) el.setAttribute(a, a === 'label' ? v + ' (top-up)' : v); });
		old.parentNode.replaceChild(el, old);
	}

	// status → active step index (0..3)
	function stepFor(d) {
		if (d.paid || d.status === 'paid') return 3;
		if (d.status === 'unconfirmed' || d.status === 'partial' || d.status === 'locked') return 2;
		if (d.status === 'mempool') return 1;
		return 0; // pending
	}
	function paint(d) {
		var active = stepFor(d);
		var bars = steps.querySelectorAll('.bar');
		for (var i = 0; i < dots.length; i++) {
			dots[i].className = 's' + (i < active ? ' done' : (i === active ? ' active' : ''));
			if (i > 0) bars[i - 1].className = 'bar' + (i <= active ? ' done' : '');
		}
		var text;
		if (d.paid) { text = '✓ ' + (L.paid || 'Payment confirmed'); msg.style.color = '#15803d'; }
		else {
			msg.style.color = '#b45309';
			if (d.status === 'mempool') text = L.mMempool || 'Payment detected — waiting for the first confirmation.';
			else if (d.status === 'unconfirmed') text = (L.mConfirming || 'Confirming — {c}/{m} confirmations.').replace('{c}', d.confirmations != null ? d.confirmations : 0).replace('{m}', d.minConfirmations != null ? d.minConfirmations : 1);
			else if (d.status === 'partial') { text = (L.mPartial || 'Received {r} XMR — send {s} more (QR updated).').replace('{r}', d.receivedXmr != null ? d.receivedXmr : '?').replace('{s}', d.shortfallXmr || '?'); applyTopup(d.shortfallXmr); }
			else if (d.status === 'locked') text = L.mLocked || 'Funds received — maturing on-chain…';
			else if (d.reachable === false) text = L.mConnecting || 'Connecting to the payment scanner…';
			else text = L.mWatching || 'Watching the blockchain for your payment…';
		}
		msg.textContent = text;
		tip.textContent = (d.tipHeight ? ((L.block || 'Latest block') + ' #' + fmt(d.tipHeight)) : '');
	}

	if (already) { paint({ paid: true }); return; }
	paint({ status: 'pending' });

	var stopped = false;
	function tick() {
		if (stopped) return;
		fetch(url, { headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				paint(d || {});
				if (d && d.paid) { stopped = true; setTimeout(function () { location.reload(); }, 1800); }
			})
			.catch(function () { /* transient — keep watching */ });
	}
	setInterval(tick, 6000);
	setTimeout(tick, 1200);
})();
