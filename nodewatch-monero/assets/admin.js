// xmr-pay admin: the gateway settings "Test connection" (agent) and "Check setup"
// (no-server) buttons. Data (ajaxurl, nonces, strings) comes from window.xmrpayAdmin
// via wp_localize_script. Moved out of inline <script> blocks for Plugin Check.
(function () {
	var A = window.xmrpayAdmin || {};
	function val(id) { var e = document.getElementById(id); return e ? (e.value || '').trim() : ''; }
	function post(body) {
		return fetch(A.ajaxurl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then(function (r) { return r.json(); });
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
			nout.innerHTML = '<span style="color:#666">' + (A.checking || 'checking…') + '</span>';
			post(new URLSearchParams({ action: 'xmrpay_test_node', _wpnonce: A.nodeNonce, address: val('woocommerce_xmrpay_xmr_address'), view_key: val('woocommerce_xmrpay_view_key'), nodes: val('woocommerce_xmrpay_nodes') }))
				.then(function (d) {
					if (!d || !d.success) { nout.innerHTML = '<span style="color:#b91c1c">✗ ' + ((d && d.data && d.data.msg) || 'error') + '</span>'; return; }
					nout.innerHTML = (d.data.checks || []).map(function (c) {
						var col = c.ok ? '#15803d' : '#b91c1c';
						return '<div style="color:' + col + '">' + (c.ok ? '✓' : '✗') + ' ' + String(c.msg).replace(/[<>]/g, '') + '</div>';
					}).join('');
				})
				.catch(function () { nout.innerHTML = '<span style="color:#b91c1c">✗ ' + (A.reqfail || 'request failed') + '</span>'; });
		});
	}
})();
