# How to deploy to Ubuntu 26.04 with Caddy

This is the deployment guide for the **Svelte** build of Epimethean Challenge
(`playbymail/svolos`) on a DigitalOcean Ubuntu 26.04 server at
**https://svolos.pbbgaming.com**.

It is the same deliberately boring model used for the React build:

- The server has a plain Git working copy at `/srv/svolos`.
- Deploying is `git pull` plus `composer install`, `npm run build`, `migrate`, `optimize`.
- Node.js and npm **are** installed on the server. The Vite build happens there.
- PHP-FPM runs as the `deploy` user, so there is no `setfacl` juggling and nothing
  is owned by two users at once.
- Caddy serves `/srv/svolos/public` and terminates TLS automatically.
- The SQLite database and its backups live outside the working copy, at `/srv/svolos-data`.
- There is no `releases/` directory and no `current` symlink.

Assumptions:

| Thing | Value |
| --- | --- |
| Repository | `git@github.com:playbymail/svolos.git` |
| Domain | `svolos.pbbgaming.com` |
| OS | Ubuntu 26.04 |
| Deploy user | `deploy` (already created, has `sudo`) |
| PHP | 8.5 with PHP-FPM |
| Web server | Caddy (already installed) |
| App directory | `/srv/svolos` |
| Data directory | `/srv/svolos-data` |

**Ubuntu 26.04 ships PHP 8.5**, which is what `apt install php-fpm` gets you and what this
guide uses throughout. `composer.json` requires `php: ^8.5`, so the stock package is the
supported one and pinning an older series from a PPA is no longer an option — `composer
install` refuses 8.4 outright rather than warning.

The version appears in five places that must all agree: the apt package names (section
2.2), the pool file path and its socket (section 3), the sudoers rule (section 3.1), the
`php_fastcgi` socket in the Caddyfile (section 9), and the service the deploy script
reloads (section 10). Confirm what the box actually has before you start:

```bash
php -v
systemctl list-units --type=service 'php*-fpm.service'
```

A mismatch fails in two characteristic ways. A pool and a Caddyfile pointing at different
sockets is a 502 that looks like a code problem. A `systemctl reload` naming a service
that does not exist is `Unit php8.4-fpm.service not found` at the very end of an otherwise
successful deploy — the code is live but opcache still holds the previous build.

Everything in sections 1–10 is done **once**. After that, deploying is section 11.

---

## Why Node is installed on the server

Building assets on a Mac and shipping them with `rsync` avoids installing Node, but it
forces release directories, shared-directory symlinks, ACLs, and a two-machine checklist
for every deploy.

This project's Vite build needs Node anyway — Svelte 5, Tailwind 4, and the Wayfinder
plugin, which boots Laravel during `vite build` to generate typed route helpers.
Installing Node on the server costs about 120 MB of disk and removes all of that
machinery. The build is also reproducible: same Linux, same lockfile, every time.

The one real cost is memory. `vite build` wants roughly 1–2 GB. If the droplet has 1 GB
of RAM, add swap — see section 2.1.

---

## 1. Directory layout

```text
/srv/svolos/                 # git working copy — the whole app
├── .env                     # not in git, survives every pull
├── public/                  # Caddy's document root
│   └── build/               # vite output, not in git, rebuilt each deploy
├── storage/                 # logs, sessions, cache — not in git, survives pulls
└── scripts/deploy.sh        # the deploy script (section 10)

/srv/svolos-data/            # nothing here is ever touched by git
├── database.sqlite
└── backups/
    └── database-20260811T120000Z.sqlite
```

Files that must survive a deploy are either gitignored (`.env`, `storage/*`,
`public/build`) or live outside the working copy (the database). `git pull` never
touches any of them.

---

## 2. Install the packages the server needs

SSH in as `deploy`:

```bash
ssh deploy@svolos.pbbgaming.com
```

### 2.1 Add swap if the droplet has less than 2 GB of RAM

Check first:

```bash
free -h
```

If `Mem` total is under 2 GB and `Swap` is 0, add 2 GB of swap so `vite build` and
`composer install` do not get OOM-killed:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### 2.2 Apt packages

