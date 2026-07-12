const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../assets/xmr-pay.js', 'utf8');

function loadWidget(clipboard, legacyCopy) {
    let Widget;
    const timers = [];
    const stats = { legacyCalls: 0 };
    const document = {
        body: {
            appendChild() {},
            removeChild() {}
        },
        createElement() {
            return {
                value: '',
                style: {},
                setAttribute() {},
                select() {},
                setSelectionRange() {}
            };
        },
        execCommand(command) {
            stats.legacyCalls++;
            return command === 'copy' && legacyCopy;
        }
    };
    const context = {
        console,
        document,
        navigator: clipboard ? { clipboard } : {},
        HTMLElement: class {
            getAttribute() { return ''; }
            dispatchEvent() {}
        },
        customElements: {
            get() { return null; },
            define(name, implementation) { Widget = implementation; }
        },
        setTimeout(callback) { timers.push(callback); return timers.length; },
        clearTimeout() {},
        Promise,
        URL,
        TextEncoder,
        TextDecoder
    };
    vm.runInNewContext(source, context);
    return { Widget, timers, stats };
}

function wireAddressButton(Widget, address) {
    let click;
    const button = {
        innerHTML: address,
        textContent: address,
        addEventListener(event, callback) {
            if (event === 'click') click = callback;
        }
    };
    const root = {
        querySelector(selector) { return selector === '.addr' ? button : null; },
        querySelectorAll() { return []; }
    };
    new Widget()._wire(root, address, '', {
        copied: 'Copied',
        copyFail: 'Copy failed, select manually'
    });
    return { button, click };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
}

async function run() {
    const address = '78test-address';

    let loaded = loadWidget({ writeText: () => Promise.resolve() }, false);
    let wired = wireAddressButton(loaded.Widget, address);
    wired.click();
    await settle();
    assert.equal(wired.button.textContent, 'Copied');
    assert.equal(loaded.stats.legacyCalls, 0);
    console.log('PASS  confirmed clipboard write reports success');

    loaded = loadWidget({ writeText: () => Promise.reject(new Error('denied')) }, true);
    wired = wireAddressButton(loaded.Widget, address);
    wired.click();
    await settle();
    assert.equal(wired.button.textContent, 'Copied');
    assert.equal(loaded.stats.legacyCalls, 1);
    console.log('PASS  rejected Clipboard API uses the legacy fallback');

    loaded = loadWidget(null, false);
    wired = wireAddressButton(loaded.Widget, address);
    wired.click();
    await settle();
    assert.match(wired.button.textContent, /Copy failed, select manually/);
    assert.match(wired.button.textContent, /78test-address/);
    assert.notEqual(wired.button.textContent, 'Copied');
    console.log('PASS  total copy failure stays honest and keeps the address visible');

    console.log('\nALL GREEN  3 passed, 0 failed');
}

run().catch((error) => {
    console.error(error.stack || error);
    process.exit(1);
});
