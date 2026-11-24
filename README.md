# Pedalya

## Render + Aiven MySQL deployment

The root `render.yaml` deploys a Docker web service compatible with Aiven
MySQL. Configure all database values in Render's secret environment-variable
editor: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and
the base64-encoded Aiven CA as `MYSQL_SSL_CA_BASE64`. The certificate is created
only in the running container and is not part of the image or repository.

The container never migrates or seeds a production database on startup.
Back up the Aiven database, review `php artisan migrate:status`, and run
`php artisan migrate --force --no-interaction` once from Render Shell only after
approval. Do not run `migrate:fresh` or production seeders.

Render's filesystem is ephemeral. Before accepting production ID uploads,
configure S3-compatible object-storage values and set `FILESYSTEM_DISK=s3`.
The default `free` plan remains in the Blueprint to avoid unapproved billing;
choose an approved always-on production plan manually in Render.

### One-time production account provisioning

After the provisioning change has been deployed and approved, open the
Pedalyaaa web service's Render Shell and run:

```sh
php artisan accounts:provision-production
```

The command requires `APP_ENV=production` and a reachable MySQL connection. It
prints only the connected database name and server host identifier, then
requires the exact confirmation `PRODUCTION`. It checks all requested emails,
including soft-deleted users, before prompting for any passwords. Existing
records are left untouched. For each missing account, enter and confirm a
unique strong password at the hidden prompts; passwords must be at least 12
characters and contain lowercase and uppercase letters, a number, and a symbol.
No password is accepted as a command-line option or printed in the results.

The command creates each account in its own transaction and reports
`CREATED`, `ALREADY EXISTS`, `NOT CREATED`, `NOT CONFIRMED`, or `NOT ATTEMPTED`.
After an uncertain database write it stops prompting and marks later accounts
not attempted; verify the database before retrying. It does not run migrations
or seeders. Do not execute it until the displayed database identity has been
confirmed as the intended production database. Render Shell availability
depends on the service plan; do not upgrade the plan without billing approval.