```bash
sudo apt update
sudo apt install -y \
    git \
    curl \
    unzip \
    sqlite3 \
    php8.5-cli \
    php8.5-fpm \
    php8.5-sqlite3 \
    php8.5-mbstring \
    php8.5-xml \
    php8.5-curl \
    php8.5-zip \
    php8.5-bcmath \
    php8.5-intl
```

Three of those are load-bearing for features this app actually ships, so do not trim the
list:

- **`php8.5-curl`** — Mailgun sends through `symfony/http-client`. Without it, every
  invitation fails to send, and invitations are the only way to create an account.
- **`php8.5-xml`** — mail rendering inlines CSS through `tijsverkoyen/css-to-inline-styles`,
  which needs `dom` and `libxml`.
- **`php8.5-mbstring`** — the QR code for two-factor enrolment comes from
  `bacon/bacon-qr-code`, which wants `iconv`/`mbstring`.

`openssl` (WebAuthn/passkeys), `fileinfo`, `ctype`, `hash`, `session` and `tokenizer` are
compiled into Ubuntu's core PHP packages and need no separate install.

Confirm the SQLite extensions are loaded:

```bash
php -m | grep -Ei 'pdo_sqlite|sqlite3'
```

Both `pdo_sqlite` and `sqlite3` must appear.

### 2.3 Composer

Install from the official installer rather than `apt install composer`, which can drag in
a second PHP version:

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php

composer --version
```

### 2.4 Node.js 24

Ubuntu's packaged Node is usually older than Vite 8 supports (it needs Node 20.19+ or
22.12+). Use NodeSource:

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs

node -v    # v24.x
npm -v
```

---

## 3. Run PHP-FPM as the deploy user

This is the step that removes all the permission complexity. Give the app its own FPM
pool that runs as `deploy`, listening on a socket the `caddy` user can reach.

```bash
sudo tee /etc/php/8.5/fpm/pool.d/svolos.conf > /dev/null <<'EOF'
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
EOF
```

The pool name, the socket file and the log file all carry `svolos` so this app can share
a droplet with the React build without either one's pool shadowing the other's.

If nothing else on this box uses the default pool, disable it:

```bash
sudo mv /etc/php/8.5/fpm/pool.d/www.conf /etc/php/8.5/fpm/pool.d/www.conf.disabled
```

Test the configuration and restart:

```bash
sudo php-fpm8.5 -t
sudo systemctl restart php8.5-fpm
```

Confirm the socket:

```bash
ls -l /run/php/php8.5-fpm-svolos.sock
```

It should read approximately:

```text
srw-rw---- 1 deploy caddy 0 ... /run/php/php8.5-fpm-svolos.sock
```

Because PHP runs as `deploy` and the entire working copy is owned by `deploy`,
`storage/` and `bootstrap/cache/` are writable with no extra work.

### 3.1 Let the deploy script reload services

The deploy script reloads PHP-FPM at the end. Allow that without a password prompt:

```bash
sudo tee /etc/sudoers.d/deploy-svolos > /dev/null <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm, /usr/bin/systemctl reload caddy
EOF

sudo chmod 0440 /etc/sudoers.d/deploy-svolos
sudo visudo -c
```

---

## 4. Create the directories

```bash
sudo mkdir -p /srv/svolos /srv/svolos-data/backups
sudo chown -R deploy:deploy /srv/svolos /srv/svolos-data
sudo chmod 0755 /srv/svolos
sudo chmod 0750 /srv/svolos-data
```

`/srv/svolos` is world-traversable so Caddy can read `public/`. `/srv/svolos-data` is not —
only `deploy` (and therefore PHP-FPM) needs it.

---

## 5. Give the server access to GitHub

Generate a passphrase-less key so `git pull` works unattended:

```bash
ssh-keygen -t ed25519 -N '' -C 'svolos.pbbgaming.com deploy' -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Add that public key to the repository at
`https://github.com/playbymail/svolos/settings/keys` as a **read-only deploy key**.

Verify:

```bash
ssh -T git@github.com
```

`Hi playbymail/svolos! You've successfully authenticated` is the expected response —
GitHub always closes the connection afterwards.

If this server already holds a deploy key for another repository, GitHub will reject the
same key on a second one. Generate a second key under a different filename and select it
per host in `~/.ssh/config`:

```sshconfig
Host github-svolos
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_svolos
    IdentitiesOnly yes
```

