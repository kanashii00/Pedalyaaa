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
