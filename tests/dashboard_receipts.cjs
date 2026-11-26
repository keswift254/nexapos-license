const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// The dashboard's script lives in its own file since the page runs under a Content-Security-Policy.
const script = fs.readFileSync('public/admin/dashboard.js', 'utf8');

test('dashboard scripts parse and support replies show server/read state', () => {
  new vm.Script(script);
  const definition = script.match(/function supportDeliveryLabel\(message\) \{[^}]+\}/)?.[0];
  assert.ok(definition);
  const label = vm.runInNewContext(`(${definition})`);
  assert.equal(label({read_at: null}), 'Delivered');
  assert.equal(label({read_at: '2026-10-21 12:30:00'}), 'Read');
  assert.match(script, /mine\s*\? `<div class="ticket-delivery"/);
});
