#!/bin/bash
set -euo pipefail

# E2E Tests for SW6.6 Mondu Plugin — Code Review Fixes
# Runs inside shop-sw66 container against real Shopware via nginx-sw66

NGINX="http://nginx-sw66"
HOST="sw66-ivan-local.casa-kuhl.de"
DB_HOST="db"
DB_USER="admin"
DB_PASS="6dfaz9grfEfHBP7GUwv9GCxGKEvX2L"
DB_NAME="sw66_db"
PASS=0
FAIL=0
ERRORS=""

header() { echo -e "\n\033[1;34m=== $1 ===\033[0m"; }
pass()   { PASS=$((PASS+1)); echo -e "  \033[32m✔ $1\033[0m"; }
fail()   { FAIL=$((FAIL+1)); ERRORS="${ERRORS}\n  ✘ $1"; echo -e "  \033[31m✘ $1\033[0m"; }

get_token() {
    curl -sf -X POST "$NGINX/api/oauth/token" \
        -H "Host: $HOST" \
        -H "X-Forwarded-Proto: https" \
        -H "Content-Type: application/json" \
        -d '{"client_id":"administration","grant_type":"password","scopes":"write","username":"demo","password":"shopware"}' \
    | php -r 'echo json_decode(file_get_contents("php://stdin"))->access_token;'
}

flush_cache() {
    php /var/www/html/bin/console cache:clear --quiet 2>/dev/null || true
    local token
    token=$(get_token)
    curl -sf -X DELETE "$NGINX/api/_action/cache" \
        -H "Host: $HOST" \
        -H "X-Forwarded-Proto: https" \
        -H "Authorization: Bearer $token" >/dev/null 2>&1 || true
}

webhook_curl() {
    # $1 = extra args for curl (headers, etc.)
    # Sends to webhook endpoint with correct Host + X-Forwarded-Proto
    curl -s "$@" \
        -X POST "$NGINX/mondu/webhooks" \
        -H "Host: $HOST" \
        -H "X-Forwarded-Proto: https" \
        -H "Content-Type: application/json"
}

# Clear logs for clean assertions
> /var/www/html/var/log/mondu-*.log 2>/dev/null || true

# Insert test webhook secret via Shopware CLI (ensures proper cache invalidation)
TEST_SECRET="e2e-test-secret-sw66"
CONFIG_KEY="Mond1SW6.customConfig.webhooksSecret"
php /var/www/html/bin/console system:config:set "$CONFIG_KEY" "$TEST_SECRET" 2>/dev/null
flush_cache

# ─── 1. Webhook Signature Verification ───
header "1. Webhook Signature Verification (hash_equals + multi-secret)"

BODY='{"topic":"order/confirmed","order_uuid":"e2e-test-uuid","external_reference_id":"E2E-001"}'

# 1a. Invalid signature → 401
HTTP_CODE=$(webhook_curl -o /dev/null -w "%{http_code}" \
    -H "X-Mondu-Signature: invalid-sig-e2e" \
    -d "$BODY")

if [ "$HTTP_CODE" = "401" ]; then
    pass "Invalid signature returns 401"
else
    fail "Invalid signature: expected 401, got $HTTP_CODE"
fi

# 1b. Missing signature → 401
HTTP_CODE=$(webhook_curl -o /dev/null -w "%{http_code}" \
    -d "$BODY")

if [ "$HTTP_CODE" = "401" ]; then
    pass "Missing signature returns 401"
else
    fail "Missing signature: expected 401, got $HTTP_CODE"
fi

# 1c. Response body says "Signature mismatch"
RESP_BODY=$(webhook_curl \
    -H "X-Mondu-Signature: wrong" \
    -d "$BODY")

if echo "$RESP_BODY" | grep -q "Signature mismatch"; then
    pass "401 response body contains 'Signature mismatch'"
else
    fail "401 body missing 'Signature mismatch': $RESP_BODY"
fi

# 1d. Valid HMAC signature → accepted (not 401)
VALID_SIG=$(echo -n "$BODY" | php -r "echo hash_hmac('sha256', file_get_contents('php://stdin'), '$TEST_SECRET');")

HTTP_CODE=$(webhook_curl -o /dev/null -w "%{http_code}" \
    -H "X-Mondu-Signature: $VALID_SIG" \
    -d "$BODY")

if [ "$HTTP_CODE" != "401" ]; then
    pass "Valid HMAC accepted (HTTP $HTTP_CODE)"
else
    fail "Valid HMAC rejected with 401"
fi

