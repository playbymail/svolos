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
#   FPM_SERVICE=php8.5-fpm scripts/deploy.sh  # pin the service instead of deriving it
set -Eeuo pipefail

APP_DIR=/srv/svolos
DATA_DIR=/srv/svolos-data
BRANCH="${BRANCH:-main}"
SKIP_NPM="${SKIP_NPM:-0}"
KEEP_BACKUPS=10

# Derived from the running CLI PHP rather than hardcoded, because the version moves with
# the distribution — Ubuntu 26.04 ships 8.5, and a literal `php8.4-fpm` here fails at the
# very last step of an otherwise successful deploy with "Unit not found", leaving the new
# code live but opcache still holding the previous build. The CLI and the FPM pool are the
# same series in this setup (both come from the same apt packages), so this is the version
# to follow.
FPM_SERVICE="${FPM_SERVICE:-php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm}"

cd "$APP_DIR"

# Checked up front, before maintenance mode and before anything is migrated. Reloading is
# the last step, so a wrong service name would otherwise only surface after the risky part
# of the deploy had already run.
if ! systemctl list-unit-files --no-legend "$FPM_SERVICE.service" | grep -q .; then
    echo "no such service: $FPM_SERVICE" >&2
    echo "installed PHP-FPM units:" >&2
    systemctl list-unit-files --no-legend 'php*-fpm.service' >&2
    echo "set FPM_SERVICE=<unit> and re-run, and update /etc/sudoers.d/deploy-svolos to match" >&2
    exit 1
fi

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
