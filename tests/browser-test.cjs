const {chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html?demo');await page.waitForFunction(()=>document.querySelector('#connection').textContent==='Demovorschau');for(const [width,height] of [[1400,900],[700,650],[320,500],[1100,350]]){await page.setViewportSize({width,height});await page.waitForTimeout(100);const overflow=await page.evaluate(()=>document.querySelector('.tile').scrollWidth>document.documentElement.clientWidth);if(overflow)throw Error('Horizontal overflow '+width);await page.screenshot({path:`/tmp/wallbox-${width}.png`,fullPage:true});}const heights=[];
for(const height of [500,900,1400]){await page.setViewportSize({width:1400,height});await page.waitForTimeout(100);heights.push(await page.evaluate(()=>({padding:parseFloat(getComputedStyle(document.querySelector('.tile')).paddingTop),font:parseFloat(getComputedStyle(document.querySelector('.big')).fontSize),chart:document.querySelector('#chart').getBoundingClientRect().height,viewport:document.querySelector('.tile').getBoundingClientRect().height})));}
if(!(heights[0].padding<heights[1].padding&&heights[0].font<heights[1].font&&heights[0].chart<heights[2].chart))throw Error('Layout does not scale with height');
if(heights.some((v,i)=>Math.abs(v.viewport-[500,900,1400][i])>1))throw Error('Tile height does not follow viewport');
if(await page.locator('#controls .row').first().innerText().then(t=>!t.includes('Modul aktiv')))throw Error('Module switch must stay first');
for(const [power,label] of [[-704,'entladen'],[704,'geladen'],[0,'in Ruhe']]){await page.evaluate(power=>{state.batteryPower.value=power;renderBattery()},power);if(!(await page.locator('#batteryDirection').innerText()).includes(label))throw Error('Wrong battery direction '+power);if(!(await page.locator('#batteryPower').innerText()).includes(power>0?'+704':String(power)))throw Error('Battery power sign lost');}
await page.evaluate(()=>{state.batterySoc.value=4;renderBattery()});if(await page.locator('#batteryMeter').getAttribute('aria-valuenow')!=='4')throw Error('Battery SOC mismatch');
await page.evaluate(()=>{state.batterySoc={missing:true};state.batteryPower={missing:true};renderBattery()});if(await page.locator('#batteryDirection').innerText()!=='Keine Daten')throw Error('Missing battery data must not appear idle');
if(await page.locator('#carSoc').innerText()!=='62 %')throw Error('Car SOC missing');
if(await page.locator('#carRange').innerText()!=='200 km')throw Error('Car range missing');
await page.getByLabel('Soll-Ladestand',{exact:true}).selectOption('90');
if(await page.locator('#carTargetSelect').inputValue()!=='90')throw Error('Car target action failed');
if(await page.locator('#carTargetSelect option').count()!==11)throw Error('Expected 0-100 percent options');
for(const value of ['0','40','100']){await page.getByLabel('Soll-Ladestand',{exact:true}).selectOption(value);if(await page.locator('#carTargetSelect').inputValue()!==value)throw Error('Percent action failed '+value)}
await page.evaluate(()=>{state.carTarget.writable=false;renderCar()});
if(!await page.locator('#carTargetSelect').isDisabled())throw Error('Read-only car target enabled');
if(await page.locator('#gridPower').innerText()!=='-770 W')throw Error('Grid power missing');
await page.getByRole('button',{name:'Netzleistung – Verlauf öffnen',exact:true}).click();
await page.waitForFunction(()=>document.querySelectorAll('#gridChart path').length===1);
await page.keyboard.press('Escape');
if(await page.locator('#gridDialog').isVisible())throw Error('Grid dialog did not close');
await page.getByRole('button',{name:'Manuell',exact:true}).click();await page.waitForFunction(()=>document.querySelector('button[aria-pressed="true"]')!==null);if(await page.getByRole('button',{name:'Manuell',exact:true}).getAttribute('aria-pressed')!=='true')throw Error('Mode change failed');const toggle=page.getByRole('button',{name:'Ladefreigabe',exact:true});await toggle.click();if(await toggle.getAttribute('aria-pressed')!=='false')throw Error('Toggle failed');if(await page.locator('#chart path').count()!==3)throw Error('Missing chart series');if(await page.locator('#forecastChart').count()!==0)throw Error('Price chart still present');const active=page.getByRole('button',{name:'Modul aktiv',exact:true});await active.click();if(await active.getAttribute('aria-pressed')!=='false')throw Error('Module active toggle failed');if(await page.getByText(/Börsenpreis|Strompreise gültig/).count())throw Error('Price fields still present');await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html');await page.waitForFunction(()=>!document.querySelector('#error').hidden);await page.addInitScript(()=>{
 window.__nativeRequests=[];
 window.requestAction=(ident,value)=>{
  const request=JSON.parse(value);window.__nativeRequests.push({ident,...request});
  setTimeout(()=>{const response={id:request.id};if(window.__failNext){window.__failNext=false;response.error='Test: Aktion abgewiesen'}else response.result=window.demoAPI(request.op,request);window.handleMessage(JSON.stringify(response))},5);
 };
});
await page.goto('http://127.0.0.1:8080/WallboxTile/tile.html');
await page.waitForFunction(()=>document.querySelector('#connection').textContent==='Verbunden');
await page.getByLabel('Soll-Ladestand',{exact:true}).selectOption('70');
await page.waitForFunction(()=>document.querySelector('#carTargetSelect').value==='70');
if(!await page.evaluate(()=>window.__nativeRequests.some(r=>r.ident==='TileRequest'&&r.op==='action'&&r.key==='carTarget'&&r.value===70)))throw Error('Native action not sent');
await page.evaluate(()=>{window.__failNext=true});
await page.getByRole('button',{name:'Modul aktiv',exact:true}).click();
await page.waitForFunction(()=>document.querySelector('#error').textContent.includes('Aktion abgewiesen'));
await page.evaluate(()=>handleMessage(JSON.stringify({id:'other-client',result:{fields:{}}})));
if(await page.locator('#carRange').innerText()!=='200 km')throw Error('Unrelated client reply corrupted state');
if(errors.length)throw Error(errors.join('\n'));console.log('PASS: four sizes, height scaling at 500/900/1400px, battery charge/discharge/idle/missing states, SOC, first module switch, car readings/target dropdown/read-only mode, grid dialog, controls, charts and native bridge/actions/errors');await browser.close()})().catch(e=>{console.error(e);process.exit(1)});
