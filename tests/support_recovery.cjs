const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const mysql = 'C:/xampp/mysql/bin/mysql.exe';
const database = 'nexapos_recovery_test_1019';
function sql(query) {
  return execFileSync(mysql, ['--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names', database, '--execute='+query], {encoding:'utf8'}).trim();
}
async function call(action, body, headers={}) {
  const res = await fetch('http://127.0.0.1:18091/index.php?action='+action, {
    method:'POST', headers:{'Content-Type':'application/json', ...headers}, body:JSON.stringify(body)});
  return {status:res.status, ...await res.json()};
}
async function main() {
  assert.equal(sql('SELECT DATABASE()'), database);
  sql("INSERT INTO license_keys (code, device_id, expires_at, activated_at, valid_days, valid_until, activation_token_hash) VALUES ('TEST-RENEW', 'test-device', DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 DAY), DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 DAY), 1, DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY), SHA2('previous-token',256)) ON DUPLICATE KEY UPDATE revoked=0, device_id='test-device', valid_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY)");
  const renewed = await call('activate',{code:'TEST-RENEW',device_id:'test-device'});
  assert.equal(renewed.success,true);
  assert.equal((await call('activate',{code:'TEST-RENEW',device_id:'other-device'})).success,false);
  const bearer = {Authorization:'Bearer '+renewed.activation_token};
  const admin = {'X-Admin-Secret':'local-recovery-test-only'};
  assert.equal((await call('reset_authenticator',{device_id:'test-device'})).status,401);
  const initial = await call('verify',{},bearer);
  assert.equal((await call('reset_authenticator',{device_id:'test-device'},admin)).success,true);
  const reset = await call('verify',{},bearer);
  assert.equal(reset.authenticator_generation,initial.authenticator_generation+1);
  const issue = await call('issue_support_access',{device_id:'test-device'},admin);
  assert.equal(issue.username,'nexapos-support');
  assert.equal((await call('redeem_support_access',{device_id:'other-device',password:issue.password},bearer)).success,false);
  assert.equal((await call('redeem_support_access',{device_id:'test-device',password:issue.password},bearer)).success,true);
  assert.equal((await call('redeem_support_access',{device_id:'test-device',password:issue.password},bearer)).success,false);
  const expired = await call('issue_support_access',{device_id:'test-device'},admin);
  sql("UPDATE device_security_recovery SET access_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE device_id='test-device'");
  assert.equal((await call('redeem_support_access',{device_id:'test-device',password:expired.password},bearer)).success,false);
  sql("UPDATE license_keys SET revoked=1 WHERE code='TEST-RENEW'");
  assert.equal((await call('activate',{code:'TEST-RENEW',device_id:'test-device'})).success,false);
  console.log('Passed: same-device renewal, cross-device rejection, authenticated reset, one-time/expired support access, revoked license.');
}
main().catch(error=>{console.error(error.message);process.exitCode=1;});
