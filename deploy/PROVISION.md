# Provisioning id.thijssensoftware.nl

One-time steps to bring the identity provider live on the droplet
(165.22.203.180). Subsequent releases use `scripts/deploy.sh` (see bottom).
Every step here touches production — run them yourself or explicitly hand me
SSH/DNS access.

Prerequisite: the THI-334 branch is merged to `main` so `git clone` gets the app.

## 1. DNS

Create an A record in the DNS provider for thijssensoftware.nl:

    id.thijssensoftware.nl.  A  165.22.203.180

Wait for it to resolve (`dig +short id.thijssensoftware.nl` → the droplet IP)
before requesting TLS.

## 2. App on the droplet (as `deploy`)

    git clone git@github.com:Ezomic/id.git /home/deploy/id
    cd /home/deploy/id
    cp .env.example .env

Edit `.env`:
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://id.thijssensoftware.nl`
- `php artisan key:generate`
- `DB_CONNECTION=mysql`, `DB_DATABASE=id`, plus `DB_USERNAME`/`DB_PASSWORD`
- `MAIL_*` — a real transactional mailer (login codes are emailed; without a
  working mailer, email-code sign-in is dead — passkeys still work).

Create the database first (as a DB admin):

    CREATE DATABASE id CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

Then build and initialise:

    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan passport:keys        # once; deploy.sh never rotates these
    php artisan migrate --force
    php artisan storage:link

Create the first admin (passwordless — they sign in with a code or passkey):

    php artisan id:admin <your-email> "<Your Name>"

## 3. TLS (shared cert)

On the droplet, in the infra repo, add the domain to the **seeded**
`certs/shared.domains` (do NOT hand-type the certbot `-d` list — the guarded
script exists for exactly this, see THI-309):

    echo 'id.thijssensoftware.nl' >> certs/shared.domains
    bin/renew-shared-cert.sh --dry-run   # confirm it keeps every existing SAN
    bin/renew-shared-cert.sh

## 4. nginx

    sudo cp /home/deploy/id/deploy/nginx/id.thijssensoftware.nl.conf \
            /etc/nginx/sites-available/id.thijssensoftware.nl
    # confirm the php-fpm socket path matches (ls /run/php/)
    sudo ln -s /etc/nginx/sites-available/id.thijssensoftware.nl \
               /etc/nginx/sites-enabled/
    sudo nginx -t && sudo systemctl reload nginx

## 5. Verify

    curl -sS -o /dev/null -w '%{http_code}\n' https://id.thijssensoftware.nl/login   # 200

## 6. Register client apps

For each workflow app, from `/home/deploy/id`:

    php artisan id:app "Zero" zero https://zero.thijssensoftware.nl/auth/sso/callback

Put the printed client id/secret in that app's `.env`
(`THIJSSENSOFTWARE_ID_*`) and grant users access from the admin UI or seed with
`--all-apps` on `id:admin`.

## Subsequent deploys

    bash scripts/deploy.sh --remote deploy@165.22.203.180
