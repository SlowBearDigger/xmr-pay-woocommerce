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
        querySelectorAll(selector) { return this.children.filter(child => child.className === selector.slice(1)); }
    };
}
(async () => {
    for (const data of [
        { status: 'partial', reachable: false, receivedXmr: '0.007', shortfallXmr: '0.013' },
        { status: 'partial', reachable: true, receivedXmr: '0.007', shortfallXmr: '0.013' },
        { paid: true, reachable: false }
    ]) {
        const host = element(); host.attrs['data-poll'] = '/status';
        let timer;
        vm.runInNewContext(source, {
            document: { getElementById: () => host, createElement: element, head: element(), querySelector: () => null, addEventListener() {} },
            window: {}, setTimeout: fn => { timer = fn; }, clearTimeout() {},
            fetch: async () => ({ json: async () => data })
        });
        timer();
        await new Promise(resolve => setImmediate(resolve));
        const message = host.children[0].children[1].textContent;
        assert.match(message, data.paid ? /Payment confirmed/ : data.reachable ? /Received 0.007 XMR: send 0.013 more/ : /Connecting to the payment scanner/);
    }
    console.log('Checkout outage, partial recovery and paid priority passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
