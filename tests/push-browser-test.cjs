const {chromium} = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
      window.__calls = [];
      window.__pushOn = true;
      window.__dropAction = false;
      window.requestAction = (ident, body) => {
        const request = JSON.parse(body);
        window.__calls.push(request);
        if (request.op !== 'action') throw Error('Read requests are unavailable in this mobile bridge');
        if (!window.__dropAction) setTimeout(() => handleMessage({id: request.id, result: demoAPI(request.op, request)}), 5);
      };
      window.addEventListener('load', () => {
        const fields = demoAPI('state').fields;
        const history = demoAPI('history');
        const push = () => {
          if (!window.__pushOn) return;
          for (const key of ['power', 'pv', 'house', 'gridPower']) handleMessage({event: 'update', fields, history: {[key]: history[key]}});
        };
        push();
        setInterval(push, 5000);
      });
    });
    await page.clock.install();
    await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html');
    await page.waitForFunction(() => document.getElementById('connection').textContent === 'Verbunden');
    await page.clock.runFor(70000);
    assert.equal(await page.evaluate(() => window.__calls.length), 0, 'Live values, history, and watchdog must not invoke the read bridge');
    assert.equal(await page.locator('#chart path').count(), 3);
    assert.equal(await page.locator('#error').isVisible(), false);
    await page.evaluate(() => {window.__pushOn = false;});
    await page.clock.runFor(20000);
    assert.equal(await page.locator('#connection').textContent(), 'Keine aktuellen Daten');
    assert.equal(await page.locator('#error').isVisible(), true);
    await page.evaluate(() => {window.__pushOn = true;});
    await page.clock.runFor(5000);
    assert.equal(await page.locator('#connection').textContent(), 'Verbunden');
    assert.equal(await page.locator('#error').isVisible(), false);
    await page.evaluate(() => {void act('enabled', false);});
    await page.clock.runFor(50);
    assert.equal(await page.evaluate(() => state.enabled.value), false);
    await page.evaluate(() => {window.__dropAction = true; void act('enabled', true);});
    await page.clock.runFor(31000);
    assert.match(await page.locator('#error').textContent(), /Steuerbefehl/);
    assert.equal(await page.evaluate(() => window.__calls.length), 2, 'Only the two explicit control actions use the bridge, with no retries');
    assert.deepEqual(errors, []);
    console.log('PASS: live values and history without read RPC, stale stream and recovery, explicit control action, dropped action without retry');
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
