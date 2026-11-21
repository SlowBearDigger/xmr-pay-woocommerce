// Check polling messages when a node disconnects with a cached partial payment.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/checkout.js'), 'utf8');
function element() {
    return {
        children: [], style: {}, attrs: {},
        appendChild(child) { this.children.push(child); },
        getAttribute(key) { return this.attrs[key] || ''; },
        setAttribute(key, value) { this.attrs[key] = value; },
        querySelectorAll(selector) { return this.children.filter(child => child.className === selector.slice(1)); }
    };
}
(async () => {
    for (const data of [
        { status: 'partial', reachable: false, receivedXmr: '0.007', shortfallXmr: '0.013' },
        { status: 'partial', reachable: true, receivedXmr: '0.007', shortfallXmr: '0.013' },
        { paid: true, reachable: false },
        { status: 'fulfillment_pending', paid: false, reachable: true, receivedXmr: '0.000025', shortfallXmr: '0' },
        { status: 'fulfillment_pending', paid: false, reachable: true, initial: true }
    ]) {
        const host = element(); host.attrs['data-poll'] = '/status';
        if (data.initial) host.attrs['data-fulfillment-pending'] = '1';
        let widget = element();
        widget.attrs.address = 'merchant-subaddress';
        widget.attrs.amount = '0.000025';
        const parent = { replaceChild(replacement) { widget = replacement; widget.parentNode = parent; } };
        widget.parentNode = parent;
        let timer;
        vm.runInNewContext(source, {
            document: { getElementById: () => host, createElement: element, head: element(), querySelector: selector => selector.includes('xmr-pay') ? widget : null, addEventListener() {} },
            window: {}, setTimeout: fn => { timer = fn; }, clearTimeout() {},
            fetch: async () => ({ json: async () => data })
        });
        if (data.initial) {
            assert.equal(widget.hidden, true, 'server-known deferred fulfillment stays hidden before the first poll');
            assert.match(host.children[0].children[1].textContent, /Do not send another payment/);
        }
        timer();
        await new Promise(resolve => setImmediate(resolve));
        const message = host.children[0].children[1].textContent;
        assert.match(message, data.status === 'fulfillment_pending' ? /Payment received.*Finalizing.*Do not send another payment/ : data.paid ? /Payment confirmed/ : data.reachable ? /Received 0.007 XMR: send 0.013 more/ : /Connecting to the payment scanner/);
        if (data.status === 'fulfillment_pending' || data.paid) {
            assert.equal(widget.hidden, true, 'fully funded deferred orders hide the payment component');
            assert.equal(widget.style.display, 'none', 'visible QR and wallet instructions stay hidden');
        }
    }
    console.log('Checkout outage, partial recovery, paid priority and deferred fulfillment passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
