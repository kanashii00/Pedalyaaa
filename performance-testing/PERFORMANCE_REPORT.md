# Pedalya performance test report

Test date: 14 October 2026 (Asia/Singapore)  
Application: local Laravel server at `http://127.0.0.1:8000`  
Host: `MSI`, Windows local PC; AMD Ryzen 7 7730U (8 cores / 16 logical processors), 15.38 GB RAM

## Results

| Test ID | Users / devices | Duration | Average (ms) | p95 (ms) | Slowest (ms) | Error rate (%) | Server used (CPU, RAM, host) |
| --- | --- | --- | ---: | ---: | ---: | ---: | --- |
| PT-001 | 1 authorised admin user | One dashboard load | 4,877* | N/A (one sample) | 4,877* | N/A | Local Windows PC; Ryzen 7 7730U (8C/16T), 15.38 GB RAM; `MSI` |
| PT-002 | 10 virtual users | 3 min 7.3 sec total | 6,780 | 11,590 | 12,630 | 0.00% | Local Windows PC; CPU/RAM not recorded for this preserved prior run |
| PT-003 | 10 virtual users; one dedicated rider test account | 3 min | 8,431 | 10,007 | 10,127 | 0.00% | Local Windows PC; Ryzen 7 7730U (8C/16T), 15.38 GB RAM; `MSI` |
| PT-004 | 2 simulated IoT devices (doubled from 1) | 3 min | 1,301 | 3,514 | 8,362 | 0.00% | Local Windows PC; Ryzen 7 7730U (8C/16T), 15.38 GB RAM; `MSI` |
| PT-005 | 1 physical ESP32 device | Not measured | Not measured | N/A | Not measured | Not measured | Physical sensor-to-screen observation was not available in this session |
| PT-006 | N/A — no AI model | N/A | N/A | N/A | N/A | N/A | N/A |

\* PT-001 average and slowest are Chrome Navigation Timing `duration`, not an HTTP-load-test aggregate. Chrome recorded **LCP: 5,980 ms**, DOM content loaded at 4,811 ms, and navigation/load completion at 4,877 ms. LCP is reported separately because it is not equivalent to HTTP response duration.

## Test evidence and method

### PT-001 — browser dashboard load

`chrome-dashboard-metrics.mjs` launched headless Chrome, authenticated as the authorised local admin account, waited for `/admin/dashboard`, and read Navigation Timing plus Largest Contentful Paint through Chrome DevTools Protocol. The result is saved in `evidence/pt001-chrome-metrics.json`.

### PT-002 — preserved result

The figures in the brief were retained verbatim. They were not rerun or replaced.

### PT-003 — real login flow

`k6-login.js` first loads `/login` to obtain its CSRF token and session cookie, then submits the real `POST /login` flow for each virtual user. It does not treat a `GET /login` as a successful login. The dedicated local rider account is `performance.login@pedalya.test`; no password, cookie, session token, or API key is recorded in the evidence.

The run made 10 login submissions: 10 redirects after valid credentials, 0 failed login checks, and 0 failed HTTP requests. Table timings use the custom `login_request_duration` metric, which covers only the login POST rather than its preparatory GET.

### PT-004 — IoT HTTP heartbeat load

The firmware and Laravel routes use authenticated HTTP `POST /api/iot/heartbeat`, not MQTT. `k6-iot-heartbeat.js` sends a supported JSON heartbeat with the configured device key supplied only at execution time. It uses bicycle 6, already in maintenance, and labels the `device_id` values as simulated.

| Run | Simulated devices | Requests | Successful | Failed | Average (ms) | p95 (ms) | Maximum (ms) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Baseline | 1 | 29 | 29 | 0 | 1,297 | 3,353 | 6,693 |
| Doubled (table result) | 2 | 58 | 58 | 0 | 1,301 | 3,514 | 8,362 |

These are API simulations, not evidence that multiple physical ESP32 units were attached.

### PT-005 — sensor to dashboard

Not measured. A physical ESP32 and a direct visual observation of the dashboard were not available to establish sensor-transmission-to-screen timing. The PT-004 heartbeats demonstrate only simulated API processing and must not be substituted for physical sensor-to-screen latency.

To complete PT-005, connect one ESP32 configured for the local server, transmit 10 timestamped heartbeat/sensor readings, record each transmission time, and record the moment the matching `last_heartbeat` or telemetry value becomes visible on Admin → Monitoring. Store the 10 delays, their average, and their maximum as separate evidence.

## Evidence files

- `evidence/pt001-chrome-metrics.json` — Chrome LCP and navigation timing.
- `evidence/pt003-k6-login-summary.json` — k6 login metric summary.
- `evidence/pt003-k6-login-console.txt` — k6 console summary.
- `evidence/pt004-1vu-summary.json` and `evidence/pt004-1vu-console.txt` — baseline IoT run.
- `evidence/pt004-2vu-summary.json` and `evidence/pt004-2vu-console.txt` — doubled IoT run.
- `k6-login.js`, `k6-iot-heartbeat.js`, `chrome-dashboard-metrics.mjs`, `prepare-login-account.php`, and `run-iot-k6.php` — reproducible test tooling.

## Assessment

- PT-001 does **not** meet the guide's 2.5-second page-load target: LCP was 5,980 ms.
- PT-002 does **not** meet the guide's p95-under-2-second target: p95 was 11,590 ms. Its 0% error rate meets the under-1% error target.
- PT-003 does **not** meet the guide's p95-under-2-second target: p95 was 10,007 ms. All 10 valid login submissions succeeded and the 0% error rate meets the no-failed-logins objective.
- PT-004 meets the guide's loss/error criterion for this simulation: 58 of 58 doubled-load heartbeats were successful (0% HTTP error rate). Its p95 is 3,514 ms and should be monitored against any project-specific IoT latency target.
- PT-005 remains incomplete until physical sensor-to-screen timing is observed. PT-006 is correctly N/A because there is no prediction model.

## Evidence-based recommendations

1. Profile the dashboard and login requests with Laravel query logging or a local profiler to identify whether database queries, view rendering, session storage, or external assets dominate the multi-second wait time. Apply query eager-loading/indexing only after that evidence identifies the bottleneck.
2. Inspect the login path's audit-log write and session regeneration under concurrent requests; preserve both security controls, but measure their database queries and add suitable indexes if the profiler identifies them as slow.
3. For the dashboard, measure/cache static assets and defer non-critical scripts after profiling. Re-run PT-001 in a fresh browser profile after each change and compare LCP, not merely HTTP duration.
4. Keep the IoT heartbeat interval and production device count configurable. Re-run both the expected-device and 2×-device tests against the deployed environment before UAT, then compare database write latency and error rate.
