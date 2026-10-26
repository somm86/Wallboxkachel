const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
process.env.TZ = 'Europe/Vienna';
const html = fs.readFileSync(__dirname + '/../WallboxTile/tile.html', 'utf8');
const script = html.split('<script>')[1].split('</script>')[0];
new vm.Script(script);
const nodes = {};
const context = {
  Date, Number, Math,
  state: {batterySoc: {value: 4}, batteryPower: {value: -704}, batteryRemaining: {text: '0:33'}},
  format: String,
  $: id => nodes[id] ??= {style: {}, setAttribute() {}, removeAttribute() {}},
  text: (el, value) => el.textContent = value
};
vm.createContext(context);
vm.runInContext(script.slice(script.indexOf('function batteryRemainingDisplay('), script.indexOf('function render(){')), context);
const now = new Date('2026-10-26T13:52:00Z');
const display = text => context.batteryRemainingDisplay({text}, now);
assert.equal(display('0:33').duration, '0 Std. 33 Min.');
assert.equal(display('0:33').until, 'bis 15:25 Uhr');
assert.equal(display('1:05').duration, '1 Std. 5 Min.');
assert.equal(display('10:00').until, 'bis morgen, 00:52 Uhr');
assert.match(display('48:00').until, /28\.10\.2026/);
assert.equal(display('0:00').duration, '0 Std. 0 Min.');
for (const value of ['0:60', '-1:20', '0.55', '', '<script>', '99999999999999999999999:00']) {
  assert.equal(display(value), null);
}
for (const [power, label] of [[704, 'Restzeit Laden'], [-704, 'Restzeit Entladen']]) {
  context.state.batteryPower.value = power;
  context.renderBattery();
  assert.equal(nodes.batteryRemainingLabel.textContent, label);
  assert.equal(nodes.batteryRemaining.textContent, '0 Std. 33 Min.');
  assert.match(nodes.batteryUntil.textContent, /^bis /);
}
context.state.batteryPower.value = 0;
context.renderBattery();
assert.equal(nodes.batteryRemaining.textContent, '–');
assert.equal(nodes.batteryUntil.textContent, '');
context.state.batteryPower.value = -704;
context.state.batteryRemaining = {missing: true};
context.renderBattery();
assert.equal(nodes.batteryRemaining.textContent, '–');
assert.equal(nodes.batteryUntil.textContent, '');
console.log('PASS: time formatting, end time, day rollover, invalid input, charging/discharging, idle and missing data');
