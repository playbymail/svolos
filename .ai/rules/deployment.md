# Deployment

Globs: `scripts/deploy.sh`, `docs/how-to/set-up-a-production-server.md`,
`docs/how-to/deploy-a-change.md`, `docs/how-to/troubleshoot-a-deployment.md`,
`docs/how-to/create-the-first-administrator.md`, `docs/reference/production-server.md`

The production installation is at `https://svolos.pbbgaming.com`. What it *is* — paths, owners,
config file contents, the deploy script's steps — is described in
[`docs/reference/production-server.md`](../../docs/reference/production-server.md), and the
procedures are the four how-to guides listed above. This file is why it is arranged that way.

## The deployment model is deliberately boring

`/srv/svolos` is a plain Git working copy, and deploying is `git pull` plus `composer install`,
`npm run build`, `migrate` and `optimize`. There is no `releases/` directory, no `current` symlink
and no shared-directory machinery.

That machinery exists to solve a problem this arrangement does not have. Every file that must
survive a deploy is either gitignored — `.env`, `storage/*`, `public/build` — or lives outside the
working copy entirely, at `/srv/svolos-data`. `git pull` cannot touch any of them, so there is
nothing for symlinks to keep in place across a release boundary.

The cost is a maintenance window of a few seconds rather than an atomic switch. For a play-by-mail
game whose turns advance on the scale of days, that is not a trade worth machinery.

## Node is installed on the server, and the build happens there

Building assets on a Mac and shipping them with `rsync` avoids installing Node, but it buys that
with release directories, shared-directory symlinks, ACLs, and a two-machine checklist for every
deploy.

The Vite build needs Node anyway — Svelte 5, Tailwind 4, and the Wayfinder plugin, which boots
Laravel during `vite build` to generate typed route helpers. Installing Node on the server costs
about 120 MB of disk and removes all of that machinery, and the build is reproducible besides: same
Linux, same lockfile, every time.

The one real cost is memory. `vite build` wants roughly 1–2 GB, which is why a droplet under 2 GB
needs swap before the first build rather than after the first OOM kill.

## PHP-FPM runs as `deploy`, in a pool of its own

The application gets its own FPM pool running as `deploy`, listening on a socket the `caddy` user
can reach. Because PHP runs as the user that owns the whole working copy, `storage/` and
`bootstrap/cache/` are writable with no `setfacl` juggling and nothing is owned by two users at
once. That single decision is what removes the permission complexity a deployment guide usually
carries.

The pool name, the socket file and the log file all carry `svolos` so this application can share a
droplet with the React build without either one's pool shadowing the other's.

## The PHP series has to agree in five places

The apt package names, the pool file path and its socket, the sudoers rule, the `php_fastcgi`
socket in the Caddyfile, and the service `scripts/deploy.sh` reloads. Ubuntu 26.04 ships PHP 8.5,
`composer.json` requires `php: ^8.5`, and pinning an older series from a PPA is not an option —
`composer install` refuses 8.4 outright rather than warning.

A mismatch fails in two characteristic ways, and neither one names the real cause:

- **A pool and a Caddyfile pointing at different sockets is a 502** that reads like a code problem.
- **A `systemctl reload` naming a service that does not exist** is `Unit php8.4-fpm.service not
  found` at the very end of an otherwise successful deploy. The code is live, but opcache still
  holds the previous build.

This is why `scripts/deploy.sh` derives `FPM_SERVICE` from the running CLI PHP instead of pinning
it, and why it checks that unit exists **before** entering maintenance mode. The reload is the last
step, so a wrong name would otherwise surface only after the migration had already run.

## The deploy script checksums itself, and leaves the application down when it changed

`git pull` replaces `scripts/deploy.sh`, but the shell running the deploy keeps the file it opened.
Left alone that is a nasty footgun: a deploy that *fixes* the deploy script still fails on the bug
it fixes, and `grep` afterwards shows the corrected file, so it reads as the fix not having worked.

The script therefore checksums itself either side of the pull and stops if it moved. It stops
**without** bringing the application back up, which is deliberate: the pull has already replaced the
application code, so the working copy is new code against the previous `vendor/` and
`public/build`. That is not a state worth serving for the few seconds it takes to re-run. Nothing
has been installed, migrated or re-cached, so the second run is a normal deploy and not a recovery.

Every other failure does the opposite — the trap brings the application back up, so a failed deploy
leaves the previous code serving.

## The five environment values that fail as something else

Each of these is configuration whose symptom points somewhere other than `.env`, which is why the
reference tables them and the setup guide sends the reader there before saving the file:

- **`APP_URL`** must be the exact `https://` origin. Fortify derives the WebAuthn relying-party ID
  from it, so a wrong value breaks passkey registration with an error that appears to come from the
  browser.
- **`SESSION_DRIVER` must stay `database`.** `/admin/sessions` reads the `sessions` table directly
  to list signed-in browsers and sign them out — see [sessions.md](sessions.md). On the `file`
  driver that screen is silently empty and the sign-out controls do nothing.
- **`MAIL_MAILER` must be a real transport.** Registration does not exist here (see
  [auth.md](auth.md)) and the only path to an account is an emailed invitation. On
  `MAIL_MAILER=log` the site works but nobody can ever be onboarded, and it looks like the
  invitation feature being broken rather than like mail configuration.
- **`APP_DEBUG` stays `false`.** With Inertia, a debug-mode exception renders the full stack trace
  into the page payload.
- **`APP_KEY` is generated once.** `.env` is not in Git and is not replaced by a deploy, so the key
  persists; regenerating it invalidates every session and every encrypted value in the database,
  including two-factor secrets.

## There are no queue workers and no scheduler, and that is currently correct

`QUEUE_CONNECTION=database` is configured but nothing is dispatched to it.
`InvitationNotification` extends `Notification` rather than `ShouldQueue`, so invitation mail is
sent inside the request that creates it — which is also why a slow or unreachable Mailgun shows up
as a slow `/admin/invitations` POST rather than as mail that silently never arrives.

The moment anything implements `ShouldQueue`, or a `Schedule::` entry appears in
`routes/console.php`, this stops being true. Add the systemd unit or the cron entry then, and have
`scripts/deploy.sh` restart the worker after `php artisan optimize` — a worker started before a
deploy keeps running the old code until it is restarted.

## The database backup is an online backup, not a copy

`scripts/deploy.sh` backs up with `sqlite3 .backup` before it pulls. SQLite runs in WAL here, and a
WAL database cannot be backed up by copying the file — the recent commits live in the `-wal`. See
the Databases section of [general.md](general.md), which is where that decision is recorded.

The ten most recent backups are kept, and a rollback restores one of them by hand. Reverting code
does not revert a migration.
