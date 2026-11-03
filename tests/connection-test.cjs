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
    Date: {now: () => now}, Math, crypto: webcrypto, TextEncoder,
    document: {hidden: false}, tileVisible: true, demo: false,
    nativeQueue: Promise.resolve(), transportTrace: [],
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
  vm.runInContext(script.slice(script.indexOf('function traceTransport('), script.indexOf('const controls=')), context);
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
  await Promise.resolve();
  assert.equal([...transport.timers.values()][0].delay, 30000);
  transport.advance(20000);
  t.handleMessage(JSON.stringify({id: requests[0].id, result: {fields: {}}}));
  await slowState;
  assert.equal(t.pendingRequests.size, 0, 'Accept slow state responses and clear timers');
  const action = t.api('action', {key: 'enabled', value: true});
  const rejectedAction = assert.rejects(action, /Steuerbefehl/);
  await Promise.resolve();
  [...transport.timers.values()][0].fn();
  await rejectedAction;
  assert.equal(requests.filter(r => r.op === 'action').length, 1, 'Never automatically repeat control actions');
  assert.equal(t.pendingRequests.size, 0);
  t.handleMessage(JSON.stringify({id: requests[1].id, result: {fields: {}}}));
  const history = t.api('history');
  await Promise.resolve();
  assert.equal([...transport.timers.values()].at(-1).delay, 60000);
  t.handleMessage(JSON.stringify({id: requests[2].id, result: {power: {points: []}}}));
  await history;

  // Symcon may deliver UpdateVisualizationValue before requestAction's Promise settles.
  const queued = fixture();
  const q = queued.context, sent = [], acknowledgements = [];
  const tick = () => new Promise(setImmediate);
  q.requestAction = (ident, body) => {
    sent.push(JSON.parse(body));
    return new Promise((resolve, reject) => acknowledgements.push({resolve, reject}));
  };
  let firstDone = false;
  const first = q.api('state').then(value => {firstDone = true; return value;});
  const second = q.api('history', {series: 'power'});
  await tick();
  assert.equal(sent.length, 1, 'Only one native request starts at a time');
  q.handleMessage({id: sent[0].id, result: {fields: {}}});
  await tick();
  assert.equal(firstDone, false, 'Data notification alone must not finish a pending native call');
  assert.equal(sent.length, 1, 'Wait for native acknowledgement before sending another request');
  acknowledgements[0].resolve();
  await first; await tick();
  assert.equal(sent.length, 2);
  acknowledgements[1].resolve();
  await tick();
  assert.equal(q.pendingRequests.size, 1, 'Native acknowledgement alone still waits for the data response');
  q.handleMessage({id: sent[1].id, result: {power: {points: []}}});
  await second;
  const lateFailure = q.api('action', {key: 'enabled', value: true});
  const rejectedLate = assert.rejects(lateFailure, /Verbindung.*unterbrochen/);
  await tick();
  q.handleMessage({id: sent[2].id, result: {fields: {}}});
  acknowledgements[2].reject(Error('Die Verbindung wurde während der Anfrage unterbrochen'));
  await rejectedLate;
  assert.equal(sent.filter(r => r.op === 'action').length, 1, 'Do not repeat actions after a late native failure');
  const recovery = q.api('state');
  await tick();
  assert.ok(sent[3].clientTrace.some(line => line.includes('bridge-error')), 'Next successful request can report the browser-side failure');
  q.handleMessage({id: sent[3].id, result: {fields: {}}});
  acknowledgements[3].resolve();
  await recovery;
  assert.equal(q.pendingRequests.size, 0);
  const missingAck = q.api('state');
  const rejectedAck = assert.rejects(missingAck, /Anfrageabschluss nicht bestätigt/);
  await tick();
  q.handleMessage({id: sent[4].id, result: {fields: {}}});
  [...queued.timers.values()][0].fn();
  await rejectedAck;
  acknowledgements[4].resolve();
  await tick();
  assert.equal(q.pendingRequests.size, 0);
  q.transportTrace.push(...Array.from({length: 20}, () => '界'.repeat(240)));
  const boundedTrace = q.api('state');
  await tick();
  assert.ok(new TextEncoder().encode(JSON.stringify(sent[5])).byteLength <= 7000, 'Browser diagnostics stay within the native request size limit');
  q.handleMessage({id: sent[5].id, result: {fields: {}}});
  acknowledgements[5].resolve();
  await boundedTrace;
  console.log('PASS: archive isolation, cooldown, connection recovery, hidden tiles, slow replies, native queue, both acknowledgement orders, late native rejection, browser diagnostics, action timeout without retry');
})().catch(e => {console.error(e); process.exitCode = 1;});
