# Pedalya

## Free Render demo

The root `render.yaml` deploys a Docker web service and configures it to use
PostgreSQL. Before deploying this configuration, create a Render Postgres
database and add its **Internal Database URL** to the web service's environment
as the secret `DB_URL`. The Blueprint declares `DB_URL` with `sync: false` so
the credential stays in the Render dashboard and is not stored in source
control. The entrypoint runs database migrations and starts the Laravel
scheduler alongside Apache.

To inspect the deployed data from a terminal, use the database's **External
Database URL** from Render's Connect menu with `psql`, then run `\dt` and SQL
queries such as `SELECT id, name, email, role, status FROM users;`. The old
local MySQL data and any data in a previous SQLite deployment are not copied to
the new PostgreSQL database automatically.

This is for demos only. Render's free Postgres database expires after 30 days,
and the web service's filesystem is ephemeral, so uploaded files can be lost
when the instance restarts or deploys. The service can also spin down while
idle; scheduled jobs only run while it is awake. Do not store real rider IDs or
other production data with this configuration.
