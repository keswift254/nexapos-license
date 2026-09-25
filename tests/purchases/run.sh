#!/bin/bash
# Run:  bash tests/purchases/run.sh      (needs the local XAMPP MySQL + PHP, see MYSQL/PHP below)
# End-to-end test of the license server's "buy a license" flow (Purchases.php) and of
# moving a license to a new device (Recovery.php) against:
#   * a THROWAWAY local MySQL database (never production),
#   * a FAKE Paystack + FAKE email service (fake_paystack.php - nothing here contacts the real ones),
#   * local PHP servers on spare ports.
MYSQL=/c/xampp/mysql/bin/mysql.exe
PHP=/c/xampp/php/php.exe
HERE="$(cd "$(dirname "$0")" && pwd)"
LIC="$(cd "$HERE/../.." && (pwd -W 2>/dev/null || pwd))"   # the repo root, as a path PHP understands
SCR="$(cd "$HERE" && (pwd -W 2>/dev/null || pwd))"
SECRET=scratch-admin-secret-not-real
KEY=sk_test_fake_key
TS=$(date +%s)
DB="nexapos_purch_$TS"
FP_PORT=8981; LI_PORT=8982; OFF_PORT=8983; SLOW_PORT=8984; NOTEST_PORT=8985; OLD_PORT=8986
FP="http://127.0.0.1:$FP_PORT"
LI="http://127.0.0.1:$LI_PORT/index.php"
OFF="http://127.0.0.1:$OFF_PORT/index.php"
SLOW="http://127.0.0.1:$SLOW_PORT/index.php"
PASS=0; FAIL=0
curl() { command curl -m 60 "$@"; }   # nothing in this script may hang forever

check() { # desc got expected-substring
  if [[ "$2" == *"$3"* ]]; then PASS=$((PASS+1)); echo "PASS: $1"; else FAIL=$((FAIL+1)); echo "FAIL: $1"; echo "   want: $3"; echo "   got:  $2"; fi
}
check_not() { # desc got forbidden-substring
  if [[ "$2" != *"$3"* ]]; then PASS=$((PASS+1)); echo "PASS: $1"; else FAIL=$((FAIL+1)); echo "FAIL: $1"; echo "   must NOT contain: $3"; echo "   got:  $2"; fi
}
kill_port() { local pid; pid=$(netstat -ano | grep ":$1 " | grep LISTENING | awk '{print $5}' | head -1); [ -n "$pid" ] && taskkill //F //PID "$pid" >/dev/null 2>&1; }
jget() { "$PHP" -r '$v=json_decode($argv[1],true); foreach(explode(".",$argv[2]) as $k){ if(!is_array($v)||!array_key_exists($k,$v)){echo ""; exit;} $v=$v[$k]; } echo is_bool($v)?($v?"true":"false"):(is_array($v)?json_encode($v):$v);' "$1" "$2"; }
sql() { "$MYSQL" -u root -N -e "$1" 2>/dev/null; }
IPN=0; TEST_IP=10.1.0.1
section() { IPN=$((IPN+1)); TEST_IP="10.1.0.$IPN"; echo "=== $1 ==="; }   # a fresh client address per section, so the per-address limit never leaks between tests
post() { curl -s -X POST "$LI?action=$1" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d "$2"; }
fake() { curl -s -X POST "$FP/_test/$1" -H "Content-Type: application/json" -d "$2" >/dev/null; }
hmac() { "$PHP" -r 'echo hash_hmac("sha512", $argv[1], $argv[2]);' "$1" "$2"; }

cleanup() {
  kill_port $FP_PORT; kill_port $LI_PORT; kill_port $OFF_PORT; kill_port $SLOW_PORT; kill_port $NOTEST_PORT; kill_port $OLD_PORT
  sql "DROP DATABASE IF EXISTS $DB;"
  sql "DROP DATABASE IF EXISTS ${DB}_old;"
  rm -f "$(cygpath -u "$TEMP" 2>/dev/null || echo /tmp)"/fake_paystack_state_*.json 2>/dev/null
}
trap cleanup EXIT
for p in $FP_PORT $LI_PORT $OFF_PORT $SLOW_PORT $NOTEST_PORT $OLD_PORT; do kill_port $p; done
sleep 1

# The fake Paystack
"$PHP" -S 127.0.0.1:$FP_PORT "$SCR/fake_paystack.php" >/tmp/fp.log 2>&1 &
# The license server WITH payments enabled (verify throttle off so tests need not wait)
start_license() { # port extra-env...
  local port=$1; shift
  env DB_NAME="$DB" LICENSE_ADMIN_SECRET="$SECRET" PAYSTACK_BASE_URL="$FP" LICENSE_PUBLIC_BASE_URL="http://127.0.0.1:$LI_PORT/index.php" "$@" \
    "$PHP" -S 127.0.0.1:$port -t "$LIC/public" >/tmp/li_$port.log 2>&1 &
}
start_license $LI_PORT PAYSTACK_SECRET_KEY=$KEY PAYSTACK_SUBACCOUNT=ACCT_fake123 PURCHASE_VERIFY_EVERY_SECONDS=0 BREVO_API_KEY=fake-brevo-key MAIL_FROM=noreply@nexapos.test BREVO_BASE_URL="$FP"
# ... one WITHOUT a Paystack key (payments not configured yet)
start_license $OFF_PORT
# ... and one with a long verify throttle
start_license $SLOW_PORT PAYSTACK_SECRET_KEY=$KEY PURCHASE_VERIFY_EVERY_SECONDS=30
sleep 3

section "plans"
r=$(curl -s "$LI?action=plans")
check "plans: succeeds" "$r" '"success":true'
check "plans: payments enabled" "$(jget "$r" purchasing_enabled)" "true"
check "plans: KES" "$(jget "$r" currency)" "KES"
check "plans: 3 months 1500" "$r" '{"id":"m3","label":"3 months","months":3,"days":0,"amount_kes":1500,"test":false}'
check "plans: 6 months 3000" "$r" '{"id":"m6","label":"6 months","months":6,"days":0,"amount_kes":3000,"test":false}'
check "plans: 1 year 4800" "$r" '{"id":"m12","label":"1 year","months":12,"days":0,"amount_kes":4800,"test":false}'
check_not "plans: never mentions a trial" "$r" "trial"
r=$(curl -s "$OFF?action=plans")
check "plans: with no Paystack key, payments are reported as not available" "$(jget "$r" purchasing_enabled)" "false"
check "plans: ... but the plans are still listed" "$r" '"amount_kes":3000'
r=$(curl -s -X POST "$OFF?action=checkout_start" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d '{"device_id":"dev-off","plan_id":"m3","email":"a@b.co"}')
check "start with no Paystack key -> 503 with a clear message" "$r" "not available yet"

section "start: validation"
r=$(post checkout_start '{"plan_id":"m3","email":"a@b.co"}');                          check "no device -> rejected" "$r" "device_id is required"
r=$(post checkout_start '{"device_id":"d1","plan_id":"nope","email":"a@b.co"}');       check "unknown plan -> rejected" "$r" "Choose one of the listed plans"
r=$(post checkout_start '{"device_id":"d1","plan_id":"m3","email":"not-an-email"}');   check "bad email -> rejected" "$r" "valid email"
r=$(post checkout_start '{"device_id":"d1","plan_id":"m3"}');                          check "no email -> rejected" "$r" "valid email"
r=$(post checkout_start '{"device_id":"bad id with spaces","plan_id":"m3","email":"a@b.co"}'); check "device id with spaces -> rejected" "$r" "device_id is required"

section "start: the real thing (6 months, KSh 3,000)"
DEV="dev-buyer-$TS"
# the app tries to sneak in its own price - it must be ignored
r=$(post checkout_start "{\"device_id\":\"$DEV\",\"plan_id\":\"m6\",\"email\":\"Buyer@Example.com\",\"amount_kes\":1,\"months\":99,\"amount\":100}")
check "start: success" "$r" '"success":true'
REF=$(jget "$r" reference); URL=$(jget "$r" authorization_url)
check "start: a reference is returned" "$REF" "nxl-"
check "start: the checkout URL is returned" "$URL" "http://127.0.0.1:$FP_PORT/pay/$REF"
check "start: plan echoed back with the SERVER's price" "$(jget "$r" plan.amount_kes)" "3000"
check_not "start: the response never carries a secret key" "$r" "$KEY"
p=$(curl -s "$FP/_test/payload?reference=$REF")
check "Paystack was asked for 3000 KSh = 300000 cents (not the app's 1)" "$(jget "$p" amount)" "300000"
check "Paystack was asked in KES" "$(jget "$p" currency)" "KES"
check "Paystack was told the buyer's email (lower-cased)" "$(jget "$p" email)" "buyer@example.com"
check "Paystack was given the settlement subaccount" "$(jget "$p" subaccount)" "ACCT_fake123"
check "Paystack was told where to send the browser afterwards" "$(jget "$p" callback_url)" "?action=payment_done"
check "Paystack metadata names the device" "$(jget "$p" metadata.device_id)" "$DEV"
check "DB row: pending, 300000 cents, 6 months" "$(sql "SELECT CONCAT(status,'|',amount_minor,'|',months,'|',currency) FROM $DB.license_purchases WHERE reference='$REF';")" "pending|300000|6|KES"
r2=$(post checkout_start "{\"device_id\":\"$DEV\",\"plan_id\":\"m6\",\"email\":\"buyer@example.com\"}")
check "a second tap on the same plan reuses the open checkout (no second charge attempt)" "$(jget "$r2" reference)" "$REF"
check "... and stored just one row" "$(sql "SELECT COUNT(*) FROM $DB.license_purchases WHERE device_id='$DEV';")" "1"

section "status before paying / wrong owner"
r=$(post checkout_status "{\"reference\":\"$REF\",\"device_id\":\"$DEV\"}")
check "status: pending while unpaid (Paystack says abandoned)" "$(jget "$r" status)" "pending"
check_not "status: no license code while unpaid" "$r" '"code"'
r=$(post checkout_status "{\"reference\":\"$REF\",\"device_id\":\"someone-else\"}")
check "status: another device cannot see this payment" "$r" "Unknown payment"
r=$(post checkout_status '{"reference":"nxl-doesnotexist","device_id":"x"}')
check "status: unknown reference -> the same answer" "$r" "Unknown payment"
r=$(post checkout_status '{"device_id":"x"}');  check "status: reference required" "$r" "required"

section "paying: license issued, bound, timed"
fake set "{\"reference\":\"$REF\",\"status\":\"success\"}"
r=$(post checkout_status "{\"reference\":\"$REF\",\"device_id\":\"$DEV\"}")
check "status: issued after Paystack confirms" "$(jget "$r" status)" "issued"
CODE=$(jget "$r" code)
check "status: a 10-character license code" "${#CODE}" "10"
check "DB: purchase is issued with that code" "$(sql "SELECT CONCAT(status,'|',license_code) FROM $DB.license_purchases WHERE reference='$REF';")" "issued|$CODE"
check "DB: license bound to the paying device, not revoked" "$(sql "SELECT CONCAT(device_id,'|',revoked) FROM $DB.license_keys WHERE code='$CODE';")" "$DEV|0"
days=$(sql "SELECT TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), valid_until) FROM $DB.license_keys WHERE code='$CODE';")
if [ "$days" -ge 180 ] && [ "$days" -le 184 ]; then check "DB: valid for ~6 months ($days days)" ok ok; else check "DB: valid for ~6 months" "$days days" "180-184 days"; fi
check "DB: valid_until is a real calendar 6 months" "$(sql "SELECT valid_until = DATE_ADD(activated_at, INTERVAL 6 MONTH) FROM $DB.license_keys WHERE code='$CODE';")" "1"
r=$(post checkout_status "{\"reference\":\"$REF\",\"device_id\":\"$DEV\"}")
check "status again: the SAME code (idempotent)" "$(jget "$r" code)" "$CODE"
check "DB: still exactly one license for the device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$DEV';")" "1"