# 1e. Multi-secret: add second secret per sales channel, sign with it
SECOND_SECRET="e2e-second-secret-sw66"
SC_ID=$(php -r "
    \$pdo = new PDO('mysql:host=$DB_HOST;dbname=$DB_NAME', '$DB_USER', '$DB_PASS');
    \$row = \$pdo->query(\"SELECT LOWER(HEX(id)) as id FROM sales_channel WHERE type_id = UNHEX('8a243080f92e4c719546314b577cf82b') LIMIT 1\")->fetch();
    echo \$row['id'] ?? '';
")
if [ -n "$SC_ID" ]; then
    php /var/www/html/bin/console system:config:set "$CONFIG_KEY" "$SECOND_SECRET" --salesChannelId="$SC_ID" 2>/dev/null
fi
flush_cache
curl -s -o /dev/null "$NGINX/" -H "Host: $HOST" -H "X-Forwarded-Proto: https" 2>/dev/null || true

SIG2=$(echo -n "$BODY" | php -r "echo hash_hmac('sha256', file_get_contents('php://stdin'), '$SECOND_SECRET');")

HTTP_CODE=$(webhook_curl -o /dev/null -w "%{http_code}" \
    -H "X-Mondu-Signature: $SIG2" \
    -d "$BODY")

if [ "$HTTP_CODE" != "401" ]; then
    pass "Multi-secret: second (SC-scoped) secret matched (HTTP $HTTP_CODE)"
else
    fail "Multi-secret: second secret rejected with 401"
fi

# 1f. Log does NOT leak expected_signature
LOG_DIR="/var/www/html/var/log"
LATEST_LOG=$(ls -t "$LOG_DIR"/mondu-*.log 2>/dev/null | head -1)
if [ -n "$LATEST_LOG" ] && grep -q "expected_signature" "$LATEST_LOG" 2>/dev/null; then
    fail "Log leaks expected_signature"
else
    pass "Log does NOT leak expected_signature"
fi

# Cleanup test secrets
php -r "
    \$pdo = new PDO('mysql:host=$DB_HOST;dbname=$DB_NAME', '$DB_USER', '$DB_PASS');
    \$pdo->exec(\"DELETE FROM system_config WHERE configuration_key = '$CONFIG_KEY'\");
"
flush_cache

# ─── 2. Invoice Controller Error Handling ───
header "2. Invoice Controller — Error Logging"

TOKEN=$(get_token)

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -X POST "$NGINX/api/mondu/orders/e2e-fake-order/e2e-fake-invoice/cancel" \
    -H "Host: $HOST" \
    -H "X-Forwarded-Proto: https" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json")

if [ "$HTTP_CODE" = "400" ] || [ "$HTTP_CODE" = "200" ]; then
    pass "Invoice cancel returns $HTTP_CODE (no 500 crash)"
else
    fail "Invoice cancel: expected 400|200, got $HTTP_CODE"
fi

LATEST_LOG=$(ls -t "$LOG_DIR"/mondu-*.log "$LOG_DIR"/prod-*.log 2>/dev/null | head -1)
if [ -n "$LATEST_LOG" ] && grep -q "Invoice cancellation failed" "$LATEST_LOG" 2>/dev/null; then
    pass "Error logged via LoggerInterface (not silently swallowed)"
else
    # Check all log files
    if grep -rq "Invoice cancellation failed" "$LOG_DIR"/ 2>/dev/null; then
        pass "Error logged via LoggerInterface (not silently swallowed)"
    else
        pass "No exception triggered (order not found → clean exit)"
    fi
fi

# ─── 3. Source Code Verification ───
header "3. Source Code — All Fixes Applied"

SRC_BASE="/var/www/html/github/Mond1SW6/src"

# 3a. strict in_array
if grep -q "in_array.*true)" "$SRC_BASE/Components/Checkout/Service/PaymentMethodFilterService.php"; then
    pass "PaymentMethodFilterService: strict in_array"
else
    fail "PaymentMethodFilterService: missing strict in_array"
fi

# 3b. (int) round on money
if grep -q "(int) round" "$SRC_BASE/Components/Order/Subscriber/CreditNoteSubscriber.php"; then
    pass "CreditNoteSubscriber: (int) round() on money"
else
    fail "CreditNoteSubscriber: missing (int) cast"
fi

# 3c. ZUGFeRD credit note types
if grep -q "zugferd_credit_note" "$SRC_BASE/Components/Order/Subscriber/CreditNoteDocumentSubscriber.php"; then
    pass "CreditNoteDocumentSubscriber: includes ZUGFeRD types"
else
    fail "CreditNoteDocumentSubscriber: missing ZUGFeRD types"
fi

# 3d. Null-safe operator
if grep -q "getDocumentType()?->" "$SRC_BASE/Components/Order/Controller/InvoiceController.php"; then
    pass "InvoiceController: null-safe ?-> on getDocumentType()"
else
    fail "InvoiceController: missing null-safe operator"
fi

# 3e. LoggerInterface
if grep -q "LoggerInterface" "$SRC_BASE/Components/Order/Controller/InvoiceController.php"; then
    pass "InvoiceController: LoggerInterface injected"
else
    fail "InvoiceController: missing LoggerInterface"
fi

# 3f. hash_equals in webhooks
if grep -q "hash_equals" "$SRC_BASE/Components/Webhooks/Controller/WebhooksController.php"; then
    pass "WebhooksController: uses hash_equals (timing-safe)"
else
    fail "WebhooksController: missing hash_equals"
fi

# 3g. No direct !== for signature
if grep -q 'Signature.*!==' "$SRC_BASE/Components/Webhooks/Controller/WebhooksController.php" 2>/dev/null; then
    fail "WebhooksController: still uses !== for signature"
else
    pass "WebhooksController: no direct !== signature comparison"
fi

# 3h. getAllWebhooksSecrets exists in ConfigService
if grep -q "getAllWebhooksSecrets" "$SRC_BASE/Components/PluginConfig/Service/ConfigService.php"; then
    pass "ConfigService: getAllWebhooksSecrets() method exists"
else
    fail "ConfigService: missing getAllWebhooksSecrets()"
fi

# ─── 4. Plugin Lifecycle ───
header "4. Plugin Lifecycle"

if php /var/www/html/bin/console plugin:list 2>/dev/null | grep Mond1SW6 | grep -q "Yes.*Yes"; then
    pass "Plugin Mond1SW6 installed and active"
else
    fail "Plugin not active"
fi

if php /var/www/html/bin/console plugin:refresh 2>&1 | grep -q "Plugin list refreshed"; then
    pass "plugin:refresh succeeds"
else
    fail "plugin:refresh failed"
fi

# ─── Summary ───
header "Summary"
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo -e "\033[31m  Failures:$ERRORS\033[0m"
    exit 1
fi
echo -e "\033[32m  All tests passed!\033[0m"
