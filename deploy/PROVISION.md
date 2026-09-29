# Provisioning id.thijssensoftware.nl

The server is not set up from this repo. id is one entry in Ezomic/infra's
`ansible/group_vars/all/apps.yml` (SQLite, scheduler timer), and that repo's
`docs/provisioning.md` covers the rest: the `id` Linux user and its php-fpm
master, the nginx vhost, the certificate, DNS, backups and `app-deploy`. Change
the server there, never by hand on the box.

What follows is what id itself needs once on a fresh box, after the infra run.

## Environment

`/home/id/shared/.env` is linked into every release. Start from `.env.example`
and set at least:

- `APP_ENV=production`, `APP_DEBUG=false`, and a fresh `APP_KEY`.
- `APP_URL=https://id.thijssensoftware.nl`. ID accepts only this host name
  (ID-98), so a wrong value turns every request away.
- `DB_CONNECTION=sqlite` and
  `DB_DATABASE=/home/id/shared/database/database.sqlite`, in place of the
  MySQL settings `.env.example` ships. id uses no MySQL anywhere.
- A working `MAIL_*` mailer. Sign-in codes are emailed, so without one only
  passkeys and recovery codes work.

`QUEUE_CONNECTION` stays `sync`: nothing runs a queue worker for id.

## First deploy and one-time setup

As the app user:

    sudo -iu id
    app-deploy
    cd current
    php artisan passport:keys

`passport:keys` writes into `storage/`, which lives in `shared/` and survives
every release. Run it once: new keys invalidate every access token the apps
hold.

Register each app as an OAuth client, and put the client id and secret it
prints in that app's `.env`:

    php artisan id:app "Zero" zero https://zero.thijssensoftware.nl/auth/sso/callback

Then create the first administrator, who signs in with an emailed code or a
passkey. `--all-apps` grants access to every app registered so far:

    php artisan id:admin <email> "<Name>" --all-apps

## Later deploys

Run the "Deploy to production" workflow, or `sudo -iu id app-deploy` on the box.
`app-deploy status` shows what is live, and `app-deploy rollback` goes back to
the previous release.