section "the paying device activates with it (existing activate path)"
act=$(post activate "{\"code\":\"$CODE\",\"device_id\":\"$DEV\"}")
check "activate: success" "$act" '"success":true'
TOKEN=$(jget "$act" activation_token)
check "activate: a token is issued" "${#TOKEN}" "64"
check "activate: valid_until matches the license" "$(jget "$act" valid_until)" "$(sql "SELECT valid_until FROM $DB.license_keys WHERE code='$CODE';")"
v=$(curl -s -X POST "$LI?action=verify" -H "Authorization: Bearer $TOKEN")
check "verify: the token is valid" "$(jget "$v" valid)" "true"
act2=$(post activate "{\"code\":\"$CODE\",\"device_id\":\"another-device\"}")
check "activate: the code does not work on another device" "$act2" "belongs to another device"

section "wrong amount / wrong currency / failed"
r=$(post checkout_start "{\"device_id\":\"dev-mm-$TS\",\"plan_id\":\"m3\",\"email\":\"m@m.co\"}"); REF2=$(jget "$r" reference)
fake set "{\"reference\":\"$REF2\",\"status\":\"success\",\"amount\":100}"
r=$(post checkout_status "{\"reference\":\"$REF2\",\"device_id\":\"dev-mm-$TS\"}")
check "amount mismatch: refused" "$(jget "$r" status)" "failed"
check "amount mismatch: tells the customer to contact us with the reference" "$r" "$REF2"
check "amount mismatch: nothing issued" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='dev-mm-$TS';")" "0"
check "amount mismatch: recorded" "$(sql "SELECT CONCAT(status,'|',paystack_status) FROM $DB.license_purchases WHERE reference='$REF2';")" "failed|amount_mismatch"
r=$(post checkout_start "{\"device_id\":\"dev-cur-$TS\",\"plan_id\":\"m3\",\"email\":\"c@c.co\"}"); REF3=$(jget "$r" reference)
fake set "{\"reference\":\"$REF3\",\"status\":\"success\",\"currency\":\"USD\"}"
r=$(post checkout_status "{\"reference\":\"$REF3\",\"device_id\":\"dev-cur-$TS\"}")
check "currency mismatch: refused" "$(jget "$r" status)" "failed"
check "currency mismatch: nothing issued" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='dev-cur-$TS';")" "0"
r=$(post checkout_start "{\"device_id\":\"dev-fail-$TS\",\"plan_id\":\"m12\",\"email\":\"f@f.co\"}"); REF4=$(jget "$r" reference)
fake set "{\"reference\":\"$REF4\",\"status\":\"failed\"}"
r=$(post checkout_status "{\"reference\":\"$REF4\",\"device_id\":\"dev-fail-$TS\"}")
check "failed payment: reported as failed" "$(jget "$r" status)" "failed"
check "failed payment: the customer is told they were not charged for a license" "$r" "did not go through"
check "failed payment: nothing issued" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='dev-fail-$TS';")" "0"
fake set "{\"reference\":\"$REF4\",\"status\":\"success\"}"
r=$(post checkout_status "{\"reference\":\"$REF4\",\"device_id\":\"dev-fail-$TS\"}")
check "a failed purchase stays failed even if Paystack later says success (start a new one)" "$(jget "$r" status)" "failed"

