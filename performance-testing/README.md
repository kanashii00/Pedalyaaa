# Pedalya performance-testing evidence

Scripts in this directory require credentials only through environment variables; credentials and keys must never be committed or copied into reports.

- `k6-login.js` submits the real CSRF-protected login form. It reports the custom `login_request_duration` metric and `login_failed` rate.
- `k6-iot-heartbeat.js` sends supported, labelled simulated heartbeat payloads. It does not represent physical ESP32 hardware.
- `chrome-dashboard-metrics.mjs` is intended to collect Chrome navigation timing and LCP for an authorised admin-dashboard visit. Its JSON output must be captured by the calling runner.
