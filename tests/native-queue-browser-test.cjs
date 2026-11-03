const {chromium} = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
      window.__nativeActive = 0;
      window.__maxNativeActive = 0;
      window.__requests = [];
      window.requestAction = (ident, body) => {
        const request = JSON.parse(body);
        window.__requests.push(request);
        window.__nativeActive++;
        window.__maxNativeActive = Math.max(window.__maxNativeActive, window.__nativeActive);
        return new Promise((resolve, reject) => {
          if (window.__nativeActive > 1) {
            window.__nativeActive--;
            reject(Error('Die Verbindung wurde während der Anfrage unterbrochen'));
            return;
          }
          // Data notification arrives before the native invocation is acknowledged.
          setTimeout(() => handleMessage(JSON.stringify({id: request.id, result: demoAPI(request.op, request)})), 5);
          setTimeout(() => {window.__nativeActive--; resolve();}, 35);
        });
      };
    });
    await page.addInitScript(require('./native-push-fixture.cjs'));
    await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html');
    await page.waitForFunction(() => document.getElementById('connection').textContent === 'Verbunden' && !refreshInFlight);
    await page.evaluate(async () => {
      clearTimeout(pollTimer);
      await Promise.all([api('state'), api('history', {series: 'power'}), api('state')]);
      await act('enabled', false);
    });
    assert.equal(await page.evaluate(() => window.__maxNativeActive), 1, 'Polling, history and actions must wait for complete native calls');
    assert.equal(await page.locator('#error').isVisible(), false);
    assert.equal(await page.evaluate(() => pendingRequests.size), 0);
    assert.equal(await page.evaluate(() => window.__requests.filter(r => r.op === 'action').length), 1);
    assert.deepEqual(errors, []);
    console.log('PASS: Chromium with early data notifications, delayed native acknowledgements, concurrent callers and single control action');
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
