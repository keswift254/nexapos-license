// Security checks of the admin pages (public/admin) and the license host's .htaccess. Run: node --test tests/
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {execFileSync} = require('node:child_process');

const pages = ['dashboard', 'generator'];
const read = (path) => fs.readFileSync(path, 'utf8');

/** The source of one top-level function of a script, found by its name and matching braces. */
function functionSource(script, name) {
  const start = script.search(new RegExp(`(^|\\n)(async )?function ${name}\\(`));
  assert.ok(start >= 0, `function ${name} not found`);
  let depth = 0;
  for (let i = script.indexOf('{', start); i < script.length; i++) {
    if (script[i] === '{') depth++;
    if (script[i] === '}' && --depth === 0) return script.slice(start, i + 1);
  }
  throw new Error(`function ${name} has no end`);
}

/** What a browser makes of an attribute value: the entities escapeHtml writes, turned back into characters. */
function decodeAttribute(value) {
  return value.replace(/&(amp|lt|gt|quot|#39);/g, (m, e) => ({amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'"}[e]));
}

test('no admin page runs inline script: no <script> without src, no on*="..." attribute', () => {
  for (const page of pages) {
    const html = read(`public/admin/${page}.html`);
    for (const [, attrs, body] of html.matchAll(/<script(\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
      assert.match(attrs || '', /\ssrc="/, `${page}.html has an inline <script>`);
      assert.equal(body.trim(), '', `${page}.html has script text inside a <script src>`);
    }
    assert.doesNotMatch(html, /\son[a-z]+\s*=/i, `${page}.html still has an inline event handler`);
  }
});

test('no admin script builds HTML with an inline handler, and every wired control exists', () => {
  for (const page of pages) {
    const script = read(`public/admin/${page}.js`);
    const code = script.split('\n').filter((line) => !line.trim().startsWith('//')).join('\n');
    assert.doesNotMatch(code, /\son(click|input|change|keydown|error|load)=/, `${page}.js writes an inline handler`);
    new vm.Script(script);
    const html = read(`public/admin/${page}.html`);
    for (const [, id] of script.matchAll(/\bon\('([A-Za-z0-9_-]+)', '/g)) {
      assert.match(html, new RegExp(`\\sid="${id}"`), `${page}.js wires #${id}, which the page does not have`);
    }
  }
});

test('every control that had an inline handler is still wired to the same action', () => {
  const expected = {
    dashboard: [
      'loadBtn click loadDashboard()', 'search input renderRows()', 'refreshBtn click loadDashboard()',
      'ticketDialogClose click closeTicketDialog()', 'ticketResolveBtn click resolveTicket()', 'ticketReplyBtn click sendTicketReply()',
    ],
    generator: [
      'expiryPreset change onExpiryPresetChange()', 'durationPreset change onDurationPresetChange()', 'generateBtn click generateKey()',
      'revokeBtn click revokeKey()', 'unrevokeBtn click unrevokeLicense()', 'extendBtn click extendLicense()',
      'setExpiryBtn click setLicenseExpiry()', "findQuery keydown if (event.key === 'Enter') findCustomer()", 'findBtn click findCustomer()',
      'moveBtn click moveLicense()', 'purchasesBtn click loadPurchases()', 'plansBtn click loadPlans()',
      ...['windowsInstaller:publishWindowsInstallerUrl', 'android:publishAndroidUrl', 'windowsLegacy:publishWindowsLegacyUrl',
        'androidPatch:publishAndroidPatchUrl', 'windowsInstallerPatch:publishWindowsInstallerPatchUrl',
        'windowsLegacyPatch:publishWindowsLegacyPatchUrl', 'patchApplier:publishPatchApplierUrl']
        .flatMap((pair) => { const [kind, id] = pair.split(':'); return [`${id} change hashPublishFile('${kind}')`, `${id} input updatePublishButtonState()`]; }),
      ...['publishWindowsInstallerSha256', 'publishAndroidSha256', 'publishWindowsLegacySha256', 'publishPatchFromVersion',
        'publishAndroidPatchSha256', 'publishWindowsInstallerPatchSha256', 'publishWindowsLegacyPatchSha256', 'publishPatchApplierSha256']
        .map((id) => `${id} input updatePublishButtonState()`),
      'publishIncludeLegacy change toggleLegacyPublish()', 'publishIncludePatches change togglePatchPublish()',
      'hashFilesBtn click hashBothPublishFiles()', 'publishBtn click publishVersion()',
      "resetAuthenticatorBtn click supportRecovery('reset_authenticator')", "supportAccessBtn click supportRecovery('issue_support_access')",
    ],
  };
  for (const page of pages) {
    const wired = [...read(`public/admin/${page}.js`).matchAll(/\bon\('([^']+)', '([a-z]+)', function \(event\) \{ (.*); \}\);/g)]
      .map(([, id, type, code]) => `${id} ${type} ${code}`);
    assert.deepEqual([...wired].sort(), [...expected[page]].sort(), `${page}.js wiring changed`);
  }
});

test('a device whose ID, name or shop name is script cannot run it in the dashboard', () => {
  const script = read('public/admin/dashboard.js');
  const context = vm.createContext({
    COPY_ICON_SVG: '<svg></svg>',
    isNeverRegistered: (d) => !d || d.unregistered === true,
    isDisabledDevice: (d) => !!d && d.status === 'disabled',
  });
  for (const name of ['escapeHtml', 'codeCells', 'deviceIdCell', 'deleteButton']) {
    vm.runInContext(functionSource(script, name), context);
  }
  const payloads = [
    "x');document.title='pwned';//",
    '"><img src=x onerror=alert(1)>',
    "&#39;);alert(1)//",
    "Till 2 \\'); alert(1); //",
  ];
  for (const payload of payloads) {
    const idCell = vm.runInContext(`deviceIdCell(${JSON.stringify(payload)})`, context);
    const del = vm.runInContext(`deleteButton({id: 7, device_label: ${JSON.stringify(payload)}, status: 'active'})`, context);
    const {codeCell} = vm.runInContext(`codeCells({code: ${JSON.stringify(payload)}, status: 'used'})`, context);
    for (const html of [idCell, del, codeCell]) {
      // Attribute NAMES of the tags written (values blanked out - escaped text inside them is harmless).
      const tags = (html.match(/<[^>]+>/g) || []).map((tag) => tag.replace(/"[^"]*"/g, '""'));
      for (const tag of tags) assert.doesNotMatch(tag, /\son[a-z]+=/i, `an inline handler was written: ${tag}`);
      assert.deepEqual([...new Set(tags.map((t) => t.match(/^<\/?([a-z]+)/i)[1].toLowerCase()))].sort(),
        [...new Set(['span', 'button', 'svg'].filter((t) => html.includes('<' + t)))].sort(), 'the payload became markup');
    }
    // Each value comes back from its data- attribute exactly as it went in (never run as code).
    assert.equal(decodeAttribute(idCell.match(/data-copy-device-id="([^"]*)"/)[1]), payload);
    assert.equal(decodeAttribute(del.match(/data-label="([^"]*)"/)[1]), payload);
    assert.equal(decodeAttribute(codeCell.match(/data-copy-code="([^"]*)"/)[1]), payload);
  }
  // The one click listener reads them back with .dataset and calls the real functions.
  assert.match(script, /closest\('\[data-copy-device-id\]'\)[\s\S]*copyDeviceId\(copyIdBtn\.dataset\.copyDeviceId, copyIdBtn\)/);
  assert.match(script, /deleteDevice\(Number\(deleteBtn\.dataset\.deleteDevice\), deleteBtn\.dataset\.label\)/);
  assert.match(script, /revokeShop\(Number\(revokeBtn\.dataset\.revokeShop\), revokeBtn\.dataset\.name\)/);
  assert.match(script, /openTicketThread\(Number\(ticketRow\.dataset\.openTicket\)\)/);
});

test('the license host sends a strict CSP and frame ban for the admin pages, and redirects the old addresses', () => {
  const htaccess = read('public/.htaccess');
  const csp = htaccess.match(/Content-Security-Policy "([^"]+)"/)?.[1];
  assert.ok(csp, 'no Content-Security-Policy');
  const scriptSrc = csp.split(';').map((d) => d.trim()).find((d) => d.startsWith('script-src'));
  assert.ok(scriptSrc && !/unsafe-inline|unsafe-eval|\*/.test(scriptSrc), `script-src is not strict: ${scriptSrc}`);
  assert.match(csp, /frame-ancestors 'none'/);
  assert.match(csp, /default-src 'none'/);
  assert.match(htaccess, /FilesMatch "\^\(dashboard\|generator\)\\\.html\$"/);
  assert.match(htaccess, /X-Frame-Options "DENY"/);
  assert.match(htaccess, /Redirect 301 \/dashboard\.html \/admin\/dashboard\.html/);
  assert.match(htaccess, /Redirect 301 \/generator\.html \/admin\/generator\.html/);
  assert.match(htaccess, /<FilesMatch "\^\\\.">\s*Require all denied/);
  assert.ok(!fs.existsSync('public/dashboard.html') && !fs.existsSync('public/generator.html'), 'old page copies left in public/');
});

test('rate limits use the real connection address, never a header the caller writes', () => {
  const index = read('public/index.php');
  const fn = functionSource(index, 'callerIp');
  const php = 'C:/xampp/php/php.exe';
  const out = execFileSync(php, ['-r', `${fn}
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
echo callerIp();`], {encoding: 'utf8'});
  assert.equal(out, '203.0.113.7');
});
