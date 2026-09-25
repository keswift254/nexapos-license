#!/bin/bash
# Run:  bash tests/purchases/run.sh      (needs the local XAMPP MySQL + PHP, see MYSQL/PHP below)
# End-to-end test of the license server's "buy a license" flow (Purchases.php) against:
#   * a THROWAWAY local MySQL database (never production),
#   * a FAKE Paystack (fake_paystack.php - nothing here contacts the real Paystack),
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
FP_PORT=8981; LI_PORT=8982; OFF_PORT=8983; SLOW_PORT=8984
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
  kill_port $FP_PORT; kill_port $LI_PORT; kill_port $OFF_PORT; kill_port $SLOW_PORT
  sql "DROP DATABASE IF EXISTS $DB;"
  rm -f "$(cygpath -u "$TEMP" 2>/dev/null || echo /tmp)"/fake_paystack_state_*.json 2>/dev/null
}
trap cleanup EXIT
for p in $FP_PORT $LI_PORT $OFF_PORT $SLOW_PORT; do kill_port $p; done
sleep 1

# The fake Paystack
"$PHP" -S 127.0.0.1:$FP_PORT "$SCR/fake_paystack.php" >/tmp/fp.log 2>&1 &
# The license server WITH payments enabled (verify throttle off so tests need not wait)
start_license() { # port extra-env...
  local port=$1; shift
  env DB_NAME="$DB" LICENSE_ADMIN_SECRET="$SECRET" PAYSTACK_BASE_URL="$FP" LICENSE_PUBLIC_BASE_URL="http://127.0.0.1:$LI_PORT/index.php" "$@" \
    "$PHP" -S 127.0.0.1:$port -t "$LIC/public" >/tmp/li_$port.log 2>&1 &
}
start_license $LI_PORT PAYSTACK_SECRET_KEY=$KEY PAYSTACK_SUBACCOUNT=ACCT_fake123 PURCHASE_VERIFY_EVERY_SECONDS=0
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
check "plans: 3 months 1500" "$r" '{"id":"m3","label":"3 months","months":3,"amount_kes":1500}'
check "plans: 6 months 3000" "$r" '{"id":"m6","label":"6 months","months":6,"amount_kes":3000}'
check "plans: 1 year 4800" "$r" '{"id":"m12","label":"1 year","months":12,"amount_kes":4800}'
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

section "nothing else broke"
issue=$(curl -s -X POST "$LI?action=issue" -H "X-Admin-Secret: $SECRET" -H "Content-Type: application/json" -d '{"license_duration_days":30}')
check "the vendor's manual issue still works" "$issue" '"success":true'
check "health still works" "$(curl -s "$LI?action=health")" '"service":"nexapos_license"'

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