section "Paystack having a bad moment is not a failed payment"
r=$(post checkout_start "{\"device_id\":\"dev-glitch-$TS\",\"plan_id\":\"m3\",\"email\":\"g@g.co\"}"); REF5=$(jget "$r" reference)
fake set "{\"reference\":\"$REF5\",\"status\":\"success\"}"
fake mode '{"verify_500":true}'
r=$(post checkout_status "{\"reference\":\"$REF5\",\"device_id\":\"dev-glitch-$TS\"}")
check "verify outage -> still pending, not failed" "$(jget "$r" status)" "pending"
check "verify outage -> row untouched" "$(sql "SELECT status FROM $DB.license_purchases WHERE reference='$REF5';")" "pending"
fake mode '{"verify_500":false}'
r=$(post checkout_status "{\"reference\":\"$REF5\",\"device_id\":\"dev-glitch-$TS\"}")
check "after the outage the payment is honoured" "$(jget "$r" status)" "issued"

section "Paystack refusing to start a payment"
fake mode '{"fail_initialize":true}'
r=$(post checkout_start "{\"device_id\":\"dev-init-$TS\",\"plan_id\":\"m3\",\"email\":\"i@i.co\"}")
check "initialize refused -> a clear 502-style message" "$r" "could not be started"
check_not "initialize refused -> Paystack's internal message is not leaked" "$r" "Currency not supported"
check "initialize refused -> the row is failed, not left pending" "$(sql "SELECT status FROM $DB.license_purchases WHERE device_id='dev-init-$TS';")" "failed"
r=$(post checkout_start "{\"device_id\":\"dev-init-$TS\",\"plan_id\":\"m3\",\"email\":\"i@i.co\"}")
check "...and the next try works" "$(jget "$r" success)" "true"

section "webhook"
r=$(post checkout_start "{\"device_id\":\"dev-hook-$TS\",\"plan_id\":\"m3\",\"email\":\"h@h.co\"}"); REF6=$(jget "$r" reference)
BODY="{\"event\":\"charge.success\",\"data\":{\"reference\":\"$REF6\",\"status\":\"success\",\"amount\":150000,\"currency\":\"KES\"}}"
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LI?action=paystack_webhook" -H "Content-Type: application/json" -H "x-paystack-signature: deadbeef" --data-binary "$BODY")
check "webhook: a bad signature is refused (401)" "$code" "401"
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LI?action=paystack_webhook" -H "Content-Type: application/json" --data-binary "$BODY")
check "webhook: no signature is refused (401)" "$code" "401"
check "webhook: an unsigned call changed nothing" "$(sql "SELECT status FROM $DB.license_purchases WHERE reference='$REF6';")" "pending"
SIG=$(hmac "$BODY" "$KEY")
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LI?action=paystack_webhook" -H "Content-Type: application/json" -H "x-paystack-signature: $SIG" --data-binary "$BODY")
check "webhook: a genuinely signed charge.success is accepted (200)" "$code" "200"
check "webhook: marks the purchase paid" "$(sql "SELECT status FROM $DB.license_purchases WHERE reference='$REF6';")" "paid"
check "webhook: does NOT issue a license (the period starts when the customer gets it)" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='dev-hook-$TS';")" "0"
r=$(post checkout_status "{\"reference\":\"$REF6\",\"device_id\":\"dev-hook-$TS\"}")
check "webhook: the device then collects its license" "$(jget "$r" status)" "issued"
BODY2="{\"event\":\"charge.success\",\"data\":{\"reference\":\"nxl-unknown\",\"status\":\"success\",\"amount\":1,\"currency\":\"KES\"}}"
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LI?action=paystack_webhook" -H "x-paystack-signature: $(hmac "$BODY2" "$KEY")" --data-binary "$BODY2")
check "webhook: an unknown reference is acknowledged (200) and ignored" "$code" "200"
BODY3="{\"event\":\"transfer.success\",\"data\":{}}"
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$LI?action=paystack_webhook" -H "x-paystack-signature: $(hmac "$BODY3" "$KEY")" --data-binary "$BODY3")
check "webhook: other event types are acknowledged (200)" "$code" "200"
r=$(post checkout_start "{\"device_id\":\"dev-hook2-$TS\",\"plan_id\":\"m3\",\"email\":\"h2@h.co\"}"); REF7=$(jget "$r" reference)
BODY4="{\"event\":\"charge.success\",\"data\":{\"reference\":\"$REF7\",\"status\":\"success\",\"amount\":100,\"currency\":\"KES\"}}"
curl -s -o /dev/null -X POST "$LI?action=paystack_webhook" -H "x-paystack-signature: $(hmac "$BODY4" "$KEY")" --data-binary "$BODY4"
check "webhook: a signed call with the wrong amount does not mark it paid" "$(sql "SELECT status FROM $DB.license_purchases WHERE reference='$REF7';")" "failed"

section "renewing early loses nothing (stacks on the running license)"
r=$(post checkout_start "{\"device_id\":\"$DEV\",\"plan_id\":\"m3\",\"email\":\"buyer@example.com\"}"); REF8=$(jget "$r" reference)
fake set "{\"reference\":\"$REF8\",\"status\":\"success\"}"
r=$(post checkout_status "{\"reference\":\"$REF8\",\"device_id\":\"$DEV\"}")
CODE2=$(jget "$r" code)
check "renewal: a second license is issued" "${#CODE2}" "10"
check "renewal: it starts when the first one ends (first valid_until + 3 months)" "$(sql "SELECT b.valid_until = DATE_ADD(a.valid_until, INTERVAL 3 MONTH) FROM $DB.license_keys a, $DB.license_keys b WHERE a.code='$CODE' AND b.code='$CODE2';")" "1"
act=$(post activate "{\"code\":\"$CODE2\",\"device_id\":\"$DEV\"}")
check "renewal: activates on the same device" "$act" '"success":true'

