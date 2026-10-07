#!/usr/bin/env bash
# Build the installable plugin ZIP (dist/site-phonebooks-<version>.zip).
# Only runtime files are included; tests, dev dependencies, docs and examples are excluded via .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -E '^ \* Version:' site-phonebooks.php | awk '{print $3}')
SLUG=site-phonebooks
STAGE=$(mktemp -d)
mkdir -p dist "$STAGE/$SLUG"
rsync -a --exclude-from=.distignore ./ "$STAGE/$SLUG/"
# Sanity checks: no dev files, no real contact data.
test ! -e "$STAGE/$SLUG/vendor"
test ! -e "$STAGE/$SLUG/tests"
( cd "$STAGE/$SLUG" && find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null )
rm -f "dist/$SLUG-$VERSION.zip"
( cd "$STAGE" && zip -qr "$OLDPWD/dist/$SLUG-$VERSION.zip" "$SLUG" -x '*.DS_Store' )
rm -rf "$STAGE"
echo "Built dist/$SLUG-$VERSION.zip"
unzip -l "dist/$SLUG-$VERSION.zip" | tail -1