Then clone from `github-svolos:playbymail/svolos.git` instead of `git@github.com:...`.

---

## 6. Clone the application

```bash
git clone git@github.com:playbymail/svolos.git /srv/svolos
cd /srv/svolos
git switch main
```

---

## 7. Create the environment file and database

```bash
cd /srv/svolos
cp .env.example .env
chmod 0600 .env
nano .env
```

Set at least these values:

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

Four of those are not routine, and getting any of them wrong produces a symptom that
does not look like a configuration problem:

- **`APP_URL` must be the exact `https://` origin.** Fortify derives the WebAuthn
  relying-party ID from it, so a wrong value breaks passkey registration and sign-in
  with an error that appears to come from the browser.
- **`SESSION_DRIVER` must stay `database`.** `/admin/sessions` reads the `sessions`
  table directly to list signed-in browsers and sign them out. On the `file` driver that
  screen is silently empty and the sign-out controls do nothing.
- **`MAIL_MAILER` must be a real transport.** Registration does not exist in this
  application — `/register` returns 404 — and the only path to a new account is an
  emailed invitation. On `MAIL_MAILER=log` the site works but nobody can ever be
  onboarded, and the failure looks like the invitation feature being broken. Set
  `MAILGUN_ENDPOINT=api.eu.mailgun.net` if the Mailgun domain is in the EU region.
- **`APP_DEBUG` stays `false`.** With Inertia, a debug-mode exception renders the full
  stack trace into the page payload.

`MAILGUN_SECRET` is a credential. It belongs in this file — mode `0600`, never in git —
and nowhere else.

Create the database file:

```bash
touch /srv/svolos-data/database.sqlite
chmod 0640 /srv/svolos-data/database.sqlite
```

---

## 8. First build

