# How to troubleshoot a deployment

This guide is organised by symptom. Find the one you are looking at, and follow it.

For what the healthy installation looks like — paths, owners, config contents — see
[the production server reference](../reference/production-server.md).

---

## 502 from Caddy

PHP-FPM is down, or the socket path is wrong.

```bash
ls -l /run/php/php8.5-fpm-svolos.sock
sudo journalctl -u php8.5-fpm -n 100 --no-pager
```

The pool's socket and the Caddyfile's `php_fastcgi` must name the same path. A mismatch between
them is a 502 that reads like a code problem.

## `Unit php8.4-fpm.service not found` at the end of a deploy

The PHP series on the box is not the one being reloaded — Ubuntu 26.04 ships **8.5**.

Everything before the reload succeeded, so the new code is pulled, migrated and cached, and the
script's trap brought the application back up. Only the opcache flush is missing. Finish it by hand:

```bash
systemctl list-unit-files --no-legend 'php*-fpm.service'
sudo systemctl reload php8.5-fpm
```

If that prompts for a password, the sudoers rule still names the old service. Rewrite
`/etc/sudoers.d/deploy-svolos` with the correct unit and re-run.

Current versions of `scripts/deploy.sh` derive the service from the running PHP and refuse to start
when the unit does not exist, so this only bites a working copy that predates that change. Such a
working copy also predates the script's self-check, which means the deploy that *pulls* the fix
fails on the very bug it fixes — **run the script a second time before concluding the fix did not
work.**

## 500 with a blank page

`APP_DEBUG` is `false`, so the browser will not show you anything. Read the logs:

```bash
tail -50 /srv/svolos/storage/logs/laravel.log
tail -50 /var/log/php8.5-fpm-svolos.log
```

## "Unable to locate file in Vite manifest"

The build did not run, or did not finish. With Inertia this takes down the whole page rather than
one asset. Re-run it and watch for an OOM kill:

```bash
cd /srv/svolos && npm run build
dmesg | tail -20
```

If it was OOM-killed, the droplet needs swap — see
[step 2 of the setup guide](set-up-a-production-server.md#2-add-swap-if-the-droplet-has-less-than-2-gb-of-ram).

## A page 500s only for one route, right after a deploy

Usually a stale cache from a partial run. Clear and rebuild:

```bash
cd /srv/svolos && php artisan optimize:clear && php artisan optimize
```

## "Database file does not exist" or "readonly database"

The path in `.env` must be absolute (`/srv/svolos-data/database.sqlite`), and the file **and its
directory** must be owned by `deploy` — SQLite in WAL mode writes `-wal` and `-shm` files beside the
database.

```bash
ls -l /srv/svolos-data/
php artisan config:clear
```

## Invitations never arrive

Check the transport first, then the credentials:

```bash
cd /srv/svolos
php artisan config:show mail.default
php artisan config:show services.mailgun.domain
tail -50 storage/logs/laravel.log
```

| What you see | What it means |
| --- | --- |
| `mail.default` is `log` | `.env` was never switched to `mailgun`, or `php artisan optimize` cached the config before it was. |
| Mailgun 401 in the log | `MAILGUN_SECRET` is wrong. |
| Mailgun 404 in the log | `MAILGUN_DOMAIN` is wrong, or the domain is in the EU region and `MAILGUN_ENDPOINT` still points at `api.mailgun.net`. |

## Passkey registration fails in the browser

`APP_URL` does not exactly match the origin being visited. It must be
`https://svolos.pbbgaming.com` — no trailing slash, no `http://`, no bare domain.

```bash
php artisan config:show app.url
```

## `/admin/sessions` is empty even though people are signed in

`SESSION_DRIVER` is not `database`. Fix `.env`, then:

```bash
php artisan optimize:clear && php artisan optimize
```

Existing sessions do not migrate between drivers; everyone signs in again.

## `git pull` refuses to fast-forward

Something on the server modified a tracked file. Find it and discard it:

```bash
cd /srv/svolos && git status
git checkout -- <file>
```

## `systemctl reload caddy` fails

Get the full error first — journalctl truncates lines at the terminal width, and the useful half is
on the right:

```bash
sudo journalctl -u caddy -n 5 --no-pager -o cat
```

**`setting up custom log 'log0': ... no such file or directory`** means `/var/log/caddy` is missing.
Create it as in
[step 10 of the setup guide](set-up-a-production-server.md#10-configure-caddy).

**The same error ending in `permission denied`** means the directory exists but the log *file* does
not belong to `caddy` — almost always because it was created with `sudo touch`. Check the file, not
just the directory, and let Caddy make its own:

```bash
sudo ls -la /var/log/caddy
sudo rm -f /var/log/caddy/svolos.pbbgaming.com.log
sudo systemctl reload caddy
```

A failed reload leaves the previously loaded config running, so the site stays up while you fix it.

## Caddy will not issue a certificate

DNS is not pointing here yet, or 80/443 are blocked.

```bash
dig +short svolos.pbbgaming.com
sudo journalctl -u caddy -n 100 --no-pager
```
