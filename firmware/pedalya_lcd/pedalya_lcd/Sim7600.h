#pragma once

#include <Arduino.h>

#include "config.h"

/* ------------------------------------------------------------------------
 *  Quectel EC20F cellular modem on UART2
 *
 *  EC20F TXD -> ESP32 GPIO16 (SIM_RX_PIN)
 *  EC20F RXD -> ESP32 GPIO17 (SIM_TX_PIN)
 * 
 *  The modem carries HTTP and nothing else: it never sees a rental decision,
 *  an actuator command or the server's JSON shape. Every call here is bounded
 *  by a SIM_* timeout in config.h, so a missing or dead modem costs one
 *  bounded wait rather than an indefinite stall of the main loop.
 *
 *  All diagnostics use the [SIM] prefix on the Serial monitor, and the device
 *  key is redacted from every logged line.
 * ------------------------------------------------------------------------ */

/** Brings UART2 up and asks the modem for AT. False when nothing answers. */
bool simInit();

/**
 * Bounded AT probe: sends AT\r\n, waits for a fresh matching OK,
 * and reports exact byte count received, elapsed milliseconds, and timeout details.
 * Treats RDY as an unsolicited startup indication (never as AT success).
 * Does not treat silence as proof of SIM or network coverage failure.
 */
bool simProbeAt(uint32_t timeoutMs = SIM_CMD_TIMEOUT_MS,
                bool* outSawRdy = nullptr,
                size_t* outBytesReceived = nullptr);

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
 * True when the SIM is registered on a mobile network (home or roaming).
 * Cached, refreshed during simInit() and simConnectInternet().
 */
bool simIsRegistered();

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
 * extraHeaders is a single "Name: value" line, normally the X-API-Key header.
 * It is transmitted verbatim inside a custom request head enabled with
 * AT+QHTTPCFG="requestheader",1, so cellular requests authenticate exactly
 * like the Wi-Fi ones. The key is never written to the Serial log.
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

/**
 * Runs a complete, bounded diagnostic suite of AT commands:
 * AT, ATI, AT+CPIN?, AT+CSQ, AT+COPS?, AT+CREG?, AT+CGREG?, AT+CEREG?, AT+CGATT?, AT+CGDCONT?.
 * Parses delayed and multi-line responses accurately with bounded timeouts.
 */
void simRunDiagnostics();

/** HTTP status of the last exchange: 0 when no transport completed it. */
int simLastHttpStatus();
