const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const html = fs.readFileSync(__dirname + '/../WallboxTile/tile.html', 'utf8');
const script = html.split('<script>')[1].split('</script>')[0];
new vm.Script(script);

function fixture() {
  const nodes = {}, timers = new Map();
  let now = 1000000, nextTimer = 0;
  const context = {
    Date: {now: () => now}, Math, crypto: webcrypto,
    document: {hidden: false}, tileVisible: true, demo: false,
    pendingRequests: new Map(), state: {}, historyData: {},
    busy: false, editing: false, refreshInFlight: false, historyInFlight: null,
    chartSignatures: new Map(), historyError: '',
    stateRevision: 0, lastHistory: 0, historyRetryAt: 0, pollFailures: 0, pollTimer: 0,
    $: id => nodes[id] ??= {style: {}},
    text: (el, value) => el.textContent = value,
    error: value => context.lastError = value,
    render() {}, draw() {},
    setTimeout: (fn, delay) => {timers.set(++nextTimer, {fn, delay}); return nextTimer;},
    clearTimeout: id => timers.delete(id)
  };
  vm.createContext(context);
  vm.runInContext(script.slice(script.indexOf('function handleMessage('), script.indexOf('const controls=')), context);
  vm.runInContext(script.slice(script.indexOf('async function api('), script.indexOf('function canControl(')), context);
  vm.runInContext(script.slice(script.indexOf('function isVisible('), script.indexOf('function demoAPI(')), context);
  return {context, nodes, timers, advance: ms => now += ms};
}

(async () => {
  const {context: c, nodes, timers, advance} = fixture();
  let stateCalls = 0, historyCalls = 0, historyFails = true, stateFails = false;
  c.api = async (op, extra) => {
    if (op === 'state') {
      stateCalls++;
      if (stateFails) throw Error('Werte nicht erreichbar');
      return {fields: {power: {value: 3680}}};
    }
    historyCalls++;
    if (historyFails) throw Error('Archiv langsam');
    return {[extra.series]: {points: [[123, 3680]]}};
  };
  await c.refresh();
  assert.equal(nodes.connection.textContent, 'Verbunden', 'History errors must not report a lost state connection');
  assert.equal(c.lastError, '');
  assert.match(nodes.chartNote.textContent, /Archiv langsam/);
  assert.equal(c.state.power.value, 3680);
  for (let i = 0; i < 5; i++) {advance(5000); await c.refresh();}
  assert.equal(stateCalls, 6, 'Live values continue after failed history');
  assert.equal(historyCalls, 1, 'Failed history must not be retried every poll');
  advance(35000);
  historyFails = false;
  await Promise.all([c.loadHistory(), c.loadHistory()]);
  assert.equal(historyCalls, 5, 'One history retry after the cooldown');
  assert.equal(c.historyData.power.points[0][1], 3680);
  assert.equal(c.historyRetryAt, 0);
  stateFails = true;
  for (const delay of [10000, 20000, 40000, 60000, 60000]) {
    await c.refresh();
    c.schedulePoll();
    assert.equal(timers.get(c.pollTimer).delay, delay, 'Back off during connection failures');
  }
  assert.match(nodes.connection.textContent, /Keine Verbindung/);
  assert.equal(c.state.power.value, 3680, 'Keep last known readings on failure');
  stateFails = false;
  await c.refresh(); c.schedulePoll();
  assert.equal(timers.get(c.pollTimer).delay, 5000, 'Resume normal polling after recovery');
  assert.equal(nodes.connection.textContent, 'Verbunden');
  c.document.hidden = true;
  const callsBefore = stateCalls;
  await c.refresh(); c.schedulePoll();
  assert.equal(stateCalls, callsBefore, 'Hidden tiles must not poll');
  assert.equal(timers.size, 0);

  const transport = fixture();
  const t = transport.context;
  const requests = [];
  t.requestAction = (ident, body) => requests.push(JSON.parse(body));
  const slowState = t.api('state');
  assert.equal([...transport.timers.values()][0].delay, 30000);
  transport.advance(20000);
  t.handleMessage(JSON.stringify({id: requests[0].id, result: {fields: {}}}));
  await slowState;
  assert.equal(t.pendingRequests.size, 0, 'Accept slow state responses and clear timers');
  const action = t.api('action', {key: 'enabled', value: true});
  const rejectedAction = assert.rejects(action, /Steuerbefehl/);
  [...transport.timers.values()][0].fn();
  await rejectedAction;
  assert.equal(requests.filter(r => r.op === 'action').length, 1, 'Never automatically repeat control actions');
  assert.equal(t.pendingRequests.size, 0);
  t.handleMessage(JSON.stringify({id: requests[1].id, result: {fields: {}}}));
  const history = t.api('history');
  assert.equal([...transport.timers.values()].at(-1).delay, 60000);
  t.handleMessage(JSON.stringify({id: requests[2].id, result: {power: {points: []}}}));
  await history;
  console.log('PASS: isolated history errors, history cooldown, continued live values, connection backoff/recovery, hidden tiles, slow replies, action timeout without retry');
})().catch(e => {console.error(e); process.exitCode = 1;});
