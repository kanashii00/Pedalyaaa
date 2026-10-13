#pragma once

#include <Arduino.h>

#include "config.h"

/* ------------------------------------------------------------------------
 *  Quectel EC20F cellular modem on UART2
 *
 *  EC20F TXD -> ESP32 RX2 GPIO16 (SIM_RX_PIN)
 *  EC20F RXD -> ESP32 TX2 GPIO17 (SIM_TX_PIN)
 *  EC20F GND -> ESP32 GND
 *  UART 115200 8N1.
 *
 *  The modem carries HTTP and nothing else: it never sees a rental decision,
 *  an actuator command or the server's JSON shape. Every call here is bounded
 *  by a SIM_* timeout in config.h, so a missing or dead modem costs one
 *  bounded wait rather than an indefinite stall of the main loop.
 *
 *  HTTP runs on the EC20F QHTTP service (AT+QHTTPURL / AT+QHTTPGET /
 *  AT+QHTTPPOST / AT+QHTTPREAD) with TLS via AT+QHTTPCFG + AT+QSSLCFG. The
 *  SIM7600-style AT+HTTPINIT / AT+HTTPPARA / AT+HTTPACTION command set does
 *  not exist on this modem and is never used here.
 *
 *  All diagnostics use the [SIM] prefix on the Serial monitor, and the device
 *  key is redacted from every logged line.
 * ------------------------------------------------------------------------ */

/** Brings UART2 up and asks the modem for AT. False when nothing answers. */
bool simInit();

/**
 * Sends one AT command line and returns everything the modem replied.
 *
 * timeoutMs bounds the wait; the read also stops as soon as a result code
 * (OK / ERROR / CONNECT / +QHTTPGET: / +QHTTPPOST:) has arrived on its own
 * line, so a normal command does not burn its whole timeout.
 */
String simSendCommand(const String& command, uint32_t timeoutMs = SIM_CMD_TIMEOUT_MS);

/** True when the modem answered the AT probe in simInit(). Cheap: no UART traffic. */
bool simPresent();

/**
 * True when the SIM has an active packet data link. Cached, so it is safe to
 * call from the main loop; refreshed by simConnectInternet() and by the bearer
 * check that runs after a failed HTTP exchange.
 */
bool simIsReady();

/**
 * Attaches to the network and activates PDP context 1 with the given APN.
 * Returns false when the APN is still the placeholder, the SIM has no
 * coverage, or the modem never answered.
 */
bool simConnectInternet(const char* apn);

/**
 * HTTP POST over the modem link. jsonPayload is sent as application/json;
 * extraHeaders is a single "Name: value" line and normally carries the
 * X-API-Key header the backend requires. It is forwarded inside the EC20F
 * custom request-header block, never in the URL.
 * responseBody, when given, receives the response body whatever the status.
 * True only for a 2xx status; simLastHttpStatus() reports the exact status.
 */
bool simHttpPost(const String& url,
                 const String& jsonPayload,
                 String* responseBody = nullptr,
                 const String& extraHeaders = String());

/** HTTP GET over the modem link. Semantics as for simHttpPost(). */
bool simHttpGet(const String& url,
                String* responseBody = nullptr,
                const String& extraHeaders = String());

/** HTTP status of the last exchange: 0 when no transport completed it. */
int simLastHttpStatus();
