(function () {
	function listText(list, key, fallback) {
		return (list && list.getAttribute('data-' + key)) || fallback;
	}
	function setState(row, state, elapsed) {
		if (!row) return;
		var list = row.closest('.xmrpay-node-list');
		var status = row.querySelector('.xmrpay-node-status');
		if (!status) return;
		var text = listText(list, 'status-' + state, state);
		if (typeof elapsed === 'number' && isFinite(elapsed)) { text += ' · ' + Math.max(0, Math.round(elapsed)) + ' ms'; }
		status.setAttribute('data-state', state);
		status.textContent = text;
	}
	function setBusy(list, busy) {
		if (!list) return;
		list.querySelectorAll('input, select, button').forEach(function (field) { field.disabled = busy; });
		var add = list.nextElementSibling;
		if (add && add.classList.contains('xmrpay-add-node')) { add.disabled = busy; }
		if (!busy) updateRows();
	}
	function updateRows() {
		document.querySelectorAll('.xmrpay-node-list').forEach(function (list) {
			var rows = list.querySelectorAll('.xmrpay-node-row');
			rows.forEach(function (row, index) {
				var auth = row.querySelector('.xmrpay-node-auth');
				var credentials = row.querySelector('.xmrpay-node-credentials');
				var title = row.querySelector('.xmrpay-node-title');
				var remove = row.querySelector('.xmrpay-remove-node');
				if (title) { title.textContent = listText(list, 'node-label', 'Node') + ' ' + (index + 1); }
				if (credentials) { credentials.hidden = !auth || auth.value === 'none'; }
				if (remove) { remove.disabled = rows.length === 1; }
				row.querySelectorAll('[name]').forEach(function (field) {
					field.name = field.name.replace(/node_configs\[\d+\]/, 'node_configs[' + index + ']');
				});
			});
		});
	}
	document.addEventListener('click', function (event) {
		if (event.target.classList.contains('xmrpay-add-node')) {
			var list = event.target.previousElementSibling;
			var row = list && list.querySelector('.xmrpay-node-row');
			if (row) {
				var copy = row.cloneNode(true);
				copy.setAttribute('data-password-saved', 'false');
				copy.querySelectorAll('small').forEach(function (note) { note.remove(); });
				copy.querySelectorAll('input').forEach(function (input) { input.value = ''; });
				var auth = copy.querySelector('.xmrpay-node-auth'); if (auth) auth.value = 'none';
				list.appendChild(copy); setState(copy, 'idle'); updateRows();
			}
		}
		if (event.target.classList.contains('xmrpay-remove-node')) {
			var current = event.target.closest('.xmrpay-node-row');
			if (current && current.parentNode.children.length > 1) { current.remove(); updateRows(); }
		}
	});
	document.addEventListener('change', function (event) {
		if (event.target.classList.contains('xmrpay-node-auth')) { updateRows(); }
	});
	window.xmrpayNodeFields = {
		setChecking: function (list) {
			if (!list) return;
			setBusy(list, true);
			list.querySelectorAll('.xmrpay-node-row').forEach(function (row) { setState(row, 'checking'); });
		},
		applyResults: function (list, checks) {
			if (!list) return;
			var rows = list.querySelectorAll('.xmrpay-node-row');
			rows.forEach(function (row) { setState(row, 'idle'); });
			Array.prototype.forEach.call(checks || [], function (check) {
				var index = Number(check.node) - 1;
				if (index < 0 || index >= rows.length) return;
				setState(rows[index], check.ok ? 'healthy' : 'warning', Number(check.elapsed_ms));
			});
			setBusy(list, false);
		},
		reset: function (list) {
			if (!list) return;
			list.querySelectorAll('.xmrpay-node-row').forEach(function (row) { setState(row, 'idle'); });
			setBusy(list, false);
		},
		updateRows: updateRows
	};
	updateRows();
}());
