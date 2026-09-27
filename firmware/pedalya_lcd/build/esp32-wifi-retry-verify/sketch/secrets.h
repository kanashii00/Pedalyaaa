#line 1 "C:\\Users\\Administrator\\pedalya\\firmware\\pedalya_lcd\\pedalya_lcd\\secrets.h"
#pragma once

// LOCAL SECRETS - not committed.
//
// This file holds the live per-bicycle device key. It is listed in
// firmware/pedalya_lcd/.gitignore so it never reaches the repository.
// Commit secrets_example.h instead, and copy this file on a new machine.
//
// The key below was issued by:
//   php artisan devices:provision-key 1
//
// Only a SHA-256 digest is stored server side, so this plaintext cannot be
// recovered. If it is lost, issue a new one with:
//   php artisan devices:provision-key 1 --rotate

#define WIFI_SSID "PLDTHOMEFIBR5GxSWST"
#define WIFI_PASSWORD "PLDTWIFIZ2953"

#define DEVICE_API_KEY "1d1e9e1779272497fba1370540f5b2f5fce5c9039877294933483346fe849263"
