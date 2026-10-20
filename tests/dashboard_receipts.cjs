const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const html = fs.readFileSync('public/dashboard.html', 'utf8');

test('dashboard scripts parse and support replies show server/read state', () => {
  for (const [, script] of html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
    if (script.trim()) new vm.Script(script);
  }
  const definition = html.match(/function supportDeliveryLabel\(message\) \{[^}]+\}/)?.[0];
  assert.ok(definition);
  const label = vm.runInNewContext(`(${definition})`);
  assert.equal(label({read_at: null}), 'Delivered');
  assert.equal(label({read_at: '2026-10-21 12:30:00'}), 'Read');
  assert.match(html, /mine\s*\? `<div class="ticket-delivery"/);
});
