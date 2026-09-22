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

function loadCopyControl(clipboard) {
    let click;
    const button = {
        textContent: 'Copy', className: '',
        getAttribute() { return 'copy-value'; },
        addEventListener(event, callback) { if (event === 'click') click = callback; }
    };
    const value = { textContent: '78test-address' };
    const context = {
        navigator: clipboard ? { clipboard } : {},
        document: {
            getElementById() { return value; },
            querySelectorAll() { return [button]; }
        },
        T: {}, setTimeout() {}, Promise
    };
    const wizard = fs.readFileSync(__dirname + '/../assets/wizard.js', 'utf8');
    const start = wizard.indexOf("document.querySelectorAll('.xp-copy-btn')");
    const end = wizard.indexOf("document.querySelectorAll('.xp-radio')", start);
    assert.ok(start >= 0 && end > start);
    const code = wizard.slice(start, end);
    vm.runInNewContext(code, context);
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

    loaded = loadWidget({ writeText() { throw new Error('denied'); } }, false);
    wired = wireAddressButton(loaded.Widget, address);
    wired.click();
    await settle();
    assert.match(wired.button.textContent, /Copy failed, select manually/);
    assert.match(wired.button.textContent, /78test-address/);
    console.log('PASS  synchronous clipboard failure remains visible');

    for (const [clipboard, expected] of [
        [{ writeText: () => Promise.resolve() }, 'Copied'],
        [{ writeText: () => Promise.reject(new Error('denied')) }, 'Copy failed'],
        [null, 'Copy failed'],
        [{ writeText() { throw new Error('denied'); } }, 'Copy failed']
    ]) {
        const control = loadCopyControl(clipboard);
        control.click();
        await settle();
        assert.ok(control.button.textContent.startsWith(expected), control.button.textContent);
    }
    console.log('PASS  wizard: success, rejection, absent API and synchronous failure');
    console.log('\nALL GREEN  8 clipboard scenarios passed');
}

run().catch((error) => {
    console.error(error.stack || error);
    process.exit(1);
});
