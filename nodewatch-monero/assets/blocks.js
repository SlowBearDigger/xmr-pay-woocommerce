// Register the Monero gateway in the WooCommerce Blocks (React) checkout, so it
// appears as a payment option. Order processing still runs through the classic
// gateway's process_payment(); this is just the Blocks-side presence + label.
(function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var el = window.wp && window.wp.element;
	if (!registry || !settingsApi || !el) return;

	var data = settingsApi.getSetting('xmrpay_data', {});
	var label = data.title || 'Monero (XMR)';
	var decode = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };

	var Description = function () {
		return el.createElement('div', { style: { lineHeight: 1.5 } }, decode(data.description || ''));
	};

	registry.registerPaymentMethod({
		name: 'xmrpay',
		label: decode(label),
		ariaLabel: decode(label),
		content: el.createElement(Description),
		edit: el.createElement(Description),
		canMakePayment: function () { return true; },
		supports: {
			features: (data.supports && data.supports.length) ? data.supports : ['products'],
		},
	});
})();
