#pragma once

/*
 * EC20F UART Diagnostic
 * =====================
 * Compile-time guard: set EC20F_UART_DIAGNOSTIC 1 in secrets.h (or config.h
 * override) to activate.  When 0 (the default), this entire module compiles
 * to nothing and adds zero bytes to the production image.
 *
 * What it does when active
 * ------------------------
 *  - Runs ONCE inside setup(), before simInit() and before the Wi-Fi boot
 *    probe, so the UART state is fresh.
 *  - Tries each of EC20F_DIAG_BAUD_LIST baud rates in order.
 *  - For each rate: opens Serial2, drains any stale bytes, sends "AT\r\n",
 *    waits up to EC20F_DIAG_TIMEOUT_MS for a reply, then logs:
 *      [ec20-diag] baud=115200 sent=4 B  received=0 B  result=SILENT
 *      [ec20-diag] baud=9600   sent=4 B  received=6 B  result=RESPONSE "OK"
 *  - Hex-dumps up to EC20F_DIAG_HEX_LIMIT raw bytes so framing errors are
 *    visible even when the text is garbled.
 *  - Does NOT send any command other than "AT".  No AT+IPR, AT+QCFG, etc.
 *  - Does NOT persist any modem setting (ATE0, baud-rate change, …).
 *  - Does NOT block indefinitely: each rate costs at most EC20F_DIAG_TIMEOUT_MS.
 *  - Does NOT operate the servo, buzzer or GPS.
 *
 * After runEC20FDiagnostic() returns, normal startup continues exactly as it
 * would without the diagnostic; Serial2 is re-initialised by simInit() using
 * SIM_BAUD_RATE so no setting leaks into production.
 *
 * How to enable / disable
 * -----------------------
 *  In secrets.h (gitignored):
 *    #define EC20F_UART_DIAGNOSTIC 1   // enable
 *  After you have captured the log, put it back to 0 and reflash:
 *    #define EC20F_UART_DIAGNOSTIC 0   // disable (default)
 *
 * Safety notes
 * ------------
 *  - The diagnostic NEVER changes modem-persistent settings.
 *  - Serial2 is left closed (ended) after the run so simInit() owns UART2.
 *  - If the board cannot be identified from a label photo, do not modify
 *    wiring until the developer confirms pin voltages and pinout.
 */

#include "config.h"
#include <Arduino.h>

// -------------------------------------------------------------------
// Compile-time gate
// -------------------------------------------------------------------
#ifndef EC20F_UART_DIAGNOSTIC
#define EC20F_UART_DIAGNOSTIC 0
#endif

// Maximum milliseconds to wait for any byte at each baud rate.
#ifndef EC20F_DIAG_TIMEOUT_MS
#define EC20F_DIAG_TIMEOUT_MS 600UL
#endif

// Milliseconds of silence after the last byte before declaring the reply
// complete (avoids waiting for the full timeout when the modem did answer).
// Raised from 80ms to 200ms: EC20F replies occasionally arrive in bursts
// with a short inter-burst gap; 80ms could cut the capture too early.
#ifndef EC20F_DIAG_QUIET_MS
#define EC20F_DIAG_QUIET_MS 200UL
#endif

// Number of raw bytes to hex-dump per baud-rate attempt (diagnostic only).
#ifndef EC20F_DIAG_HEX_LIMIT
#define EC20F_DIAG_HEX_LIMIT 32
#endif

// Milliseconds to wait after Serial2.begin() before sending, so the UART
// hardware and the modem's receiver have time to settle.
#ifndef EC20F_DIAG_SETTLE_MS
#define EC20F_DIAG_SETTLE_MS 150UL
#endif

#if EC20F_UART_DIAGNOSTIC

/**
 * Run the multi-baud EC20F UART diagnostic and print results to Serial.
 *
 * Call once from setup(), before simInit().  Returns when all configured
 * baud rates have been tried.  Serial2 is closed on return so simInit()
 * can open it cleanly.
 */
void runEC20FDiagnostic();

#else

// When the diagnostic is disabled, the call compiles to nothing.
inline void runEC20FDiagnostic() {}

#endif  // EC20F_UART_DIAGNOSTIC