```bash
cd /srv/svolos

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

php artisan key:generate

npm ci
npm run build

php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Notes:

- `composer install` runs before `npm run build` because the Wayfinder Vite plugin boots
  Laravel to generate `resources/js/actions` and `resources/js/routes`.
- **Build with `npm run build`, never a bare `php artisan wayfinder:generate`.** The Vite
  plugin is configured with `formVariants: true`; the bare artisan command omits the form
  variants, which leaves the generated helpers missing the `.form()` methods the Svelte
  pages import.
- Never run Composer with `sudo`.
- `php artisan key:generate` is a **one-time** step. `.env` is not in Git and is not
  replaced by deploys, so the key persists. Regenerating it invalidates every session and
  every encrypted value in the database — including two-factor secrets.
- **Do not run `php artisan db:seed` in production.** The only seeder that creates
  anything is `DevelopmentUserSeeder`, which seeds six accounts with publicly documented
  passwords. It guards itself — it returns early unless the environment is `local` or
  `testing` — so seeding here is a no-op rather than a hole, but there is no reason to
  run it.

Check the build landed:

```bash
test -f public/build/manifest.json && echo "vite build present"
```

---

## 9. Configure Caddy

The Caddy package does not create a log directory, and Caddy refuses to load a config
whose log file it cannot open. Create it first:

```bash
sudo mkdir -p /var/log/caddy
sudo chown caddy:caddy /var/log/caddy
sudo chmod 0755 /var/log/caddy
```

Create the directory only. Do **not** `touch` the log file: Caddy creates it as
`caddy:caddy` mode 0600 on first load, and a file pre-created by `sudo` is owned by
`root` and unopenable by the `caddy` user.

Then edit the Caddyfile:

```bash
sudo nano /etc/caddy/Caddyfile
```

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

There is no `resolve_root_symlink` here because the document root is a real directory,
not a release symlink.

Nothing special is needed for `/.well-known/passkey-endpoints`. Caddy handles only
`/.well-known/acme-challenge/*` internally; everything else under `/.well-known/` has no
matching file on disk, so `php_fastcgi` passes it to `index.php` like any other route.

Caddy rotates that log file on its own — 100 MB per file, 10 kept, 90 days — so there is
no logrotate config to write.

Validate and reload — reload, never stop:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

`caddy validate` adapts and checks the config but never opens the log file or binds a
port, so it can pass on a config that then fails to load. Always confirm the reload
itself succeeded.

Caddy requests the TLS certificate on its own once `svolos.pbbgaming.com` resolves to
this server and ports 80 and 443 are open.

### 9.1 Verify

```bash
systemctl status caddy
systemctl status php8.5-fpm

sudo -u caddy test -r /srv/svolos/public/index.php && echo "caddy can read index.php"
sudo -u caddy test -w /run/php/php8.5-fpm-svolos.sock && echo "caddy can reach php-fpm"

curl -i https://svolos.pbbgaming.com/up
curl -s https://svolos.pbbgaming.com/.well-known/passkey-endpoints
curl -o /dev/null -w '%{http_code}\n' https://svolos.pbbgaming.com/register
```

`/up` is Laravel's health endpoint and should return HTTP 200. The passkey endpoints
route should return a small JSON object with `enroll` and `manage` URLs — if it 404s,
`php artisan optimize` did not run. `/register` **must** return `404`: registration is
deliberately absent, and a 200 there would mean somebody has re-enabled
`Features::registration()` in `config/fortify.php`.

Then click through the app: the landing page, sign-in, the dashboard, and confirm CSS
and JS load from `/build/`.

---

## 10. Check the deploy script

`scripts/deploy.sh` is versioned with the code it deploys, so the clone in section 6
already brought it, executable bit included. Confirm it:

```bash
ls -l /srv/svolos/scripts/deploy.sh    # should be -rwxr-xr-x
```

If the executable bit is missing — some `core.fileMode=false` configurations drop it —
restore it once:

```bash
chmod +x /srv/svolos/scripts/deploy.sh
```

Read it before the first run. Two constants at the top must match this server:

```bash
APP_DIR=/srv/svolos
DATA_DIR=/srv/svolos-data
```

The PHP-FPM service is **not** a constant — it is derived from the running CLI PHP, so it
follows the distribution rather than being pinned to whatever was current when this guide
was written:

```bash
FPM_SERVICE="${FPM_SERVICE:-php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm}"
```

The script checks that unit exists **before** entering maintenance mode, and refuses to
start if it does not, listing the PHP-FPM units that are actually installed. That ordering
is the point: the reload is the last step of the deploy, so a wrong service name would
otherwise only surface after the migration had already run.

It also checksums itself either side of the `git pull` and stops if the pull changed it,
because the run would otherwise continue executing the version it started with — see
section 11.

Two situations need the override. If the CLI PHP and the FPM pool are different series —
unusual, but possible with a PPA — or if the unit is named something other than
`phpX.Y-fpm`, pin it explicitly:

```bash
FPM_SERVICE=php8.5-fpm /srv/svolos/scripts/deploy.sh
```

Whatever it resolves to, the sudoers rule from section 3.1 must name the **same** unit.
The rule matches the command literally, so a mismatch means the reload falls through to a
password prompt the script cannot answer.

---

## 11. Deploying a change

Push to `main`, then on the server:

```bash
ssh deploy@svolos.pbbgaming.com
/srv/svolos/scripts/deploy.sh
```

That script does the whole thing:

1. `php artisan down` — maintenance mode
2. SQLite online backup to `/srv/svolos-data/backups/` (keeps the 10 most recent)
3. `git pull --ff-only origin main`
4. `composer install --no-dev --optimize-autoloader`
5. `php artisan optimize:clear` — a stale route cache breaks the Wayfinder build
6. `npm ci && npm run build`
7. `php artisan migrate --force`
8. `php artisan optimize`
9. `sudo systemctl reload php8.5-fpm`
10. `php artisan up`

Read the error, fix it, run the script again.

### A change to the deploy script itself stops the run

Step 3 pulls the new `scripts/deploy.sh`, but the run doing the pulling is still executing
the old one — `git pull` replaces the file and the already-running shell keeps the old file
open. Left alone that is a nasty footgun: a deploy that *fixes* the deploy script still
fails on the bug it fixes, and `grep` afterwards shows the corrected file, so it reads as
the fix not having worked.

The script therefore checksums itself either side of the pull and stops if it moved:

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

The application is deliberately left **down** rather than brought back up: the pull has
already replaced the application code, so the working copy is new code against the previous
`vendor/` and `public/build`, which is not a state worth serving for the few seconds it
takes to re-run. Nothing has been installed, migrated or re-cached, so the re-run is a
normal deploy and not a recovery.

Useful overrides:

```bash
BRANCH=hotfix/urgent /srv/svolos/scripts/deploy.sh
SKIP_NPM=1 /srv/svolos/scripts/deploy.sh    # backend-only change, skips the vite build
FPM_SERVICE=php8.5-fpm /srv/svolos/scripts/deploy.sh
```

Caddy does not need reloading for a deploy. Its config never changes.

---

## 12. Minting the first administrator and letting people in

The application is invite-only and has no registration form, so a freshly deployed
instance has no accounts at all. There is exactly one supported way to create the first
one, and it works in production:

```bash
cd /srv/svolos
php artisan app:create-admin you@example.com
```

The command prompts for the password interactively so it never lands in shell history,
validates it against the same rules the rest of the application uses, and marks a newly
created administrator's email as already verified. It is idempotent: run against an
address that is already an administrator, it says so and exits without erroring; run
against an existing member, it asks for confirmation before promoting them.

Then sign in at `https://svolos.pbbgaming.com`, go to **`/admin/invitations`**, and
invite the next person. That is the only path to every subsequent account.

Two things worth knowing before the first invitation goes out:

- **Invitation tokens are stored as a sha256 hash**, and only the emailed link ever
  carries the plain text. A token therefore cannot be recovered from the database or
  re-sent as-is — "resend" issues a *new* token and invalidates the previous link. If
  mail delivery is misconfigured, the invitation is unrecoverable and must be resent
  after fixing it.
- **Accepting an invitation does not verify the email address.** Clicking a mailed link
  is not proof of control, so a new account still completes the standard verification
  flow, which needs working mail as well.

Confirm mail actually leaves the box before inviting anyone real — invite a second
address of your own first, and watch the log if it does not arrive:

```bash
tail -50 /srv/svolos/storage/logs/laravel.log
```

---

## 13. Rollback

Code first. From `/srv/svolos`:

```bash
cd /srv/svolos
git log --oneline -10
```

Pick the last good commit and deploy it:

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

### Rolling back the database

Reverting code does **not** revert a migration. If the bad deploy changed the schema,
restore the backup the deploy script took immediately beforehand:

```bash
ls -1t /srv/svolos-data/backups/
php artisan down
cp /srv/svolos-data/backups/database-TIMESTAMP.sqlite /srv/svolos-data/database.sqlite
php artisan up
```

That discards every write since the backup was taken — including any account created or
invitation accepted in between. Decide that is acceptable before running it.

---

## 14. Troubleshooting

**502 from Caddy.** PHP-FPM is down or the socket path is wrong.

```bash
ls -l /run/php/php8.5-fpm-svolos.sock
sudo journalctl -u php8.5-fpm -n 100 --no-pager
```

**`Unit php8.4-fpm.service not found` at the end of a deploy.** The PHP series on the box
is not the one being reloaded — Ubuntu 26.04 ships **8.5**. Everything before the reload
succeeded, so the new code is pulled, migrated and cached and the trap brought the
application back up; only the opcache flush is missing. Finish it by hand:

```bash
systemctl list-unit-files --no-legend 'php*-fpm.service'
sudo systemctl reload php8.5-fpm
```

If that prompts for a password, the sudoers rule still names the old service. Rewrite it
with the correct one (section 3.1) and re-run.

Current versions of `scripts/deploy.sh` derive the service from the running PHP and refuse
to start when the unit does not exist, so this only bites a working copy that predates that
change. Such a working copy also predates the self-check described in section 11, so the
deploy that *pulls* the fix fails on the very bug it fixes — run the script a second time
before concluding the fix did not work. From then on the self-check makes that explicit
instead of leaving you to work it out.

**500 with a blank page.** Check the application log — `APP_DEBUG` is `false`, so the
browser will not show you anything.

```bash
tail -50 /srv/svolos/storage/logs/laravel.log
tail -50 /var/log/php8.5-fpm-svolos.log
```

**"Unable to locate file in Vite manifest".** The build did not run or did not finish.
With Inertia this takes down the whole page, not one asset. Re-run it and watch for an
OOM kill:

```bash
cd /srv/svolos && npm run build
dmesg | tail -20
```

**A page 500s only for one route, right after a deploy.** Usually a stale cache from a
partial run. Clear and rebuild:

```bash
cd /srv/svolos && php artisan optimize:clear && php artisan optimize
```

**"Database file does not exist" or "readonly database".** The path in `.env` must be
absolute (`/srv/svolos-data/database.sqlite`) and the file plus its directory must be
owned by `deploy`.

```bash
ls -l /srv/svolos-data/
php artisan config:clear
```

**Invitations never arrive.** Check the transport first, then the credentials:

```bash
cd /srv/svolos
php artisan config:show mail.default
php artisan config:show services.mailgun.domain
tail -50 storage/logs/laravel.log
```

`mail.default` returning `log` means `.env` was never switched to `mailgun`, or
`php artisan optimize` cached the config before it was. A Mailgun 401 in the log means
`MAILGUN_SECRET` is wrong; a 404 usually means `MAILGUN_DOMAIN` is wrong or the domain
is in the EU region and `MAILGUN_ENDPOINT` still points at `api.mailgun.net`.

**Passkey registration fails in the browser.** `APP_URL` does not exactly match the
origin being visited. It must be `https://svolos.pbbgaming.com` — no trailing slash, no
`http://`, no bare domain.

```bash
php artisan config:show app.url
```

**`/admin/sessions` is empty even though people are signed in.** `SESSION_DRIVER` is not
`database`. Fix `.env`, then `php artisan optimize:clear && php artisan optimize`.
Existing sessions do not migrate between drivers; everyone signs in again.

**`git pull` refuses to fast-forward.** Something on the server modified a tracked file.
Find it and discard it:

```bash
cd /srv/svolos && git status
git checkout -- <file>
```

**`systemctl reload caddy` fails.** Get the full error — journalctl truncates lines at
the terminal width, and the useful half is on the right:

```bash
sudo journalctl -u caddy -n 5 --no-pager -o cat
```

`setting up custom log 'log0': ... no such file or directory` means `/var/log/caddy` is
missing; create it as shown at the top of section 9.

The same error ending in `permission denied` means the directory exists but the log
**file** does not belong to `caddy` — almost always because it was created with
`sudo touch`. Check the file, not just the directory, and let Caddy make its own:

```bash
sudo ls -la /var/log/caddy
sudo rm -f /var/log/caddy/svolos.pbbgaming.com.log
sudo systemctl reload caddy
```

A failed reload leaves the previously loaded config running, so the site stays up while
you fix it.

**Caddy will not issue a certificate.** DNS is not pointing here yet, or 80/443 are
blocked.

```bash
dig +short svolos.pbbgaming.com
sudo journalctl -u caddy -n 100 --no-pager
```

---

## 15. What the server has

```text
git          php8.5-cli / php8.5-fpm     composer
curl         php8.5-sqlite3              node 24 / npm
unzip        php8.5-mbstring             caddy
sqlite3      php8.5-xml, curl, zip, bcmath, intl
```

There are **no queue workers and no scheduler entry**, and that is correct for this
application as it stands: `QUEUE_CONNECTION=database` is configured, but nothing is
dispatched to it. `InvitationNotification` extends `Notification` rather than
`ShouldQueue`, so invitation mail is sent inside the request that creates it — which is
also why a slow or unreachable Mailgun shows up as a slow `/admin/invitations` POST
rather than as mail that silently never arrives.

The moment anything implements `ShouldQueue`, or a `Schedule::` entry appears in
`routes/console.php`, this stops being true. Add the systemd unit or the cron entry then,
and have `scripts/deploy.sh` restart the worker after `php artisan optimize` — a worker
started before a deploy keeps running the old code until it is restarted.

---

## 16. References

- Laravel deployment — https://laravel.com/docs/13.x/deployment
- Laravel Vite — https://laravel.com/docs/13.x/vite
- Laravel Wayfinder — https://github.com/laravel/wayfinder
- Laravel Fortify — https://laravel.com/docs/13.x/fortify
- Mailgun driver configuration — https://laravel.com/docs/13.x/mail#mailgun-driver
- Caddy `php_fastcgi` — https://caddyserver.com/docs/caddyfile/directives/php_fastcgi
- Caddy service user — https://caddyserver.com/docs/running
- PHP-FPM pool configuration — https://www.php.net/manual/en/install.fpm.configuration.php
