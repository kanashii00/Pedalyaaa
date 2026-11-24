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
