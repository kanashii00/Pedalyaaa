#pragma once

#include <Arduino.h>
#include <math.h>
#include <stdint.h>

#include "config.h"

/**
 * Where the bicycle sits relative to the configured geofence.
 *
 * The final seconds of a rental are locked gently while the bicycle is still
 * at the riding zone (Inside or Near the boundary) and locked outright when it
 * is not (Outside). A bicycle with no usable GPS fix is Unknown and is treated
 * like one still inside the zone: with no evidence that it left, the gradual
 * lock is the safer verdict, because it never snaps the wheel shut while the
 * bicycle may still be moving - and it reaches the same LOCKED position at the
 * deadline either way.
 */
enum class BoundaryStatus : uint8_t { Unknown, Inside, Near, Outside };

/** Short name for the serial logs: INSIDE, NEAR, OUTSIDE, UNKNOWN. */
inline const char* boundaryStatusName(BoundaryStatus status) {
    switch (status) {
        case BoundaryStatus::Inside:  return "INSIDE";
        case BoundaryStatus::Near:    return "NEAR";
        case BoundaryStatus::Outside: return "OUTSIDE";
        case BoundaryStatus::Unknown: break;
    }
    return "UNKNOWN";
}

/** Metres between two positions, on the great circle (haversine). */
inline double boundaryDistanceMeters(double lat1, double lng1, double lat2, double lng2) {
    static const double EARTH_RADIUS_M = 6371000.0;
    static const double radPerDeg = 0.017453292519943295;

    const double dLat = (lat2 - lat1) * radPerDeg;
    const double dLng = (lng2 - lng1) * radPerDeg;
    const double sinLat = sin(dLat / 2.0);
    const double sinLng = sin(dLng / 2.0);
    const double a = sinLat * sinLat +
                     cos(lat1 * radPerDeg) * cos(lat2 * radPerDeg) * sinLng * sinLng;
    return 2.0 * EARTH_RADIUS_M * atan2(sqrt(a), sqrt(1.0 - a));
}

/**
 * Classify the current position against the configured geofence.
 *
 * @param hasFreshFix  False when the GPS fix is missing or stale, in which
 *                     case the position is Unknown and nothing is inferred.
 * @param latitude     Latest latitude, used only when hasFreshFix is true.
 * @param longitude    Latest longitude, used only when hasFreshFix is true.
 * @return Inside (within the radius), Near (out to the radius plus the
 *         warning margin), Outside (beyond that) or Unknown.
 */
inline BoundaryStatus evaluateBoundary(bool hasFreshFix, double latitude, double longitude) {
    if (!hasFreshFix) return BoundaryStatus::Unknown;

    const double distance = boundaryDistanceMeters(latitude, longitude,
                                                   GEOFENCE_CENTER_LAT, GEOFENCE_CENTER_LNG);
    if (distance <= GEOFENCE_RADIUS_M) return BoundaryStatus::Inside;
    if (distance <= GEOFENCE_RADIUS_M + GEOFENCE_NEAR_MARGIN_M) return BoundaryStatus::Near;
    return BoundaryStatus::Outside;
}
