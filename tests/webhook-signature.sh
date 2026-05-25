#!/usr/bin/env bash
# Integration test for Mondu webhook signature validation in a multi-SC setup.
#
# Exercises the following end-to-end against the real shop-sw66 container:
#   1. Seed two distinct webhook secrets in system_config:
#        - default (global) scope
#        - French sales channel scope
#   2. Craft a tiny JSON body and HMAC-sign it with each of the two secrets.
#   3. POST the body to /mondu/webhooks (main URL) and /fr/mondu/webhooks
#      (French URL), once with each secret's signature and once with a random
#      bogus signature.
#   4. Assert expected HTTP status (200 on accepted, 401 on mismatch).
#
# Exit code 0 if every assertion passes, 1 otherwise.
#
# Usage: tests/webhook-signature.sh
#
# The script is idempotent — it restores the pre-existing Mond1SW6 secrets
# (or removes the rows it inserted) on exit.

set -u

DB_CONTAINER="${DB_CONTAINER:-db}"
SHOP_DB="${SHOP_DB:-sw66_db}"
CURL_CONTAINER="${CURL_CONTAINER:-shop-sw66}"
REDIS_CONTAINER="${REDIS_CONTAINER:-redis}"
# Redis DB used by SW6_system (see config/packages/shopware.yml). This holds
# SystemConfigService cache that must be flushed after direct SQL writes
# so the controller reads the freshly-seeded secret.
REDIS_SYSTEM_DB="${REDIS_SYSTEM_DB:-0}"
NGINX_HOST="${NGINX_HOST:-nginx-sw66}"
HOST="${HOST:-sw66-ivan-local.casa-kuhl.de}"
FRENCH_SC_HEX="019DB6A081A971CAACF1C1F32EC9722E"

SECRET_DEFAULT="default-scope-secret-${RANDOM}"
SECRET_FRENCH="french-scope-secret-${RANDOM}"
BOGUS="bogus-${RANDOM}"
# Use an unregistered topic on purpose — we want to exercise only the signature
# validation branch. For a known topic the controller would then try to process
# an order that doesn't exist and the assertion would race with unrelated 4xx.
# The default branch in the controller always returns HTTP 200 "Unregistered topic"
# after a successful signature check.
BODY='{"topic":"__integration_test__.noop","order_uuid":"00000000-0000-4000-8000-000000000000"}'

pass=0
fail=0

sql() {
  local db_pass
  db_pass=$(docker exec "$DB_CONTAINER" cat /run/secrets/db_root_password)
  docker exec -e MYSQL_PWD="$db_pass" "$DB_CONTAINER" mysql -uroot -D "$SHOP_DB" -Bse "$1"
}

# Use a cached DB password so we can run sql() hundreds of times without shelling in
DB_PASS=$(docker exec "$DB_CONTAINER" cat /run/secrets/db_root_password)

sql_fast() {
  docker exec -i -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" mysql -uroot -D "$SHOP_DB" -Bse "$1"
}

flush_system_cache() {
  docker exec "$REDIS_CONTAINER" redis-cli -n "$REDIS_SYSTEM_DB" FLUSHDB >/dev/null
}

set_config() {
  local key="$1" value="$2" sc_hex="${3:-}"
  local sc_clause="sales_channel_id IS NULL"
  local sc_insert="NULL"
  if [[ -n "$sc_hex" ]]; then
    sc_clause="sales_channel_id = UNHEX('$sc_hex')"
    sc_insert="UNHEX('$sc_hex')"
  fi
  local value_json='{"_value":"'"$value"'"}'
  sql_fast "DELETE FROM system_config WHERE configuration_key='$key' AND $sc_clause;
            INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at)
            VALUES (UNHEX(REPLACE(UUID(),'-','')), '$key', '$value_json', $sc_insert, NOW(3));"
  flush_system_cache
}

clear_config() {
  local key="$1" sc_hex="${2:-}"
  local sc_clause="sales_channel_id IS NULL"
  [[ -n "$sc_hex" ]] && sc_clause="sales_channel_id = UNHEX('$sc_hex')"
  sql_fast "DELETE FROM system_config WHERE configuration_key='$key' AND $sc_clause;"
  flush_system_cache
}

hmac() {
  local secret="$1" body="$2"
  printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -binary | xxd -p -c 256 | tr -d '\n'
}

post() {
  # POST body to path (via nginx → shop container) and print HTTP status.
  # Use HTTPS — Shopware routing matches SC domains by full URL incl. scheme.
  local path="$1" signature="$2"
  docker exec "$CURL_CONTAINER" sh -c "
    curl -sk -o /dev/null -w '%{http_code}' -X POST \
      -H 'Host: $HOST' \
      -H 'Content-Type: application/json' \
      -H 'X-Mondu-Signature: $signature' \
      --data '$BODY' \
      https://$NGINX_HOST:8443$path
  "
}

assert_eq() {
  local got="$1" expected="$2" label="$3"
  if [[ "$got" == "$expected" ]]; then
    echo "  PASS  $label (HTTP $got)"
    pass=$((pass+1))
  else
    echo "  FAIL  $label — expected $expected, got $got"
    fail=$((fail+1))
  fi
}

cleanup() {
  clear_config 'Mond1SW6.customConfig.webhooksSecret'
  clear_config 'Mond1SW6.customConfig.webhooksSecret' "$FRENCH_SC_HEX"
}
trap cleanup EXIT

echo "== setup: seeding two distinct webhook secrets =="
set_config 'Mond1SW6.customConfig.webhooksSecret' "$SECRET_DEFAULT"
set_config 'Mond1SW6.customConfig.webhooksSecret' "$SECRET_FRENCH" "$FRENCH_SC_HEX"

SIG_DEFAULT=$(hmac "$SECRET_DEFAULT" "$BODY")
SIG_FRENCH=$(hmac "$SECRET_FRENCH" "$BODY")
SIG_BOGUS=$(hmac "$BOGUS" "$BODY")

echo
echo "== scenario 1: default-scope secret against main URL =="
assert_eq "$(post '/mondu/webhooks' "$SIG_DEFAULT")" '200' 'default secret -> /mondu/webhooks'

echo
echo "== scenario 2: SC-scope secret against main URL (the bug) =="
# Before the fix the controller only reads the default-scope secret, so the
# French-scope secret would be rejected. After the fix, iteration over every
# known secret accepts it.
assert_eq "$(post '/mondu/webhooks' "$SIG_FRENCH")" '200' 'french secret -> /mondu/webhooks'

echo
echo "== scenario 3: SC-scope secret against french URL =="
assert_eq "$(post '/fr/mondu/webhooks' "$SIG_FRENCH")" '200' 'french secret -> /fr/mondu/webhooks'

echo
echo "== scenario 4: default-scope secret against french URL =="
assert_eq "$(post '/fr/mondu/webhooks' "$SIG_DEFAULT")" '200' 'default secret -> /fr/mondu/webhooks'

echo
echo "== scenario 5: bogus signature is rejected =="
assert_eq "$(post '/mondu/webhooks' "$SIG_BOGUS")" '401' 'bogus sig -> /mondu/webhooks'
assert_eq "$(post '/fr/mondu/webhooks' "$SIG_BOGUS")" '401' 'bogus sig -> /fr/mondu/webhooks'

echo
echo "== summary =="
echo "  passed: $pass"
echo "  failed: $fail"

[[ "$fail" -eq 0 ]]
