# Production server

The production installation of Epimethean Challenge: what is on the server, where it lives, and
what each piece is configured with.

This document describes the installation. For building one, see
[how to set up a production server](../how-to/set-up-a-production-server.md); for the reasoning
behind the arrangement, see `.ai/rules/deployment.md` in this repository.

---

## The installation

| Thing | Value |
| --- | --- |
| Repository | `git@github.com:playbymail/svolos.git` |
| Domain | `svolos.pbbgaming.com` |
| Host | DigitalOcean droplet |
| OS | Ubuntu 26.04 |
| Deploy user | `deploy`, with `sudo` |
| PHP | 8.5 with PHP-FPM |
| Web server | Caddy |
| Application directory | `/srv/svolos` |
| Data directory | `/srv/svolos-data` |

There is no `releases/` directory and no `current` symlink. `/srv/svolos` is a plain Git working
copy of `main`, and a deploy updates it in place.

## Directory layout

```text
/srv/svolos/                 # git working copy — the whole app
├── .env                     # not in git, survives every pull
├── public/                  # Caddy's document root
│   └── build/               # vite output, not in git, rebuilt each deploy
├── storage/                 # logs, sessions, cache — not in git, survives pulls
└── scripts/deploy.sh        # the deploy script

/srv/svolos-data/            # nothing here is ever touched by git
├── database.sqlite
└── backups/
    └── database-20260811T120000Z.sqlite
```

Every file that must survive a deploy is either gitignored (`.env`, `storage/*`, `public/build`) or
lives outside the working copy. `git pull` touches none of them.

| Path | Owner | Mode |
| --- | --- | --- |
| `/srv/svolos` | `deploy:deploy` | `0755` — world-traversable, so Caddy can read `public/` |
| `/srv/svolos-data` | `deploy:deploy` | `0750` — only `deploy`, and therefore PHP-FPM, needs it |
| `/srv/svolos-data/database.sqlite` | `deploy:deploy` | `0640` |
| `/srv/svolos/.env` | `deploy:deploy` | `0600` |

## Packages

```text
git          php8.5-cli / php8.5-fpm     composer
curl         php8.5-sqlite3              node 24 / npm
unzip        php8.5-mbstring             caddy
sqlite3      php8.5-xml, curl, zip, bcmath, intl
```

Composer is installed from `getcomposer.org`, not from apt. Node comes from NodeSource rather than
Ubuntu's archive, which carries a version older than Vite 8 supports (it needs Node 20.19+ or
22.12+).

Four PHP extensions are required by features this application ships:

| Extension | Required by |
| --- | --- |
| `php8.5-sqlite3` | The database. Both `pdo_sqlite` and `sqlite3` must appear in `php -m`. |
| `php8.5-curl` | Mailgun delivery through `symfony/http-client`. |
| `php8.5-xml` | Mail rendering: `tijsverkoyen/css-to-inline-styles` needs `dom` and `libxml`. |
| `php8.5-mbstring` | Two-factor enrolment QR codes from `bacon/bacon-qr-code`, which wants `iconv`/`mbstring`. |

`openssl` (WebAuthn/passkeys), `fileinfo`, `ctype`, `hash`, `session` and `tokenizer` are compiled
into Ubuntu's core PHP packages and need no separate install.

### Not installed

There are **no queue workers and no scheduler entry**. `QUEUE_CONNECTION=database` is configured,
but nothing is dispatched to it: `InvitationNotification` extends `Notification` rather than
`ShouldQueue`, so invitation mail is sent inside the request that creates it.

## PHP-FPM

The application has its own pool, at `/etc/php/8.5/fpm/pool.d/svolos.conf`:

```ini
[svolos]
user = deploy
group = deploy

listen = /run/php/php8.5-fpm-svolos.sock
listen.owner = deploy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/php8.5-fpm-svolos.log
php_admin_value[memory_limit] = 256M
```

| Item | Value |
| --- | --- |
| Pool name | `svolos` |
| Socket | `/run/php/php8.5-fpm-svolos.sock`, `deploy:caddy`, mode `0660` |
| Error log | `/var/log/php8.5-fpm-svolos.log` |
| Service | `php8.5-fpm` |

The default `www` pool is disabled on this box, at
`/etc/php/8.5/fpm/pool.d/www.conf.disabled`.

The PHP series appears in five places that must all name the same version: the apt package names,
the pool file path and its socket, the sudoers rule, the `php_fastcgi` socket in the Caddyfile, and
the service `scripts/deploy.sh` reloads.

### Sudoers

`/etc/sudoers.d/deploy-svolos`, mode `0440`:

```text
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm, /usr/bin/systemctl reload caddy
```

The rule matches the command literally, so the unit named here must be the same one
`scripts/deploy.sh` resolves.

## Caddy

`/etc/caddy/Caddyfile`:

```caddyfile
svolos.pbbgaming.com {
	root /srv/svolos/public

	encode zstd gzip

	php_fastcgi unix//run/php/php8.5-fpm-svolos.sock

	file_server

	header {
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		X-Content-Type-Options "nosniff"
		X-Frame-Options "SAMEORIGIN"
		Referrer-Policy "strict-origin-when-cross-origin"
	}

	log {
		output file /var/log/caddy/svolos.pbbgaming.com.log
	}
}
```

