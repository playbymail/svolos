#!/usr/bin/env bash
#
# Deploy the currently pushed state of $BRANCH into this working copy.
#
# The deployment model is a plain git working copy at /srv/svolos, with the database and
# its backups outside it at /srv/svolos-data — see DEPLOY-CADDY.md. There is no releases/
# directory and no current symlink, so this script updates the running application in
# place, under maintenance mode.
#
# Safe to re-run. If any step fails the script stops and brings the application back up,
# so a failed deploy leaves the previous code running: the working copy may already be on
# the new commit, but nothing was migrated or re-cached.
#
# Overrides:
#   BRANCH=hotfix/urgent scripts/deploy.sh    # deploy something other than main
#   SKIP_NPM=1 scripts/deploy.sh              # backend-only change, skips the vite build
set -Eeuo pipefail

APP_DIR=/srv/svolos
DATA_DIR=/srv/svolos-data
BRANCH="${BRANCH:-main}"
SKIP_NPM="${SKIP_NPM:-0}"
KEEP_BACKUPS=10
FPM_SERVICE=php8.4-fpm

cd "$APP_DIR"

trap 'echo "deploy failed — bringing the application back up" >&2; php artisan up || true' ERR

php artisan down --retry=15

# Taken before anything is pulled or migrated, so the backup is always the state the
# application was serving a moment ago. `.backup` is SQLite's online backup API, which is
# consistent even if a request is mid-write — unlike copying the file.
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
sqlite3 "$DATA_DIR/database.sqlite" ".backup '$DATA_DIR/backups/database-$stamp.sqlite'"
ls -1t "$DATA_DIR"/backups/database-*.sqlite | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm --

git fetch --prune origin
git switch "$BRANCH"
git pull --ff-only origin "$BRANCH"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Before the Vite build, not after. The Wayfinder plugin boots Laravel during `vite build`
# to generate the typed route helpers in resources/js/{actions,routes}, so a stale route
# cache makes it generate against the routes of the previous deploy.
php artisan optimize:clear

if [ "$SKIP_NPM" != "1" ]; then
    npm ci
    npm run build
fi

php artisan migrate --force
php artisan optimize

sudo systemctl reload "$FPM_SERVICE"

php artisan up

echo "deployed $(git rev-parse --short HEAD) on $BRANCH"
