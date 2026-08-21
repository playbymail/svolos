# How to set up a production server

This guide shows you how to take a fresh Ubuntu 26.04 droplet to a running installation of
Epimethean Challenge, served by Caddy over HTTPS.

Everything here is done **once** per server. Afterwards, see
[how to deploy a change](deploy-a-change.md).

It assumes a `deploy` user with `sudo` already exists, and that DNS for the domain either points at
this droplet or will before you reach the Caddy step. For the finished arrangement — paths, owners,
config file contents — see [the production server reference](../reference/production-server.md).
For why it is built this way, see `.ai/rules/deployment.md`.

---

## 1. Confirm the PHP series first

The PHP version has to agree in five places, and a mismatch produces failures that look like
something else entirely. Find out what the box actually has before you install anything:

```bash
ssh deploy@svolos.pbbgaming.com

php -v
systemctl list-units --type=service 'php*-fpm.service'
```

Ubuntu 26.04 ships PHP 8.5, which is what `apt install php-fpm` gets you and what this guide uses
throughout. `composer.json` requires `php: ^8.5`, so the stock package is the supported one. If the
box has something else, substitute it consistently in every command below, and see
[the troubleshooting guide](troubleshoot-a-deployment.md) for what a mismatch looks like.

## 2. Add swap if the droplet has less than 2 GB of RAM

`vite build` wants roughly 1–2 GB. Check first:

```bash
free -h
```

If `Mem` total is under 2 GB and `Swap` is 0, add 2 GB so `vite build` and `composer install` are
not OOM-killed:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 3. Install the packages

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

