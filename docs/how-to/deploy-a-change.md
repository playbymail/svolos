# How to deploy a change

This guide shows you how to put a pushed change onto the production server, and how to get back off
it if the change was bad.

For what the deploy script does step by step and what its overrides are, see
[the production server reference](../reference/production-server.md#scriptsdeploysh). If a deploy
fails, see [how to troubleshoot a deployment](troubleshoot-a-deployment.md).

---

## Deploy

Push to `main`, then:

```bash
ssh deploy@svolos.pbbgaming.com
/srv/svolos/scripts/deploy.sh
```

That is the whole routine. The script takes the application down, backs up the database, pulls,
installs, rebuilds, migrates, re-caches, reloads PHP-FPM and brings it back up.

If a step fails, the script stops and brings the application back up, so the previous code keeps
serving. Read the error, fix it, and run the script again.

Caddy does not need reloading. Its config does not change between deploys.

### Deploying something other than `main`

```bash
BRANCH=hotfix/urgent /srv/svolos/scripts/deploy.sh
SKIP_NPM=1 /srv/svolos/scripts/deploy.sh    # backend-only change, skips the vite build
FPM_SERVICE=php8.5-fpm /srv/svolos/scripts/deploy.sh
```

### When the deploy script itself changed

If the commit you are deploying modifies `scripts/deploy.sh`, the run stops after the pull and says
so:

```text
the pull updated this script

  /srv/svolos/scripts/deploy.sh

This run is still executing the version that started, so it stops here rather than
deploying with instructions the new commit has already replaced. Nothing was installed,
migrated or re-cached, and the application is intentionally still in maintenance mode.

Run it again to finish the deploy with the new script:

  /srv/svolos/scripts/deploy.sh
```

Do exactly that. The second run's own check passes, because the file no longer changes.

The application is left **down** on purpose between the two runs, and the second run is a normal
deploy rather than a recovery — nothing was installed, migrated or re-cached. See
`.ai/rules/deployment.md` for why the check exists at all.

---

## Roll back

### The code

```bash
cd /srv/svolos
git log --oneline -10
```

Pick the last good commit and deploy it by hand:

```bash
php artisan down
git checkout <sha>
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
npm ci && npm run build
php artisan optimize
sudo systemctl reload php8.5-fpm
php artisan up
```

You are now on a detached HEAD. Get back onto the branch once the fix is pushed:

```bash
git switch main
/srv/svolos/scripts/deploy.sh
```

### The database

Reverting code does **not** revert a migration. If the bad deploy changed the schema, restore the
backup the deploy script took immediately beforehand:

```bash
ls -1t /srv/svolos-data/backups/
php artisan down
cp /srv/svolos-data/backups/database-TIMESTAMP.sqlite /srv/svolos-data/database.sqlite
php artisan up
```

**That discards every write since the backup was taken** — including any account created or
invitation accepted in between. Decide that is acceptable before running it.
