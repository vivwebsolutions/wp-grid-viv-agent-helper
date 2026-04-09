#!/bin/bash
# Viv Grid Demo Site — Database Export Script
# Creates a clean SQL export ready for import on another environment.
# Usage: bash export-db.sh [output-file]

MYSQLDUMP="/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.0.35+4/bin/darwin-arm64/bin/mysqldump"
SOCK="/Users/kalmangacs/Library/Application Support/Local/run/eDke5RAWf/mysql/mysqld.sock"
OUTPUT="${1:-/tmp/glossary-export-$(date +%Y%m%d-%H%M%S).sql}"

echo "=== Viv Grid Demo — Database Export ==="
echo "Output: $OUTPUT"

"$MYSQLDUMP" --socket="$SOCK" -u root -proot \
  --single-transaction --quick --lock-tables=false --no-tablespaces \
  local 2>/dev/null > "$OUTPUT"

# Verify
LINES=$(wc -l < "$OUTPUT")
SIZE=$(ls -lh "$OUTPUT" | awk '{print $5}')
TABLES=$(grep -c "^CREATE TABLE" "$OUTPUT")

echo "Size: $SIZE ($LINES lines, $TABLES tables)"
echo ""
echo "To push to WPE:"
echo "  1. Upload: rsync -avz -e 'ssh ... -i \"\$WPE_KEY\"' $OUTPUT local+rsync+p1glossary@p1glossary.ssh.wpengine.net:/sites/p1glossary/_wpeprivate/glossary-local-db.sql"
echo "  2. Import: ssh ... 'wp --path=/sites/p1glossary db import /sites/p1glossary/_wpeprivate/glossary-local-db.sql'"
echo "  3. Replace: ssh ... 'wp --path=/sites/p1glossary search-replace http://glossary.local:10053 https://p1glossary.wpenginepowered.com --all-tables --quiet'"
echo "  4. Flush:   ssh ... 'wp --path=/sites/p1glossary cache flush && wp --path=/sites/p1glossary rewrite flush'"
echo ""
echo "See DEPLOYMENT-GUIDE.md for full SSH commands."
