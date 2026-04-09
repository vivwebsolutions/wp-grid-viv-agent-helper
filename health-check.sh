#!/bin/bash
# Viv Grid Demo Site — Health Check Script
# Usage: bash health-check.sh [local|wpe]
# Checks all pages, AJAX endpoints, and autocomplete

set -e

ENV="${1:-local}"

if [ "$ENV" = "wpe" ]; then
    BASE="https://p1glossary.wpenginepowered.com"
    AJAX_HEADERS='-H "X-Requested-With: XMLHttpRequest"'
else
    BASE="http://glossary.local:10053"
    AJAX_HEADERS=""
fi

PASS=0
FAIL=0

check() {
    local desc="$1"
    local url="$2"
    local expected="$3"

    STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null)
    if [ "$STATUS" = "$expected" ]; then
        echo "  PASS  $desc ($STATUS)"
        PASS=$((PASS + 1))
    else
        echo "  FAIL  $desc (got $STATUS, expected $expected)"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== Viv Grid Demo Health Check ($ENV) ==="
echo "Base: $BASE"
echo ""

echo "--- Pages ---"
for page in "" resources-grid demo-v2 demo-search-filter demo-sort demo-toggle demo-save-search demo-bookmarks demo-map demo-mobile-filters demo-card-styles demo-autocomplete demo-parent getting-started demo-layouts; do
    check "/$page/" "$BASE/$page/" "200"
done

echo ""
echo "--- AJAX Endpoints ---"

# Test viv-addon AJAX (grid 1)
RESULT=$(curl -s -X POST "$BASE/wp-content/plugins/wp-grid-viv-addon/ajax.php?viv_grid=1" \
    -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode 'wpgb={"id":1,"facets":[4,5]}' --max-time 10 2>/dev/null)
if echo "$RESULT" | python3 -c "import sys,json; j=json.loads(sys.stdin.read()); assert int(j['count'])>0" 2>/dev/null; then
    echo "  PASS  AJAX Grid 1 ($(echo "$RESULT" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['count'])" 2>/dev/null) posts)"
    PASS=$((PASS + 1))
else
    echo "  FAIL  AJAX Grid 1"
    FAIL=$((FAIL + 1))
fi

# Test autocomplete AJAX
AC_RESULT=$(curl -s -X POST "$BASE/wp-content/plugins/wp-grid-viv-autocomplete/ajax.php" \
    -H "X-Requested-With: XMLHttpRequest" \
    -d "viv_acpt=guide&grid_id=11&type=main&facet_id=16" --max-time 10 2>/dev/null)
AC_COUNT=$(echo "$AC_RESULT" | python3 -c "import sys,json; print(len(json.loads(sys.stdin.read())))" 2>/dev/null)
if [ "$AC_COUNT" -gt 0 ] 2>/dev/null; then
    echo "  PASS  Autocomplete ($AC_COUNT results for 'guide')"
    PASS=$((PASS + 1))
else
    echo "  FAIL  Autocomplete"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "=== Results: $PASS passed, $FAIL failed ==="
if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
