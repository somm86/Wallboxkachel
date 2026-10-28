// Regression checks for work avoided; this is not a real-device CPU benchmark.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => {
      window.__calls = [];
      window.__delay = 2;
      window.requestAction = (ident, body) => {
        const request = JSON.parse(body);
        window.__calls.push(request.op);
        setTimeout(() => handleMessage(JSON.stringify({id:request.id, result:demoAPI(request.op,request)})), window.__delay);
      };
    });
    await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html');
    await page.waitForFunction(() => document.querySelector('#connection').textContent === 'Verbunden' && !refreshInFlight);
    const result = await page.evaluate(async () => {
      clearTimeout(pollTimer);
      const select = document.getElementById('carTargetSelect');
      const control = document.querySelector('#controls button');
      const chart = document.querySelector('#chart path');
      let mutations = 0;
      const observer = new MutationObserver(entries => mutations += entries.length);
      for (const id of ['controls', 'carTarget', 'details', 'chart']) observer.observe(document.getElementById(id), {subtree:true,childList:true,attributes:true,characterData:true});
      for (let i=0; i<10; i++) await refresh();
      // A changing sensor must not rebuild independent controls.
      state.power.value += 100;
      render();
      draw();draw();
      await new Promise(resolve => setTimeout(resolve, 0));
      const stable = {mutations, sameSelect:select===document.getElementById('carTargetSelect'), sameControl:control===document.querySelector('#controls button'),sameChart:chart===document.querySelector('#chart path')};
      observer.disconnect();
      window.__delay = 30;
      let before=window.__calls.filter(op=>op==='state').length;
      await Promise.all([refresh(),refresh(),refresh()]);
      const parallelPolls=window.__calls.filter(op=>op==='state').length-before;
      lastHistory=0;
      before=window.__calls.filter(op=>op==='history').length;
      await Promise.all([loadHistory(),loadHistory(),loadHistory()]);
      const parallelHistory=window.__calls.filter(op=>op==='history').length-before;
      await loadHistory();
      const cachedHistory=window.__calls.filter(op=>op==='history').length-before;
      window.__hidden=true;
      Object.defineProperty(document,'hidden',{configurable:true,get:()=>window.__hidden});
      document.dispatchEvent(new Event('visibilitychange'));
      before=window.__calls.length;
      await refresh();
      await new Promise(resolve=>setTimeout(resolve,50));
      const hiddenCalls=window.__calls.length-before;
      window.__hidden=false;
      document.dispatchEvent(new Event('visibilitychange'));
      await new Promise(resolve=>setTimeout(resolve,80));
      const resumed=window.__calls.length>before;
      clearTimeout(pollTimer);
      return {stable,parallelPolls,parallelHistory,cachedHistory,hiddenCalls,resumed};
    });
    assert.equal(result.stable.mutations,0,'Unchanged controls/details/charts must not mutate');
    assert.ok(result.stable.sameSelect && result.stable.sameControl && result.stable.sameChart,'Preserve existing elements');
    assert.equal(result.parallelPolls,1,'Deduplicate concurrent polls');
    assert.equal(result.parallelHistory,1,'Deduplicate concurrent history requests');
    assert.equal(result.cachedHistory,1,'Reuse fresh client history');
    assert.equal(result.hiddenCalls,0,'Pause hidden tile');
    assert.equal(result.resumed,true,'Refresh when visible again');
    assert.deepEqual(errors,[]);
    console.log('PASS:',JSON.stringify(result));
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
