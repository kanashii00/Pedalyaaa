#pragma once

// Template for the local secrets file.
//
// Copy to secrets.h inside this same folder and fill in the real values:
//   cp secrets_example.h secrets.h      (Windows: copy secrets_example.h secrets.h)
//
// secrets.h is gitignored. Never commit the filled-in copy.

#ifndef WIFI_SSID
#define WIFI_SSID "your-wifi-ssid"
#endif

#ifndef WIFI_PASSWORD
#define WIFI_PASSWORD "your-wifi-password"
#endif

// Issued per bicycle by: php artisan devices:provision-key <id>
// Sent as the X-API-Key header, and must belong to the bicycle named in
// SERVER_PATH. A valid key for a different bicycle is rejected as invalid.
// Left empty on purpose: a copied template must reach the API key check
// (which reports a missing key) rather than ship a placeholder that looks
// plausible but is rejected as invalid.
#ifndef DEVICE_API_KEY
#define DEVICE_API_KEY ""
#endif

// Fleet-wide key for the shared endpoints (/api/iot/heartbeat and
// /api/iot/accident-report). Its value is the backend's own DEVICE_API_KEY
// entry in .env (services.device_api_key), NOT a per-bicycle key: those two
// routes sit behind the fleet auth and reject the bicycle key with 401
// Invalid API key. Left empty so a copied template reports a missing key.
#ifndef PEDALYA_API_KEY
#define PEDALYA_API_KEY ""
#endif

// APN of the SIM in the SIM7600 modem, needed only for the cellular fallback
// path. Ask the carrier for the exact value (e.g. from the plan confirmation
// or SIM pack); never guess one, a wrong APN simply refuses to attach.
// The modem is left in its "no data link yet" state while this placeholder is
// in place, and the monitor logs a reminder instead of dialling the wrong net.
#ifndef SIM_APN
#define SIM_APN "YOUR_APN_HERE"
#endif

// Filename of the approved root CA already uploaded to the EC20F UFS by the
// modem provisioning procedure.  Leave empty until that has been done: HTTPS
// over cellular will fail closed rather than disabling certificate validation.
#ifndef SIM_TLS_CA_FILE
#define SIM_TLS_CA_FILE ""
#endif