section "two polls racing after a payment create ONE license"
DEVR="dev-race-$TS"
r=$(post checkout_start "{\"device_id\":\"$DEVR\",\"plan_id\":\"m3\",\"email\":\"r@r.co\"}"); REFR=$(jget "$r" reference)
fake set "{\"reference\":\"$REFR\",\"status\":\"success\"}"
pids=()
for i in 1 2 3 4 5 6; do post checkout_status "{\"reference\":\"$REFR\",\"device_id\":\"$DEVR\"}" > "/tmp/race_$i.json" & pids+=($!); done
for pid in "${pids[@]}"; do wait "$pid"; done   # NOT a bare wait: that would also wait for the servers
check "race: exactly one license row for the device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$DEVR';")" "1"
codes=$(for i in 1 2 3 4 5 6; do jget "$(cat /tmp/race_$i.json)" code; done | sort -u | wc -l | tr -d ' ')
check "race: every poll got the same code" "$codes" "1"
rm -f /tmp/race_*.json

section "rate limits"
DEVL="dev-limit-$TS"
for i in 1 2 3 4 5 6; do post checkout_start "{\"device_id\":\"$DEVL\",\"plan_id\":\"m3\",\"email\":\"limit$i@l.co\"}" >/dev/null; done
r=$(post checkout_start "{\"device_id\":\"$DEVL\",\"plan_id\":\"m3\",\"email\":\"limit7@l.co\"}")
check "the 7th checkout in an hour from one device is refused" "$r" "Too many payment attempts"

section "the per-address limit"
for i in $(seq 1 12); do post checkout_start "{\"device_id\":\"dev-ip-$i-$TS\",\"plan_id\":\"m3\",\"email\":\"ip$i@i.co\"}" >/dev/null; done
r=$(post checkout_start "{\"device_id\":\"dev-ip-13-$TS\",\"plan_id\":\"m3\",\"email\":\"ip13@i.co\"}")
check "the 13th checkout in an hour from one address (different devices) is refused" "$r" "from this connection"
TEST_IP=10.1.0.250
r=$(post checkout_start "{\"device_id\":\"dev-ip-14-$TS\",\"plan_id\":\"m3\",\"email\":\"ip14@i.co\"}")
check "...while another address is unaffected" "$(jget "$r" success)" "true"