Do not trim that list. Four of those extensions are required by features this application ships,
and [the reference](../reference/production-server.md#packages) says which.

Confirm the SQLite extensions loaded — both must appear:

```bash
php -m | grep -Ei 'pdo_sqlite|sqlite3'
```

Install Composer from the official installer rather than `apt install composer`, which can drag in
a second PHP version:

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php

composer --version
```

Install Node from NodeSource, because Ubuntu's packaged Node is usually older than Vite 8 supports:

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt install -y nodejs

node -v    # v24.x
npm -v
```

## 4. Give the application its own PHP-FPM pool

Run it as `deploy`, listening on a socket the `caddy` user can reach:

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

If nothing else on this box uses the default pool, disable it:

```bash
sudo mv /etc/php/8.5/fpm/pool.d/www.conf /etc/php/8.5/fpm/pool.d/www.conf.disabled
```

Test the configuration, restart, and confirm the socket:

```bash
sudo php-fpm8.5 -t
sudo systemctl restart php8.5-fpm
ls -l /run/php/php8.5-fpm-svolos.sock
```

The socket should read approximately:

```text
srw-rw---- 1 deploy caddy 0 ... /run/php/php8.5-fpm-svolos.sock
```

Then let the deploy script reload services without a password prompt:

```bash
sudo tee /etc/sudoers.d/deploy-svolos > /dev/null <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.5-fpm, /usr/bin/systemctl reload caddy
EOF

sudo chmod 0440 /etc/sudoers.d/deploy-svolos
sudo visudo -c
```

The rule matches the command literally. Whatever unit `scripts/deploy.sh` ends up reloading, name
that same unit here, or the reload falls through to a prompt the script cannot answer.

## 5. Create the directories

```bash
sudo mkdir -p /srv/svolos /srv/svolos-data/backups
sudo chown -R deploy:deploy /srv/svolos /srv/svolos-data
sudo chmod 0755 /srv/svolos
sudo chmod 0750 /srv/svolos-data
```

## 6. Give the server read access to GitHub

Generate a passphrase-less key so `git pull` works unattended:

```bash
ssh-keygen -t ed25519 -N '' -C 'svolos.pbbgaming.com deploy' -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

Add that public key at `https://github.com/playbymail/svolos/settings/keys` as a **read-only deploy
key**, then verify:

```bash
ssh -T git@github.com
```

`Hi playbymail/svolos! You've successfully authenticated` is the expected response — GitHub always
closes the connection afterwards.

**If this server already holds a deploy key for another repository**, GitHub rejects the same key on
a second one. Generate a second key under a different filename and select it per host in
`~/.ssh/config`:

```sshconfig
Host github-svolos
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_svolos
    IdentitiesOnly yes
```

Then clone from `github-svolos:playbymail/svolos.git` instead of `git@github.com:...` below.

## 7. Clone the application

```bash
git clone git@github.com:playbymail/svolos.git /srv/svolos
cd /srv/svolos
git switch main
```

## 8. Write the environment file and create the database

```bash
cd /srv/svolos
cp .env.example .env
chmod 0600 .env
nano .env
```

Set the values listed in
[the reference](../reference/production-server.md#environment). Five of them will bite you later if
they are wrong, in ways that do not look like configuration problems — `APP_URL`, `APP_DEBUG`,
`SESSION_DRIVER`, `MAIL_MAILER` and, once generated, `APP_KEY`. Read that table before saving.

Leave `APP_KEY` empty; the next step generates it.

Create the database file:

```bash
touch /srv/svolos-data/database.sqlite
chmod 0640 /srv/svolos-data/database.sqlite
```

## 9. Build

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

The order matters and three of those steps have a trap in them:

- **`composer install` runs before `npm run build`**, because the Wayfinder Vite plugin boots
  Laravel to generate `resources/js/actions` and `resources/js/routes`.
- **Build with `npm run build`, never a bare `php artisan wayfinder:generate`.** The Vite plugin is
  configured with `formVariants: true`; the bare artisan command omits them, leaving the generated
  helpers without the `.form()` methods the Svelte pages import.
- **`php artisan key:generate` is a one-time step.** Regenerating it later invalidates every session
  and every encrypted value in the database, including two-factor secrets.

Never run Composer with `sudo`, and do not run `php artisan db:seed` — see
`.ai/rules/seeders.md`.

Check the build landed:

```bash
test -f public/build/manifest.json && echo "vite build present"
```

## 10. Configure Caddy

Create the log **directory** first. Caddy refuses to load a config whose log file it cannot open,
and the package does not create one:

```bash
sudo mkdir -p /var/log/caddy
sudo chown caddy:caddy /var/log/caddy
sudo chmod 0755 /var/log/caddy
```

Do **not** `touch` the log file. Caddy creates it as `caddy:caddy` mode 0600 on first load, and a
file pre-created with `sudo` is owned by `root` and unopenable by the `caddy` user.

Then write the Caddyfile:

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

Validate and reload — reload, never stop:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl reload caddy
```

`caddy validate` adapts and checks the config but never opens the log file or binds a port, so it
can pass on a config that then fails to load. Confirm the reload itself succeeded rather than
trusting the validation.

Caddy requests the TLS certificate on its own once the domain resolves to this server and ports 80
and 443 are open.

## 11. Verify

```bash
systemctl status caddy
systemctl status php8.5-fpm

sudo -u caddy test -r /srv/svolos/public/index.php && echo "caddy can read index.php"
sudo -u caddy test -w /run/php/php8.5-fpm-svolos.sock && echo "caddy can reach php-fpm"

curl -i https://svolos.pbbgaming.com/up
curl -s https://svolos.pbbgaming.com/.well-known/passkey-endpoints
curl -o /dev/null -w '%{http_code}\n' https://svolos.pbbgaming.com/register
```

[The reference](../reference/production-server.md#verification-endpoints) says what each of those
three requests must return, and what a wrong answer means. Then click through the app: the landing
page, sign-in, the dashboard, and confirm CSS and JS load from `/build/`.

## 12. Check the deploy script before its first run

`scripts/deploy.sh` came with the clone, executable bit included:

```bash
ls -l /srv/svolos/scripts/deploy.sh    # should be -rwxr-xr-x
```

If the executable bit is missing — some `core.fileMode=false` configurations drop it — restore it
once:

```bash
chmod +x /srv/svolos/scripts/deploy.sh
```

Read it before running it, and check that `APP_DIR` and `DATA_DIR` at the top match this server. The
PHP-FPM unit it reloads is derived from the running CLI PHP rather than pinned, which is usually
right. Pin it explicitly if the CLI PHP and the FPM pool are different series, or if the unit is
named something other than `phpX.Y-fpm`:

```bash
FPM_SERVICE=php8.5-fpm /srv/svolos/scripts/deploy.sh
```

Whatever it resolves to must be the unit named in the sudoers rule from step 4.

## Next

The installation has no accounts at all. See
[how to create the first administrator](create-the-first-administrator.md).
