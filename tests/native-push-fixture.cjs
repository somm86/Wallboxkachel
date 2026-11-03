// Simulated server-side visualization updates for browser tests.
module.exports = function installNativePushFixture() {
  window.addEventListener('load', () => {
    if (new URLSearchParams(location.search).has('demo')) return;
    const fields = demoAPI('state').fields;
    const history = demoAPI('history');
    for (const series of ['power', 'pv', 'house', 'gridPower']) {
      handleMessage({event: 'update', fields, history: {[series]: history[series]}});
    }
  });
};
