// xmr-pay admin: the gateway settings "Test connection" (agent) and "Check setup"
// (no-server) buttons. Data (ajaxurl, nonces, strings) comes from window.xmrpayAdmin
// via wp_localize_script. Moved out of inline <script> blocks for Plugin Check.
(function () {
	var A = window.xmrpayAdmin || {};
	function val(id) { var e = document.getElementById(id); return e ? (e.value || '').trim() : ''; }
	function nodeRows() {
		return Array.prototype.map.call(document.querySelectorAll('.xmrpay-node-list .xmrpay-node-row'), function (row) {
			function rowVal(selector) { var field = row.querySelector(selector); return field ? (field.value || '').trim() : ''; }
			return { url: rowVal('.xmrpay-node-url'), auth: rowVal('.xmrpay-node-auth') || 'none', username: rowVal('[name$="[username]"]'), password: rowVal('[name$="[password]"]') };
		});
	}
	function post(body) {
		return fetch(A.ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then(function (r) { return r.json(); });
	}
	function formatElapsed(milliseconds) {
		var seconds = Math.floor(Math.max(0, milliseconds) / 1000);
		var minutes = Math.floor(seconds / 60);
		return String(minutes).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
	}
	function startNodeTimer(out, list, count) {
		var started = Date.now();
		if (window.xmrpayNodeFields) { window.xmrpayNodeFields.setChecking(list); }
		function render() { out.textContent = (A.checking || 'checking…') + ' ' + count + ' ' + (A.nodes || 'nodes') + ' · ' + formatElapsed(Date.now() - started); }
		render();
		return setInterval(render, 250);
	}

	// Agent mode — "Test connection"
	var agentBtn = document.getElementById('xmrpay-test-agent');
	if (agentBtn) {
		var out = document.getElementById('xmrpay-test-result');
		agentBtn.addEventListener('click', function () {
			out.style.color = '#666'; out.textContent = A.testing || 'testing…';
			post(new URLSearchParams({ action: 'xmrpay_test_agent', _wpnonce: A.agentNonce, url: val('woocommerce_xmrpay_agent_url'), token: val('woocommerce_xmrpay_agent_token') }))
				.then(function (d) {
					if (d && d.success) { out.style.color = '#15803d'; out.textContent = '✓ ' + ((d.data && d.data.msg) || 'OK'); }
					else { out.style.color = '#b91c1c'; out.textContent = '✗ ' + ((d && d.data && d.data.msg) || A.unreachable || 'unreachable'); }
				})
				.catch(function () { out.style.color = '#b91c1c'; out.textContent = '✗ ' + (A.reqfail || 'request failed'); });
		});
	}

	// No-server modes — "Check setup": node + network + view-key-matches-address
	var nodeBtn = document.getElementById('xmrpay-test-node');
	if (nodeBtn) {
		var nout = document.getElementById('xmrpay-node-result');
		nodeBtn.addEventListener('click', function () {
			var list = document.querySelector('.xmrpay-node-list');
			var rows = nodeRows();
			var timer = startNodeTimer(nout, list, rows.length);
			post(new URLSearchParams({ action: 'xmrpay_test_node', _wpnonce: A.nodeNonce, address: val('woocommerce_xmrpay_xmr_address'), view_key: val('woocommerce_xmrpay_view_key'), node_configs:JSON.stringify(rows) }))
				.then(function (d) {
					clearInterval(timer);
					if (!d || !d.success) { if (window.xmrpayNodeFields) window.xmrpayNodeFields.reset(list); nout.innerHTML = '<span style="color:#b91c1c">✗ ' + ((d && d.data && d.data.msg) || 'error') + '</span>'; return; }
					if (window.xmrpayNodeFields) { window.xmrpayNodeFields.applyResults(list, d.data.checks || []); }
					nout.innerHTML = (d.data.checks || []).map(function (c) {
						var warning = !c.ok && c.warning;
						var col = c.ok ? '#15803d' : (warning ? '#b45309' : '#b91c1c');
						return '<div style="color:' + col + '">' + (c.ok ? '✓' : (warning ? '⚠' : '✗')) + ' ' + String(c.msg).replace(/[<>]/g, '') + '</div>';
					}).join('');
				})
				.catch(function () { clearInterval(timer); if (window.xmrpayNodeFields) window.xmrpayNodeFields.reset(list); nout.innerHTML = '<span style="color:#b91c1c">✗ ' + (A.reqfail || 'request failed') + '</span>'; });
		});
	}
})();