section "Paystack is not hammered by polling"
r=$(curl -s -X POST "$SLOW?action=checkout_start" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d '{"device_id":"dev-slow","plan_id":"m3","email":"s@s.co"}'); REFS=$(jget "$r" reference)
before=$(curl -s "$FP/_test/verify_calls" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["verify_calls"];')
for i in 1 2 3 4 5; do curl -s -X POST "$SLOW?action=checkout_status" -H "Content-Type: application/json" -d "{\"reference\":\"$REFS\",\"device_id\":\"dev-slow\"}" >/dev/null; done
after=$(curl -s "$FP/_test/verify_calls" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["verify_calls"];')
check "five quick polls asked Paystack once" "$((after-before))" "1"

section "vendor view + the page Paystack sends the browser to"
r=$(curl -s "$LI?action=list_purchases")
check "list_purchases: needs the admin secret" "$r" "Invalid or missing admin secret"
r=$(curl -s "$LI?action=list_purchases" -H "X-Admin-Secret: $SECRET")
check "list_purchases: shows purchases with amounts in shillings" "$r" '"amount_kes":3000'
check_not "list_purchases: no raw cents field" "$r" "amount_minor"
page=$(curl -s "$LI?action=payment_done&reference=x")
check "payment_done: a human page" "$page" "Back to NexaPOS"
check_not "payment_done: does not claim the payment succeeded" "$page" "Payment successful"

# ======================================================================================
# The temporary KSh 5 test plan: a one-day plan (`days`, not `months`) marked `test`,
# for trying the whole payment flow with real money. Hidden by TEST_PLAN_ENABLED=0.
# ======================================================================================
section "the KSh 5 test plan"
r=$(curl -s "$LI?action=plans")
check "test plan: listed, marked as a test, one day, KSh 5" "$r" '{"id":"test","label":"Test plan","months":0,"days":1,"amount_kes":5,"test":true}'
check "test plan: the real plans are not marked as tests" "$r" '"amount_kes":1500,"test":false}'
DEVT="dev-testplan-$TS"
r=$(post checkout_start "{\"device_id\":\"$DEVT\",\"plan_id\":\"test\",\"email\":\"tester@example.com\",\"amount_kes\":500,\"days\":400}")
check "test plan: start succeeds (an app-sent amount/days is ignored, as for every plan)" "$r" '"success":true'
REFT=$(jget "$r" reference)
check "test plan: the plan echoed back is the test plan, one day" "$(jget "$r" plan.days)" "1"
check "test plan: ...and marked as a test" "$(jget "$r" plan.test)" "true"
p=$(curl -s "$FP/_test/payload?reference=$REFT")
check "test plan: Paystack was asked for KSh 5 = 500 cents" "$(jget "$p" amount)" "500"
check "test plan: ...in KES" "$(jget "$p" currency)" "KES"
check "DB row: test, 0 months, 1 day, 500 cents" "$(sql "SELECT CONCAT(plan_id,'|',months,'|',days,'|',amount_minor) FROM $DB.license_purchases WHERE reference='$REFT';")" "test|0|1|500"
fake set "{\"reference\":\"$REFT\",\"status\":\"success\"}"
r=$(post checkout_status "{\"reference\":\"$REFT\",\"device_id\":\"$DEVT\"}")
check "test plan: issued once Paystack confirms" "$(jget "$r" status)" "issued"
CODET=$(jget "$r" code)
hours=$(sql "SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), valid_until) FROM $DB.license_keys WHERE code='$CODET';")
if [ "$hours" -ge 22 ] && [ "$hours" -le 24 ]; then check "test plan: the license lasts about one day ($hours h)" ok ok; else check "test plan: about one day" "$hours h" "22-24 h"; fi
check "test plan: valid_days is 1" "$(sql "SELECT valid_days FROM $DB.license_keys WHERE code='$CODET';")" "1"
check "test plan: bound to the paying device" "$(sql "SELECT device_id FROM $DB.license_keys WHERE code='$CODET';")" "$DEVT"
check "test plan: it activates like any license" "$(post activate "{\"code\":\"$CODET\",\"device_id\":\"$DEVT\"}")" '"success":true'
r=$(curl -s "$LI?action=list_purchases" -H "X-Admin-Secret: $SECRET")
check "test plan: the vendor's purchases list shows the days" "$r" '"plan_id":"test","months":0,"days":1'

section "the test plan stacks one day on a running license (and not more than that)"
DEVK="dev-stack-$TS"
r=$(post checkout_start "{\"device_id\":\"$DEVK\",\"plan_id\":\"m3\",\"email\":\"stack@example.com\"}"); REFK1=$(jget "$r" reference)
fake set "{\"reference\":\"$REFK1\",\"status\":\"success\"}"
CODEK1=$(jget "$(post checkout_status "{\"reference\":\"$REFK1\",\"device_id\":\"$DEVK\"}")" code)
r=$(post checkout_start "{\"device_id\":\"$DEVK\",\"plan_id\":\"test\",\"email\":\"stack@example.com\"}"); REFK2=$(jget "$r" reference)
fake set "{\"reference\":\"$REFK2\",\"status\":\"success\"}"
CODEK2=$(jget "$(post checkout_status "{\"reference\":\"$REFK2\",\"device_id\":\"$DEVK\"}")" code)
check "stacking: the test day starts when the paid 3 months end" "$(sql "SELECT b.valid_until = DATE_ADD(a.valid_until, INTERVAL 1 DAY) FROM $DB.license_keys a, $DB.license_keys b WHERE a.code='$CODEK1' AND b.code='$CODEK2';")" "1"

section "the test plan can be paid for only a few times per device"
DEVC="dev-testcap-$TS"
for i in 1 2 3; do
  r=$(post checkout_start "{\"device_id\":\"$DEVC\",\"plan_id\":\"test\",\"email\":\"cap$i@example.com\"}"); R=$(jget "$r" reference)
  fake set "{\"reference\":\"$R\",\"status\":\"success\"}"
  post checkout_status "{\"reference\":\"$R\",\"device_id\":\"$DEVC\"}" >/dev/null
done
check "cap: three paid test purchases are in" "$(sql "SELECT COUNT(*) FROM $DB.license_purchases WHERE device_id='$DEVC' AND plan_id='test' AND status='issued';")" "3"
r=$(post checkout_start "{\"device_id\":\"$DEVC\",\"plan_id\":\"test\",\"email\":\"cap4@example.com\"}")
check "cap: a 4th test purchase on the same device is refused, in words" "$r" "already been used on this device"
check "cap: ...and nothing was created" "$(sql "SELECT COUNT(*) FROM $DB.license_purchases WHERE device_id='$DEVC' AND plan_id='test';")" "3"
r=$(post checkout_start "{\"device_id\":\"$DEVC\",\"plan_id\":\"m3\",\"email\":\"cap4@example.com\"}")
check "cap: the real plans are not affected by it" "$(jget "$r" success)" "true"
DEVU="dev-testcap-unpaid-$TS"
for i in 1 2 3 4; do
  post checkout_start "{\"device_id\":\"$DEVU\",\"plan_id\":\"test\",\"email\":\"unpaid$i@example.com\"}" >/dev/null
done
check "cap: unpaid/abandoned attempts do not count against it" "$(sql "SELECT COUNT(*) FROM $DB.license_purchases WHERE device_id='$DEVU' AND plan_id='test';")" "4"

section "switching the test plan off (TEST_PLAN_ENABLED=0)"
start_license $NOTEST_PORT PAYSTACK_SECRET_KEY=$KEY PURCHASE_VERIFY_EVERY_SECONDS=0 TEST_PLAN_ENABLED=0
sleep 2
NOTEST="http://127.0.0.1:$NOTEST_PORT/index.php"
r=$(curl -s "$NOTEST?action=plans")
check_not "off: the test plan is no longer listed" "$r" '"id":"test"'
check "off: the real plans still are" "$r" '"amount_kes":3000'
r=$(curl -s -X POST "$NOTEST?action=checkout_start" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d "{\"device_id\":\"dev-off-$TS\",\"plan_id\":\"test\",\"email\":\"o@o.co\"}")
check "off: it cannot be started any more" "$r" "Choose one of the listed plans"
# someone who paid for it just before it was switched off still gets their day
DEVP="dev-paid-before-off-$TS"
r=$(post checkout_start "{\"device_id\":\"$DEVP\",\"plan_id\":\"test\",\"email\":\"before@example.com\"}"); REFP=$(jget "$r" reference)
fake set "{\"reference\":\"$REFP\",\"status\":\"success\"}"
r=$(curl -s -X POST "$NOTEST?action=checkout_status" -H "Content-Type: application/json" -d "{\"reference\":\"$REFP\",\"device_id\":\"$DEVP\"}")
check "off: a test payment made earlier is still honoured" "$(jget "$r" status)" "issued"

section "an already-deployed purchases table (no days column) is upgraded, and its old rows still work"
OLDDB="${DB}_old"
env DB_NAME="$OLDDB" LICENSE_ADMIN_SECRET="$SECRET" PAYSTACK_BASE_URL="$FP" PAYSTACK_SECRET_KEY=$KEY PURCHASE_VERIFY_EVERY_SECONDS=0 \
  "$PHP" -S 127.0.0.1:$OLD_PORT -t "$LIC/public" >/tmp/li_$OLD_PORT.log 2>&1 &
sleep 2
OLD="http://127.0.0.1:$OLD_PORT/index.php"
curl -s "$OLD?action=health" >/dev/null   # bootstraps the database and its tables
sql "DROP TABLE $OLDDB.license_purchases;
CREATE TABLE $OLDDB.license_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(64) NOT NULL UNIQUE, device_id VARCHAR(64) NOT NULL,
    plan_id VARCHAR(20) NOT NULL, months INT NOT NULL, amount_minor INT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'KES',
    email VARCHAR(190) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', paystack_status VARCHAR(40) NULL,
    authorization_url VARCHAR(500) NULL, license_code VARCHAR(20) NULL, ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_checked_at TIMESTAMP NULL, paid_at TIMESTAMP NULL, issued_at TIMESTAMP NULL);
INSERT INTO $OLDDB.license_purchases (reference, device_id, plan_id, months, amount_minor, email, status, paid_at)
VALUES ('nxl-old-row', 'dev-old-$TS', 'm3', 3, 150000, 'old@example.com', 'paid', UTC_TIMESTAMP());"
check "migration: before, the table has no days column" "$(sql "SHOW COLUMNS FROM $OLDDB.license_purchases LIKE 'days';" | wc -l | tr -d ' ')" "0"
r=$(curl -s -X POST "$OLD?action=checkout_status" -H "Content-Type: application/json" -d "{\"reference\":\"nxl-old-row\",\"device_id\":\"dev-old-$TS\"}")
check "migration: a purchase made before the column existed is still issued" "$(jget "$r" status)" "issued"
check "migration: the table now has the days column" "$(sql "SHOW COLUMNS FROM $OLDDB.license_purchases LIKE 'days';" | wc -l | tr -d ' ')" "1"
d=$(sql "SELECT TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), valid_until) FROM $OLDDB.license_keys WHERE device_id='dev-old-$TS';")
if [ "$d" -ge 89 ] && [ "$d" -le 92 ]; then check "migration: ...for its 3 months ($d days), the extra days defaulting to 0" ok ok; else check "migration: 3 months" "$d days" "89-92"; fi
r=$(curl -s -X POST "$OLD?action=checkout_start" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d "{\"device_id\":\"dev-old-2-$TS\",\"plan_id\":\"test\",\"email\":\"n@n.co\"}")
check "migration: and the upgraded table can start a test purchase" "$(jget "$r" success)" "true"

# ======================================================================================
# Getting a license onto a new device (Recovery.php): the customer's emailed-code route
# and the vendor's find/transfer tools. The fake Brevo in fake_paystack.php catches mail.
# ======================================================================================
adm() { curl -s -X POST "$LI?action=$1" -H "X-Admin-Secret: $SECRET" -H "Content-Type: application/json" -H "CF-Connecting-IP: $TEST_IP" -d "$2"; }
mail_last() { curl -s -G "$FP/_test/mail" --data-urlencode "to=$1"; }
mail_count() { jget "$(curl -s -G "$FP/_test/mail_count" --data-urlencode "to=$1")" count; }
mail_code() { jget "$(mail_last "$1")" text | grep -o 'code is: [0-9]*' | grep -o '[0-9]*'; }   # the 6 digits in the last mail to $1
not_this() { if [ "$1" = "111111" ]; then echo 222222; else echo 111111; fi; }              # a code that is certainly wrong
restore() { # device email  -> asks for a code, returns the 6 digits from the mail
  post restore_start "{\"device_id\":\"$1\",\"email\":\"$2\"}" >/dev/null; mail_code "$2"
}

section "restore: a customer who reinstalled gets the license back (email code)"
TOKEN1=$(jget "$(post activate "{\"code\":\"$CODE\",\"device_id\":\"$DEV\"}")" activation_token)
TOKEN2=$(jget "$(post activate "{\"code\":\"$CODE2\",\"device_id\":\"$DEV\"}")" activation_token)
UNTIL2=$(sql "SELECT valid_until FROM $DB.license_keys WHERE code='$CODE2';")
check "before: both licenses are valid on the old device" "$(jget "$(curl -s -X POST "$LI?action=verify" -H "Authorization: Bearer $TOKEN2")" valid)" "true"
NEW1="dev-reinstalled-$TS"
r=$(post restore_start "{\"device_id\":\"$NEW1\",\"email\":\"nobody@nowhere.example\"}")
check "restore_start: an email that never bought gets the same answer as one that did" "$r" '"success":true'
check "restore_start: ...saying only that a code is sent IF there was a purchase" "$r" "If a NexaPOS purchase was made with this email"
check "restore_start: ...but no email goes to someone who never bought" "$(mail_count nobody@nowhere.example)" "0"
r=$(post restore_start "{\"device_id\":\"$NEW1\",\"email\":\"Buyer@Example.com\"}")
check "restore_start: the real customer gets the same answer" "$r" "If a NexaPOS purchase was made with this email"
RC=$(mail_code buyer@example.com)
check "restore_start: a 6-digit code is emailed to the purchase address" "${#RC}" "6"
check "restore_start: the email's subject carries the code too" "$(jget "$(mail_last buyer@example.com)" subject)" "$RC"
check "restore_start: an HTML version of the email is sent" "$(jget "$(mail_last buyer@example.com)" html)" "Your restore code"
check "restore_start: the database holds a hash, not the code" "$(sql "SELECT COUNT(*) FROM $DB.license_restore_codes WHERE code_hash='$RC';")" "0"
check "restore_start: ...a 64-character hash" "$(sql "SELECT LENGTH(code_hash) FROM $DB.license_restore_codes WHERE email='buyer@example.com' ORDER BY id DESC LIMIT 1;")" "64"
r=$(post restore_confirm "{\"device_id\":\"$NEW1\",\"email\":\"buyer@example.com\",\"code\":\"$(not_this "$RC")\"}")
check "restore_confirm: a wrong code is refused" "$r" "not right"
check_not "restore_confirm: ...and nothing is handed out" "$r" '"code"'
check "restore_confirm: ...and nothing moved" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$NEW1';")" "0"
r=$(post restore_confirm "{\"device_id\":\"$NEW1\",\"email\":\"buyer@example.com\",\"code\":\"12\"}")
check "restore_confirm: a code that is not 6 digits is refused" "$r" "6-digit code"
r=$(post restore_confirm "{\"device_id\":\"$NEW1\",\"email\":\"buyer@example.com\",\"code\":\"$RC\"}")
check "restore_confirm: the right code succeeds" "$r" '"success":true'
check "restore_confirm: the answer is the license that runs longest" "$(jget "$r" code)" "$CODE2"
check "restore_confirm: both licenses of the stack moved" "$(jget "$r" moved)" "2"
check "DB: both licenses are now on the new device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$NEW1' AND code IN ('$CODE','$CODE2');")" "2"
check "DB: nothing is left on the old device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$DEV';")" "0"
check "DB: the end date did not change" "$(sql "SELECT valid_until FROM $DB.license_keys WHERE code='$CODE2';")" "$UNTIL2"
check "DB: the old tokens were dropped" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE code IN ('$CODE','$CODE2') AND activation_token_hash IS NOT NULL;")" "0"
check "the old device's tokens no longer verify (license 1)" "$(jget "$(curl -s -X POST "$LI?action=verify" -H "Authorization: Bearer $TOKEN1")" valid)" "false"
check "the old device's tokens no longer verify (license 2)" "$(jget "$(curl -s -X POST "$LI?action=verify" -H "Authorization: Bearer $TOKEN2")" valid)" "false"
check "the move is logged as the customer's own" "$(sql "SELECT COUNT(*) FROM $DB.license_transfers WHERE code IN ('$CODE','$CODE2') AND moved_by='self' AND to_device_id='$NEW1' AND from_device_id='$DEV';")" "2"
act=$(post activate "{\"code\":\"$CODE2\",\"device_id\":\"$NEW1\"}")
check "the new device activates the restored license the ordinary way" "$act" '"success":true'
check "...with the same end date as before" "$(jget "$act" valid_until)" "$UNTIL2"
check "...and its new token verifies" "$(jget "$(curl -s -X POST "$LI?action=verify" -H "Authorization: Bearer $(jget "$act" activation_token)")" valid)" "true"
check "the old device can no longer activate it" "$(post activate "{\"code\":\"$CODE2\",\"device_id\":\"$DEV\"}")" "belongs to another device"
r=$(post restore_confirm "{\"device_id\":\"$NEW1\",\"email\":\"buyer@example.com\",\"code\":\"$RC\"}")
check "a code works once only" "$r" "not right"

section "restore: a code that is guessed at is burned after 5 tries"
LOCK="dev-lock-$TS"
RC=$(restore "$LOCK" g@g.co)
for i in 1 2 3 4 5; do r=$(post restore_confirm "{\"device_id\":\"$LOCK\",\"email\":\"g@g.co\",\"code\":\"$(not_this "$RC")\"}"); done
check "5 wrong codes: each just 'not right'" "$r" "not right"
r=$(post restore_confirm "{\"device_id\":\"$LOCK\",\"email\":\"g@g.co\",\"code\":\"$RC\"}")
check "the 6th try is refused even with the right code" "$r" "Too many wrong codes"
check "...and the license stayed where it was" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$LOCK';")" "0"
RC=$(restore "$LOCK" g@g.co)
check "...asking again gives a fresh code" "$(jget "$(post restore_confirm "{\"device_id\":\"$LOCK\",\"email\":\"g@g.co\",\"code\":\"$RC\"}")" success)" "true"
check "...which works, and takes the license" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$LOCK';")" "1"

section "restore: the code belongs to the device that asked, and to the email it was sent to"
RC=$(restore "dev-asker-$TS" h@h.co)
r=$(post restore_confirm "{\"device_id\":\"dev-thief-$TS\",\"email\":\"h@h.co\",\"code\":\"$RC\"}")
check "another device cannot use the code" "$r" "not right"
r=$(post restore_confirm "{\"device_id\":\"dev-asker-$TS\",\"email\":\"buyer@example.com\",\"code\":\"$RC\"}")
check "the code does not unlock a different email's license" "$r" "not right"
check "the license is still on its device" "$(sql "SELECT device_id FROM $DB.license_keys WHERE code=(SELECT license_code FROM $DB.license_purchases WHERE email='h@h.co' AND status='issued' LIMIT 1);")" "dev-hook-$TS"
check "...and no code was spent by those attempts on the real device" "$(jget "$(post restore_confirm "{\"device_id\":\"dev-asker-$TS\",\"email\":\"h@h.co\",\"code\":\"$RC\"}")" success)" "true"

section "restore: paid for but never collected"
DEVC="dev-claim-old-$TS"; NEWC="dev-claim-new-$TS"
r=$(post checkout_start "{\"device_id\":\"$DEVC\",\"plan_id\":\"m3\",\"email\":\"claim@c.co\"}"); REFC=$(jget "$r" reference)
BODYC="{\"event\":\"charge.success\",\"data\":{\"reference\":\"$REFC\",\"status\":\"success\",\"amount\":150000,\"currency\":\"KES\"}}"
curl -s -o /dev/null -X POST "$LI?action=paystack_webhook" -H "x-paystack-signature: $(hmac "$BODYC" "$KEY")" --data-binary "$BODYC"
check "setup: the payment is 'paid' (webhook) but the app never collected the license" "$(sql "SELECT status FROM $DB.license_purchases WHERE reference='$REFC';")" "paid"
RC=$(restore "$NEWC" claim@c.co)
check "a code is emailed to someone whose payment is still waiting" "${#RC}" "6"
r=$(post restore_confirm "{\"device_id\":\"$NEWC\",\"email\":\"claim@c.co\",\"code\":\"$RC\"}")
check "the waiting payment is issued to the new device" "$r" '"success":true'
check "...one payment claimed" "$(jget "$r" claimed)" "1"
check "DB: a license exists on the new device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$NEWC';")" "1"
check "DB: the purchase is issued, and now belongs to the new device" "$(sql "SELECT CONCAT(status,'|',device_id) FROM $DB.license_purchases WHERE reference='$REFC';")" "issued|$NEWC"
check "...and it activates" "$(jget "$(post activate "{\"code\":\"$(jget "$r" code)\",\"device_id\":\"$NEWC\"}")" success)" "true"

section "restore: nothing to restore / expired"
DEVX="dev-race-$TS"
sql "UPDATE $DB.license_keys SET valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE device_id='$DEVX';"
NEWX="dev-expired-new-$TS"
RC=$(restore "$NEWX" r@r.co)
r=$(post restore_confirm "{\"device_id\":\"$NEWX\",\"email\":\"r@r.co\",\"code\":\"$RC\"}")
check "an expired license is not restored - the customer is pointed at renewing" "$r" "No active license"
check "...and it stayed put" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$DEVX';")" "1"

section "restore: a license cannot be passed around without limit"
LOCKED=$(sql "SELECT code FROM $DB.license_keys WHERE device_id='$LOCK' LIMIT 1;")
for i in 1 2; do sql "INSERT INTO $DB.license_transfers (code, from_device_id, to_device_id, moved_by) VALUES ('$LOCKED', 'a$i', 'b$i', 'self');"; done
RC=$(restore "dev-limit-new-$TS" g@g.co)
r=$(post restore_confirm "{\"device_id\":\"dev-limit-new-$TS\",\"email\":\"g@g.co\",\"code\":\"$RC\"}")
check "after 3 self-service moves in 90 days a 4th is refused, and support is named" "$r" "Contact NexaPOS"
check "...the license did not move" "$(sql "SELECT device_id FROM $DB.license_keys WHERE code='$LOCKED';")" "$LOCK"

section "restore: the email could not be sent"
fake mode '{"mail_500":true}'
r=$(post restore_start "{\"device_id\":\"dev-mailfail-$TS\",\"email\":\"h@h.co\"}")
check "a mail outage is reported honestly (not 'a code is on its way')" "$r" "could not be emailed"
fake mode '{"mail_500":false}'

section "restore: validation and rate limits"
r=$(post restore_start '{"email":"a@b.co"}');                                   check "no device -> rejected" "$r" "device_id is required"
r=$(post restore_start "{\"device_id\":\"d-v-$TS\",\"email\":\"not-an-email\"}"); check "bad email -> rejected" "$r" "email address you paid with"
for i in 1 2 3; do post restore_start "{\"device_id\":\"dev-rl-$i-$TS\",\"email\":\"rate@r.co\"}" >/dev/null; done
r=$(post restore_start "{\"device_id\":\"dev-rl-4-$TS\",\"email\":\"rate@r.co\"}")
check "the 4th code request in an hour for one email is refused" "$r" "Too many attempts"
section "restore: rate limit per device"
for i in 1 2 3 4 5; do post restore_start "{\"device_id\":\"dev-rld-$TS\",\"email\":\"rld$i@r.co\"}" >/dev/null; done
r=$(post restore_start "{\"device_id\":\"dev-rld-$TS\",\"email\":\"rld6@r.co\"}")
check "the 6th code request in an hour from one device is refused" "$r" "Too many attempts"
section "restore: rate limit per address"
for i in $(seq 1 10); do post restore_start "{\"device_id\":\"dev-rli-$i-$TS\",\"email\":\"rli$i@r.co\"}" >/dev/null; done
r=$(post restore_start "{\"device_id\":\"dev-rli-11-$TS\",\"email\":\"rli11@r.co\"}")
check "the 11th code request in an hour from one address is refused" "$r" "Too many attempts"

section "vendor: find a customer"
r=$(curl -s -X POST "$LI?action=find_license" -H "Content-Type: application/json" -d '{"query":"buyer@example.com"}')
check "find_license: needs the admin secret" "$r" "Invalid or missing admin secret"
r=$(adm find_license '{"query":"Buyer@Example.com"}')
check "find by email: finds the licenses bought with it" "$r" "$CODE2"
check "find by email: ...and the purchases" "$r" "$REF8"
check "find by email: ...and where they are now (the new device)" "$r" "$NEW1"
check "find by email: ...and the history of moves" "$r" "\"moved_by\":\"self\""
check_not "find by email: no internal payment URLs leak into the vendor view" "$r" "authorization_url"
r=$(adm find_license "{\"query\":\"$NEW1\"}")
check "find by device ID: finds its licenses" "$r" "$CODE2"
r=$(adm find_license "{\"query\":\"$(echo "$CODE2" | tr 'A-Z' 'a-z')\"}")
check "find by license code (any case): finds it" "$r" "\"code\":\"$CODE2\""
check "find by code: says whether a token is held" "$r" '"has_token":true'
r=$(adm find_license '{"query":"nothing-matches-this"}')
check "find: an unknown query is a clean empty answer" "$r" '"licenses":[]'
r=$(adm find_license '{"query":""}');  check "find: an empty query is refused" "$r" "Enter an email"

section "vendor: move a license to a new device"
r=$(curl -s -X POST "$LI?action=transfer_license" -H "Content-Type: application/json" -d "{\"code\":\"$CODE2\",\"new_device_id\":\"x\"}")
check "transfer_license: needs the admin secret" "$r" "Invalid or missing admin secret"
ADM1="dev-vendor-moved-$TS"
r=$(adm transfer_license "{\"code\":\"$CODE2\"}");                         check "no new device ID -> refused" "$r" "new device ID"
r=$(adm transfer_license "{\"new_device_id\":\"$ADM1\"}");                 check "no license named -> refused" "$r" "either the license code"
r=$(adm transfer_license "{\"code\":\"NOSUCHCODE\",\"new_device_id\":\"$ADM1\"}"); check "unknown license -> refused" "$r" "No such license"
r=$(adm transfer_license "{\"code\":\"$CODE2\",\"new_device_id\":\"$NEW1\"}"); check "moving to the device it is already on -> refused" "$r" "already on this device"
r=$(adm transfer_license "{\"from_device_id\":\"$NEW1\",\"new_device_id\":\"$ADM1\"}")
check "moving by the OLD device ID works" "$r" '"success":true'
check "...and reports which licenses moved" "$r" "$CODE2"
check "...and that the end date was kept" "$(jget "$r" valid_until)" "$UNTIL2"
check "DB: the whole stack is now on the new device" "$(sql "SELECT COUNT(*) FROM $DB.license_keys WHERE device_id='$ADM1' AND code IN ('$CODE','$CODE2');")" "2"
check "DB: logged as the vendor's move" "$(sql "SELECT COUNT(*) FROM $DB.license_transfers WHERE moved_by='admin' AND to_device_id='$ADM1';")" "2"
act=$(post activate "{\"code\":\"$CODE2\",\"device_id\":\"$ADM1\"}")
check "the customer then enters the key on the new device and it activates" "$act" '"success":true'
sql "UPDATE $DB.license_keys SET valid_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE code='$CODE';"
ADM2="dev-vendor-moved-2-$TS"
r=$(adm transfer_license "{\"code\":\"$CODE\",\"new_device_id\":\"$ADM2\"}")
check "moving an expired license works but says to extend it first" "$(jget "$r" expired)" "true"
check "...with a message to that effect" "$r" "extend it"

section "admin changes an exact license expiry"
editable=$(adm issue '{"license_duration_days":30}'); EDITABLE=$(jget "$editable" code)
post activate "{\"code\":\"$EDITABLE\",\"device_id\":\"dev-expiry-$TS\"}" >/dev/null
r=$(curl -s -X POST "$LI?action=set_expiry" -H "Content-Type: application/json" -d "{\"code\":\"$EDITABLE\",\"valid_until\":\"2031-04-05T06:07:08Z\"}")
check "set_expiry: needs the admin secret" "$r" "Invalid or missing admin secret"
r=$(adm set_expiry "{\"code\":\"$EDITABLE\"}"); check "set_expiry: needs a date" "$r" "code and valid_until are required"
r=$(adm set_expiry "{\"code\":\"$EDITABLE\",\"valid_until\":\"2031-04-05 06:07:08\"}"); check "set_expiry: refuses a timezone-less date" "$r" "with a timezone"
r=$(adm set_expiry "{\"code\":\"NO-SUCH-KEY\",\"valid_until\":\"2031-04-05T06:07:08Z\"}"); check "set_expiry: unknown key is clear" "$r" "No license key"
unused=$(adm issue '{"license_duration_days":30}'); UNUSED=$(jget "$unused" code)
r=$(adm set_expiry "{\"code\":\"$UNUSED\",\"valid_until\":\"2031-04-05T06:07:08Z\"}"); check "set_expiry: unused key is refused" "$r" "not been activated"
r=$(adm set_expiry "{\"code\":\"$EDITABLE\",\"valid_until\":\"2031-04-05T06:07:08+03:00\"}")
check "set_expiry: succeeds" "$(jget "$r" success)" "true"
check "set_expiry: normalizes the instant to UTC" "$(jget "$r" valid_until)" "2031-04-05 03:07:08"
check "set_expiry: database holds the exact corrected instant" "$(sql "SELECT valid_until FROM $DB.license_keys WHERE code='$EDITABLE';")" "2031-04-05 03:07:08"
# a revoked license, and one nobody has used yet
sql "UPDATE $DB.license_keys SET revoked = 1 WHERE code='$CODE2';"
r=$(adm transfer_license "{\"code\":\"$CODE2\",\"new_device_id\":\"dev-x-$TS\"}")
check "a revoked license is not moved" "$r" "revoked"
issued=$(adm issue '{"license_duration_days":30}'); FRESH=$(jget "$issued" code)
r=$(adm transfer_license "{\"code\":\"$FRESH\",\"new_device_id\":\"dev-y-$TS\"}")
check "a key nobody has used yet is not 'moved' - the customer just enters it" "$r" "has not been used on any device yet"

section "nothing else broke"
issue=$(curl -s -X POST "$LI?action=issue" -H "X-Admin-Secret: $SECRET" -H "Content-Type: application/json" -d '{"license_duration_days":30}')
check "the vendor's manual issue still works" "$issue" '"success":true'
check "health still works" "$(curl -s "$LI?action=health")" '"service":"nexapos_license"'

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