There is no `resolve_root_symlink` because the document root is a real directory.

| Item | Value |
| --- | --- |
| Log directory | `/var/log/caddy`, owned `caddy:caddy`, mode `0755` |
| Log file | Created by Caddy itself as `caddy:caddy` mode `0600` on first load |
| Rotation | Caddy's own: 100 MB per file, 10 kept, 90 days. No logrotate config. |
| TLS | Issued automatically once the domain resolves here and ports 80 and 443 are open |

Nothing is configured for `/.well-known/passkey-endpoints`. Caddy handles only
`/.well-known/acme-challenge/*` internally; everything else under `/.well-known/` has no matching
file on disk, so `php_fastcgi` passes it to `index.php` like any other route.

Caddy's config never changes between deploys, so a deploy does not reload it.

## Environment

`/srv/svolos/.env` is not in Git and is not replaced by a deploy. The production values:

```dotenv
APP_NAME="Epimethean Challenge"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://svolos.pbbgaming.com

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/srv/svolos-data/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=mailgun
MAIL_FROM_ADDRESS="no-reply@pbbgaming.com"
MAIL_FROM_NAME="${APP_NAME}"
MAILGUN_DOMAIN=mg.pbbgaming.com
MAILGUN_SECRET=key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
MAILGUN_ENDPOINT=api.mailgun.net

VITE_APP_NAME="${APP_NAME}"
```

Five values carry more weight than their names suggest:

| Value | What reads it |
| --- | --- |
| `APP_KEY` | Every session and every encrypted column, including two-factor secrets. Generated once, at first build; regenerating it invalidates all of them. |
| `APP_URL` | Fortify derives the WebAuthn relying-party ID from it. Must be the exact `https://` origin — no trailing slash, no bare domain. |
| `APP_DEBUG` | With Inertia, a debug-mode exception renders the full stack trace into the page payload. Stays `false`. |
| `SESSION_DRIVER` | `/admin/sessions` reads the `sessions` table directly to list signed-in browsers and sign them out. Must stay `database`. |
| `MAIL_MAILER` | The only path to a new account is an emailed invitation; `/register` returns 404. Must be a real transport. |

`MAILGUN_ENDPOINT` is `api.eu.mailgun.net` when the Mailgun domain is in the EU region.
`MAILGUN_SECRET` is a credential: it belongs in this file, mode `0600`, and nowhere else.

## `scripts/deploy.sh`

Versioned with the code it deploys, so a clone brings it. It runs, in order:

1. `php artisan down` — maintenance mode
2. SQLite online backup to `/srv/svolos-data/backups/`, keeping the 10 most recent
3. `git pull --ff-only origin main`
4. `composer install --no-dev --optimize-autoloader`
5. `php artisan optimize:clear`
6. `npm ci && npm run build`
7. `php artisan migrate --force`
8. `php artisan optimize`
9. `sudo systemctl reload php8.5-fpm`
10. `php artisan up`

Two constants at the top of the file must match the server:

```bash
APP_DIR=/srv/svolos
DATA_DIR=/srv/svolos-data
```

The PHP-FPM service is not a constant. It is derived from the running CLI PHP:

```bash
FPM_SERVICE="${FPM_SERVICE:-php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm}"
```

**Guards**

- The script checks the FPM unit exists **before** entering maintenance mode, and refuses to start
  if it does not, listing the PHP-FPM units that are installed.
- It checksums itself either side of the `git pull` and stops if the pull changed it, leaving the
  application in maintenance mode.
- If any step fails it stops and brings the application back up, so a failed deploy leaves the
  previous code running.

**Environment overrides**

| Variable | Effect |
| --- | --- |
| `BRANCH` | Deploy a branch other than `main`. |
| `SKIP_NPM` | Skip the Vite build. For a backend-only change. |
| `FPM_SERVICE` | Pin the unit to reload instead of deriving it. |

## Verification endpoints

| Request | Expected |
| --- | --- |
| `GET /up` | `200`. Laravel's health endpoint. |
| `GET /.well-known/passkey-endpoints` | A small JSON object with `enroll` and `manage` URLs. A 404 means `php artisan optimize` did not run. |
| `GET /register` | `404`. Registration is deliberately absent; a 200 means `Features::registration()` has been re-enabled in `config/fortify.php`. |

## External references

- Laravel deployment — https://laravel.com/docs/13.x/deployment
- Laravel Vite — https://laravel.com/docs/13.x/vite
- Laravel Wayfinder — https://github.com/laravel/wayfinder
- Laravel Fortify — https://laravel.com/docs/13.x/fortify
- Mailgun driver configuration — https://laravel.com/docs/13.x/mail#mailgun-driver
- Caddy `php_fastcgi` — https://caddyserver.com/docs/caddyfile/directives/php_fastcgi
- Caddy service user — https://caddyserver.com/docs/running
- PHP-FPM pool configuration — https://www.php.net/manual/en/install.fpm.configuration.php
