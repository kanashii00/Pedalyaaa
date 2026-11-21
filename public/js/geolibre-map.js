/* ============================================================
   PEDALYA - Shared live location map module
   ------------------------------------------------------------
   Single source of truth for the live GeoLibre / OpenStreetMap
   view used by Live GPS Tracking, Theft Detection and the
   Rider Dashboard. Renders the saved geofence boundary + warning
   threshold band + every live bicycle pin coloured by GPS fix state
   (red=current fix, gray=last known/no fix). Geofence position is
   shown separately in the bicycle status and popup, using recorded
   GPS fixes and the same client-side math that mirrors
   GeofenceService::checkPoint.

   No hardcoded or duplicate map data: the geofence config and
   latest GPS readings are provided by the caller from
   GeofenceService + gps_logs.

   Usage:
     window.PedalyaGeoLibre.init({
       container: 'monitoringMap',
       geofence: { ... } | null,
       bicycles: [ { id, name, lat, lng, status, battery, locked, heartbeat } ],
       liveUrl: '/admin/monitoring/live',
       pollMs: 15000,
       alertUrl: null,              // optional theft-alerts live url
       readout: { radius: 'geofenceRadiusText', alertBadge: 'geofenceAlertBadge' },
       buttons: { center: 'centerMapBtn', refresh: 'refreshMapBtn', fullscreen: 'fullscreenMapBtn' },
       centerOnGps: false,           // center on a fresh GPS fix rather than the geofence
       legendCounts: true,
       bikeCardSelector: '.bike-monitor-card',
       fleetCount: 'fleetCount',
       alertBicycles: null,         // optional object: bikeId -> alert meta
       broadcast: true,             // set false to never join the admin channels
       onLive: null,                // optional callback(data) after every live poll
       onAlertsChange: null         // optional callback(alerts)
     });

   ============================================================ */
(function (global) {
    'use strict';

    function el(id) {
        return id ? document.getElementById(id) : null;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // --- Geometry helpers (mirror GeofenceService) ---

    function circlePolygon(lng, lat, radiusMeters, segments) {
        segments = segments || 96;
        var coords = [];
        var earth = 6371000;
        var latRad = lat * Math.PI / 180;
        var lngScale = earth * Math.cos(latRad);
        var latScale = earth;
        for (var i = 0; i <= segments; i++) {
            var rad = (i / segments) * 2 * Math.PI;
            var dLng = (Math.sin(rad) * radiusMeters) / lngScale;
            var dLat = (Math.cos(rad) * radiusMeters) / latScale;
            coords.push([lng + dLng * (180 / Math.PI), lat + dLat * (180 / Math.PI)]);
        }
        coords.push(coords[0]);
        return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [coords] } };
    }

    function metersToLatLngShape(start, x, y) {
        var latRad = start.lat * Math.PI / 180;
        return {
            lng: start.lng + (x / (111320 * Math.cos(latRad))),
            lat: start.lat + (y / 111320)
        };
    }

    function shapeVertices(gf) {
        var start = { lat: gf.centerLat, lng: gf.centerLng };
        var type = gf.shapeType || 'circle';
        var radius = gf.radius || 500;
        var width = gf.width || radius || 500;
        var height = gf.height || radius || 500;
        if (type === 'rectangle') {
            var a = width / 2, b = height / 2;
            var th = (gf.rotation || 0) * Math.PI / 180, cos = Math.cos(th), sin = Math.sin(th);
            var corners = [[a, b], [-a, b], [-a, -b], [a, -b]];
            return corners.map(function (c) {
                var x = c[0] * cos - c[1] * sin;
                var y = c[0] * sin + c[1] * cos;
                var p = metersToLatLngShape(start, x, y);
                return [p.lng, p.lat];
            });
        }
        if (type === 'oval_h' || type === 'oval_v') {
            var a2 = Math.max(1, width / 2), b2 = Math.max(1, height / 2);
            var el = [];
            for (var i = 0; i < 96; i++) {
                var rad = (i / 96) * 2 * Math.PI;
                var p = metersToLatLngShape(start, Math.cos(rad) * a2, Math.sin(rad) * b2);
                el.push([p.lng, p.lat]);
            }
            return el;
        }
        if (type === 'polygon' && gf.points && gf.points.length >= 3) {
            return gf.points.map(function (p) { return [p.lng, p.lat]; });
        }
        var coords = [];
        for (var i2 = 0; i2 < 96; i2++) {
            var r2 = (i2 / 96) * 2 * Math.PI;
            var p2 = metersToLatLngShape(start, Math.cos(r2) * radius, Math.sin(r2) * radius);
            coords.push([p2.lng, p2.lat]);
        }
        return coords;
    }

    function shapeFeature(gf) {
        var verts = shapeVertices(gf);
        if (verts.length) verts.push(verts[0]);
        return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [verts] } };
    }

    function setSourceData(map, id, feature) {
        if (map.getSource(id)) map.getSource(id).setData(feature);
        else map.addSource(id, { type: 'geojson', data: feature });
    }

    function addLayerOnce(map, layer) {
        if (!map.getLayer(layer.id)) map.addLayer(layer);
    }

    function safeRingVertices(gf) {
        var threshold = parseFloat(gf.warningThreshold) || 0;
        if (threshold <= 0) return [];
        var verts = shapeVertices(gf);
        var center = { lat: gf.centerLat, lng: gf.centerLng };
        var maxDim = Math.max(gf.radius || 0, gf.width || 0, gf.height || 0) * 0.5;
        threshold = Math.min(threshold, maxDim || threshold);
        var latRad = center.lat * Math.PI / 180;
        var dlng = threshold / (111320 * Math.cos(latRad));
        var dlat = threshold / 111320;
        return verts.map(function (v) {
            var dx = v[0] - center.lng;
            var dy = v[1] - center.lat;
            var m = Math.sqrt(dx * dx + dy * dy) || 1;
            return [v[0] - (dx / m) * dlng, v[1] - (dy / m) * dlat];
        });
    }

    function warnBandFeature(gf) {
        var outer = shapeVertices(gf).slice();
        if (outer.length) outer.push(outer[0]);
        var innerVerts = safeRingVertices(gf);
        if (!innerVerts.length) {
            return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [outer] } };
        }
        var inner = innerVerts.slice().reverse();
        if (inner.length) inner.push(inner[0]);
        return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [outer, inner] } };
    }

    function renderGeofence(map, gf, options) {
        setSourceData(map, 'geofence', shapeFeature(gf));
        addLayerOnce(map, {
            id: 'geofence-fill',
            type: 'fill',
            source: 'geofence',
            paint: { 'fill-color': '#27ae60', 'fill-opacity': 0.12 }
        });

        var hasThreshold = (parseFloat(gf.warningThreshold) || 0) > 0;
        setSourceData(map, 'warning-band', warnBandFeature(gf));
        addLayerOnce(map, {
            id: 'warning-band-fill',
            type: 'fill',
            source: 'warning-band',
            paint: { 'fill-color': '#ef5350', 'fill-opacity': 0.5 }
        });
        if (map.getLayer('warning-band-fill')) {
            map.setLayoutProperty('warning-band-fill', 'visibility', hasThreshold ? 'visible' : 'none');
        }

        addLayerOnce(map, {
            id: 'geofence-outline',
            type: 'line',
            source: 'geofence',
            paint: {
                'line-color': '#1e8449',
                'line-width': 3,
                'line-dasharray': [0, 2, 2, 2],
                'line-opacity': 0.9
            }
        });

        if (options.readout && options.readout.radius) {
            var radiusText = el(options.readout.radius);
            if (radiusText) radiusText.textContent = Math.round(gf.radius || 0) + 'm';
            var badge = el(options.readout.alertBadge);
            if (badge) {
                badge.innerHTML = gf.alertEnabled
                    ? '<span class="badge-admin badge-admin--success">Alerts ON</span>'
                    : '';
            }
        }
    }

    function zoneLabelText(zone) {
        switch (zone) {
            case 'safe': return 'Inside Zone';
            case 'approaching':
            case 'warning': return 'Near Boundary';
            case 'breach':
            case 'outside': return 'Outside Zone';
            default: return 'Unknown';
        }
    }

    function markerColor(bike) {
        return bike.gps_status === 'fix' ? '#e74c3c' : '#95a5a6';
    }

    function hasValidCoordinates(lat, lng) {
        if (lat === null || lat === undefined || lng === null || lng === undefined) return false;
        var latitude = Number(lat);
        var longitude = Number(lng);
        return Number.isFinite(latitude) && Number.isFinite(longitude)
            && latitude >= -90 && latitude <= 90
            && longitude >= -180 && longitude <= 180;
    }

    function haversineMeters(lat1, lng1, lat2, lng2) {
        var R = 6371000;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function localMeters(gf, lat, lng) {
        var centerLat = parseFloat(gf.centerLat) || 0;
        var centerLng = parseFloat(gf.centerLng) || 0;
        var latRad = centerLat * Math.PI / 180;
        return {
            x: (lng - centerLng) * (111320 * Math.cos(latRad)),
            y: (lat - centerLat) * 111320
        };
    }

    function latLng(v) {
        if (v && v.lat !== undefined && v.lng !== undefined) return v;
        return { lat: v[1], lng: v[0] };
    }

    function havDistToCenter(gf, lat, lng) {
        return haversineMeters(lat, lng, parseFloat(gf.centerLat), parseFloat(gf.centerLng));
    }

    function pointInside(gf, lat, lng) {
        var type = gf.shapeType || 'circle';
        if (type === 'polygon') {
            var pts = gf.points || [];
            if (pts.length < 3) return havDistToCenter(gf, lat, lng) <= (parseFloat(gf.radius) || 0);
            var inside = false;
            for (var i = 0, j = pts.length - 1; i < pts.length; j = i++) {
                var latI = parseFloat(pts[i].lat), lngI = parseFloat(pts[i].lng);
                var latJ = parseFloat(pts[j].lat), lngJ = parseFloat(pts[j].lng);
                var intersects = (lngI > lng) !== (lngJ > lng) &&
                    lat < (latJ - latI) * (lng - lngI) / (lngJ - lngI) + latI;
                if (intersects) inside = !inside;
            }
            return inside;
        }
        var m = localMeters(gf, lat, lng);
        if (type === 'rectangle') {
            var w = (parseFloat(gf.width) || parseFloat(gf.radius) || 500) / 2;
            var h = (parseFloat(gf.height) || parseFloat(gf.radius) || 500) / 2;
            var th = (parseFloat(gf.rotation) || 0) * Math.PI / 180;
            var cos = Math.cos(th), sin = Math.sin(th);
            var lx = m.x * cos - m.y * sin;
            var ly = m.x * sin + m.y * cos;
            return Math.abs(lx) <= w && Math.abs(ly) <= h;
        }
        if (type === 'oval_h' || type === 'oval_v') {
            var a = Math.max(1, (parseFloat(gf.width) || parseFloat(gf.radius) || 500) / 2);
            var b = Math.max(1, (parseFloat(gf.height) || parseFloat(gf.radius) || 500) / 2);
            return (m.x * m.x) / (a * a) + (m.y * m.y) / (b * b) <= 1;
        }
        return havDistToCenter(gf, lat, lng) <= (parseFloat(gf.radius) || 0);
    }

    function distanceToBoundary(gf, lat, lng) {
        var verts = shapeVertices(gf);
        var centerLat = parseFloat(gf.centerLat) || 0;
        var centerLng = parseFloat(gf.centerLng) || 0;
        if (verts.length < 2) {
            return Math.abs(haversineMeters(lat, lng, centerLat, centerLng) - (parseFloat(gf.radius) || 0));
        }
        var mPerDegLat = 111320;
        var mPerDegLng = 111320 * Math.cos(lat * Math.PI / 180);
        var min = Infinity;
        for (var i = 0; i < verts.length; i++) {
            var av = latLng(verts[i]);
            var bv = latLng(verts[(i + 1) % verts.length]);
            var ax = (av.lng - lng) * mPerDegLng;
            var ay = (av.lat - lat) * mPerDegLat;
            var bx = (bv.lng - lng) * mPerDegLng;
            var by = (bv.lat - lat) * mPerDegLat;
            var dx = bx - ax, dy = by - ay;
            var seg;
            if (dx === 0 && dy === 0) {
                seg = Math.sqrt(ax * ax + ay * ay);
            } else {
                var t = ((-ax) * dx + (-ay) * dy) / (dx * dx + dy * dy);
                t = Math.max(0, Math.min(1, t));
                var cx = ax + t * dx, cy = ay + t * dy;
                seg = Math.sqrt(cx * cx + cy * cy);
            }
            if (seg < min) min = seg;
        }
        return min;
    }

    function clientZoneFor(gf, lat, lng) {
        var dist = havDistToCenter(gf, lat, lng);
        var inside = pointInside(gf, lat, lng);
        var threshold = parseFloat(gf.warningThreshold) || 0;
        if (!inside) return { zone: 'breach', distance: dist };
        var toBoundary = distanceToBoundary(gf, lat, lng);
        if (threshold > 0) {
            if (toBoundary <= threshold * 0.5) return { zone: 'warning', distance: dist };
            if (toBoundary <= threshold) return { zone: 'approaching', distance: dist };
        }
        return { zone: 'safe', distance: dist };
    }

    function normalizeZone(bike, ctx) {
        if ((bike.gps_status === undefined || bike.gps_status === 'fix')
                && hasValidCoordinates(bike.lat, bike.lng)
                && ctx.geofence && ctx.geofence.centerLat !== undefined
                && ctx.geofence.centerLat !== null && ctx.geofence.centerLng !== undefined) {
            var res = clientZoneFor(ctx.geofence, bike.lat, bike.lng);
            return { zone: res.zone, distance: res.distance };
        }
        if (bike.gps_status === 'last_known' || bike.gps_status === 'unavailable') {
            return { zone: 'no-gps', distance: null };
        }
        return { zone: bike.zone || 'unknown', distance: bike.distance };
    }

    // --- Rendering / live sync ---

    function buildContext(bike) {
        return { id: bike.id, name: bike.name || ('Bike #' + bike.id), bike: bike };
    }

    function init(options) {
        if (!global.maplibregl) return null;

        var container = el(options.container);
        if (!container) return null;

        var ctx = {
            options: options,
            geofence: options.geofence || null,
            map: null,
            markers: {},
            cycling: null,
            centerButton: null,
            activePopup: null
        };

        var gf = ctx.geofence;
        var initialGpsBicycle = (options.bicycles || [])
            .filter(function (bike) {
                return bike.gps_status === 'fix' && hasValidCoordinates(bike.lat, bike.lng);
            })
            .sort(function (a, b) {
                return new Date(b.last_gps_update || 0).getTime() - new Date(a.last_gps_update || 0).getTime();
            })[0];
        var map = new global.maplibregl.Map({
            container: container,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: initialGpsBicycle
                ? [Number(initialGpsBicycle.lng), Number(initialGpsBicycle.lat)]
                : gf && gf.centerLng !== undefined && gf.centerLat !== undefined
                    ? [gf.centerLng, gf.centerLat]
                    : options.defaultCenter || [125.6470, 7.0990],
            zoom: initialGpsBicycle ? (options.gpsZoom || 17) : (options.zoom || 15),
            pitch: options.pitch || 55,
            bearing: options.bearing || -15,
            attributionControl: true
        });

        var nav = document.createElement('div');
        map.addControl(new global.maplibregl.NavigationControl(), 'top-right');
        map.addControl(new global.maplibregl.FullscreenControl(), 'top-right');
        ctx.map = map;

        function gpsLabel(bike) {
            if (bike.gps_status === 'fix') return 'GPS FIX AVAILABLE';
            if (bike.gps_status === 'last_known') return 'GPS FIX NOT AVAILABLE - LAST KNOWN LOCATION';
            if (bike.gps_status === 'unavailable') return 'GPS FIX NOT AVAILABLE';
            return null;
        }

        function createPopup(bike) {
            var popup = new global.maplibregl.Popup({
                offset: {
                    'top': [0, 8],
                    'top-left': [0, 8],
                    'top-right': [0, 8],
                    'bottom': [0, -32],
                    'bottom-left': [0, -32],
                    'bottom-right': [0, -32],
                    'left': [16, -14],
                    'right': [-16, -14]
                },
                closeButton: true,
                closeOnClick: false,
                maxWidth: '310px',
                className: 'pedalya-map-popup-wrap'
            }).setHTML(popupHtml(bike));

            popup.on('open', function () {
                if (ctx.activePopup && ctx.activePopup !== popup) {
                    ctx.activePopup.remove();
                }
                ctx.activePopup = popup;
            });
            popup.on('close', function () {
                if (ctx.activePopup === popup) {
                    ctx.activePopup = null;
                }
            });

            return popup;
        }

        function popupHtml(bike) {
            var alert = options.alertBicycles && options.alertBicycles[bike.id];
            var isBreach = bike.zone === 'breach' || bike.zone === 'outside';
            var isNear = bike.zone === 'warning' || bike.zone === 'approaching';

            // 1. Zone badge
            var zoneBadge = '';
            if (isBreach) {
                zoneBadge = '<span class="theft-badge theft-badge--critical"><i class="bi bi-shield-slash-fill"></i>Outside Zone</span>';
            } else if (isNear) {
                zoneBadge = '<span class="theft-badge theft-badge--warning"><i class="bi bi-exclamation-triangle-fill"></i>Near Boundary</span>';
            } else if (bike.zone === 'safe') {
                zoneBadge = '<span class="theft-badge theft-badge--success"><i class="bi bi-shield-check"></i>Inside Zone</span>';
            } else {
                zoneBadge = '<span class="theft-badge theft-badge--neutral">No GPS</span>';
            }

            // 2. Alert badge (if theft alert/incident exists)
            var alertBadgeHtml = '';
            if (alert) {
                var incId = alert.id ? ('#' + alert.id) : '';
                alertBadgeHtml = '<span class="theft-badge theft-badge--critical"><i class="bi bi-bell-fill"></i>Theft ' + incId + '</span>';
            }

            // 3. Status badge (Available, Rented, Maintenance, etc.)
            var statusBadge = '';
            var st = String(bike.status || 'unknown').toLowerCase();
            if (st === 'available') {
                statusBadge = '<span class="theft-badge theft-badge--success">Available</span>';
            } else if (st === 'rented') {
                statusBadge = '<span class="theft-badge theft-badge--info">Rented</span>';
            } else if (st === 'maintenance') {
                statusBadge = '<span class="theft-badge theft-badge--warning">Maintenance</span>';
            } else {
                statusBadge = '<span class="theft-badge theft-badge--neutral">' + escapeHtml(capitalize(st)) + '</span>';
            }

            // 4. Lock badge
            var isLocked = !!(bike.locked || bike.lock_status === 'locked');
            var lockBadge = isLocked
                ? '<span class="theft-badge theft-badge--critical"><i class="bi bi-lock-fill"></i>Locked</span>'
                : '<span class="theft-badge theft-badge--success"><i class="bi bi-unlock-fill"></i>Unlocked</span>';

            // 5. Distance formatting
            var distText = 'Inside';
            var distClass = 'text-success';
            if (bike.distance !== null && bike.distance !== undefined && !isNaN(Number(bike.distance))) {
                var dVal = Math.round(Number(bike.distance));
                if (isBreach) {
                    distText = dVal + 'm outside';
                    distClass = 'text-danger';
                } else if (isNear) {
                    distText = dVal + 'm from edge';
                    distClass = 'text-warning';
                } else {
                    distText = dVal + 'm radius';
                    distClass = 'text-success';
                }
            } else if (!isBreach && !isNear && bike.zone === 'safe') {
                distText = 'Inside Zone';
            } else {
                distText = '—';
                distClass = 'text-muted';
            }

            // 6. Coordinates
            var latStr = Number(bike.lat).toFixed(5);
            var lngStr = Number(bike.lng).toFixed(5);

            // 7. GPS Status and Icon
            var isFix = bike.gps_status === 'fix';
            var isLastKnown = bike.gps_status === 'last_known';
            var gpsTitle = isFix ? 'Live GPS Fix' : (isLastKnown ? 'Last Known Location' : 'No GPS Fix');
            var gpsClass = isFix ? 'text-success' : (isLastKnown ? 'text-warning' : 'text-muted');
            var gpsIcon = isFix ? 'bi-broadcast' : (isLastKnown ? 'bi-clock-history' : 'bi-geo');

            var updateTimeText = 'N/A';
            if (bike.last_gps_update) {
                var d = new Date(bike.last_gps_update);
                updateTimeText = isNaN(d.getTime()) ? String(bike.last_gps_update) : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } else if (bike.heartbeat) {
                var d2 = new Date(bike.heartbeat);
                updateTimeText = isNaN(d2.getTime()) ? String(bike.heartbeat) : d2.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }

            // 8. Rider & Rental row
            var riderHtml = '';
            var riderName = bike.rider || (bike.current_rider_user ? bike.current_rider_user.name : null);
            if (riderName && riderName !== 'No Active Rental') {
                var rentalPart = bike.rental ? ('<span class="text-muted ms-auto font-monospace">' + escapeHtml(bike.rental) + '</span>') : '';
                riderHtml = '<div class="pedalya-map-popup__rider">' +
                    '<span><i class="bi bi-person-fill text-muted me-1"></i><strong class="text-light">' + escapeHtml(riderName) + '</strong></span>' +
                    rentalPart +
                '</div>';
            }

            // 9. Action button
            var actionBtn = '';
            if (alert && alert.id) {
                actionBtn = '<button type="button" class="btn-admin btn-admin--primary btn-admin--sm w-100" data-view-incident="' + alert.id + '">' +
                    '<i class="bi bi-journal-text me-1"></i>View Incident Details' +
                '</button>';
            }

            return '<div class="pedalya-map-popup">' +
                '<div class="pedalya-map-popup__header">' +
                    '<div class="pedalya-map-popup__badges">' +
                        (alertBadgeHtml || zoneBadge) +
                        statusBadge +
                    '</div>' +
                '</div>' +
                '<div class="mb-2">' +
                    '<div class="pedalya-map-popup__title" title="' + escapeHtml(bike.name) + '">' + escapeHtml(bike.name) + '</div>' +
                    '<div class="pedalya-map-popup__subtitle">' +
                        '<span>#' + bike.id + '</span>' +
                        '<span>&bull;</span>' +
                        '<span class="font-monospace">' + escapeHtml(bike.serialNumber || 'SN-' + bike.id) + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="pedalya-map-popup__grid">' +
                    '<div class="pedalya-map-popup__grid-item">' +
                        '<span class="pedalya-map-popup__grid-label">Lock</span>' +
                        '<span class="pedalya-map-popup__grid-value">' + lockBadge + '</span>' +
                    '</div>' +
                    '<div class="pedalya-map-popup__grid-item">' +
                        '<span class="pedalya-map-popup__grid-label">Boundary</span>' +
                        '<span class="pedalya-map-popup__grid-value ' + distClass + '">' + distText + '</span>' +
                    '</div>' +
                    '<div class="pedalya-map-popup__grid-item">' +
                        '<span class="pedalya-map-popup__grid-label">Battery</span>' +
                        '<span class="pedalya-map-popup__grid-value text-muted"><i class="bi bi-battery-charging me-1 text-success"></i>' + (bike.battery !== undefined ? bike.battery + '%' : '—') + '</span>' +
                    '</div>' +
                '</div>' +
                riderHtml +
                '<div class="pedalya-map-popup__telemetry">' +
                    '<div class="pedalya-map-popup__coords">' +
                        '<span><i class="bi bi-geo-alt-fill text-muted me-1"></i>Coordinates</span>' +
                        '<code>' + latStr + ', ' + lngStr + '</code>' +
                    '</div>' +
                    '<div class="pedalya-map-popup__gps-status">' +
                        '<span class="' + gpsClass + ' fw-semibold"><i class="bi ' + gpsIcon + ' me-1"></i>' + gpsTitle + '</span>' +
                        '<span class="text-muted">' + escapeHtml(updateTimeText) + '</span>' +
                    '</div>' +
                '</div>' +
                actionBtn +
            '</div>';
        }

        function currentGpsPosition() {
            var ids = Object.keys(ctx.markers).sort(function (a, b) { return Number(a) - Number(b); });
            var bikes = ids.map(function (id) { return ctx.markers[id].bike; })
                .filter(function (bike) {
                    return (bike.gps_status === 'fix' || bike.gps_status === 'last_known')
                        && hasValidCoordinates(bike.lat, bike.lng);
                });
            bikes.sort(function (a, b) {
                if (a.gps_status !== b.gps_status) return a.gps_status === 'fix' ? -1 : 1;
                return new Date(b.last_gps_update || 0).getTime() - new Date(a.last_gps_update || 0).getTime();
            });
            return bikes[0] || null;
        }

        function updateCenterButton() {
            if (options.centerOnGps && ctx.centerButton) {
                ctx.centerButton.disabled = false;
            }
        }

        function addMarker(bike) {
            if (!hasValidCoordinates(bike.lat, bike.lng)) return;
            var color = markerColor(bike);
            var popup = createPopup(bike);
            var marker = new global.maplibregl.Marker({ color: color, pitchAlignment: 'auto', rotationAlignment: 'auto' })
                .setLngLat([Number(bike.lng), Number(bike.lat)])
                .setPopup(popup)
                .addTo(map);

            ctx.markers[bike.id] = { marker: marker, bike: bike, popup: popup };
            var markerElement = marker.getElement();
            if (markerElement) {
                if (bike.gps_status === 'last_known') markerElement.style.opacity = '0.55';

                // Reposition map gently on marker click so the popup does not get clipped by map container edges
                markerElement.addEventListener('click', function () {
                    if (map) {
                        var canvas = map.getCanvas();
                        var point = map.project([Number(bike.lng), Number(bike.lat)]);
                        var pad = 70;
                        if (point.x < pad || point.x > canvas.width - pad || point.y < pad || point.y > canvas.height - pad) {
                            map.easeTo({ center: [Number(bike.lng), Number(bike.lat)], duration: 300 });
                        }
                    }
                });
            }
        }

        function syncFleetStatus(bike) {
            if (!options.bikeCardSelector) return;
            var card = document.querySelector(options.bikeCardSelector + '[data-bike-id="' + bike.id + '"]');
            if (!card) return;
            var pill = card.querySelector('.zone-pill');
            if (pill) {
                var meta = zonePillMeta(bike.zone);
                pill.style.background = meta.bg + '22';
                pill.style.color = meta.bg;
                pill.innerHTML = '<span class="dot" style="background:' + meta.bg + ';"></span>' + meta.label;
            }
            var gpsCells = card.querySelectorAll('small.fw-semibold');
            if (hasValidCoordinates(bike.lat, bike.lng) && gpsCells.length > 1) {
                var gps = null;
                gpsCells.forEach(function (s) {
                    if (!gps && /^-?\d{1,3}\.\d+/.test(s.textContent || '')) gps = s;
                });
                if (gps) gps.textContent = Number(bike.lat).toFixed(4) + ', ' + Number(bike.lng).toFixed(4);
            }
        }

        function updateLegendCounts() {
            if (!options.legendCounts) return;
            var counts = { safe: 0, near: 0, outside: 0 };
            Object.keys(ctx.markers).forEach(function (id) {
                var zone = ctx.markers[id].bike.zone;
                if (zone === 'breach' || zone === 'outside') counts.outside++;
                else if (zone === 'approaching' || zone === 'warning') counts.near++;
                else if (zone === 'safe') counts.safe++;
            });
            var keys = { safe: 'safe', near: 'near', outside: 'outside' };
            Object.keys(keys).forEach(function (key) {
                var node = document.querySelector('.map-legend .legend-count[data-count="' + keys[key] + '"]');
                if (node) node.textContent = String(counts[key]);
            });
        }

        function updateMarker(bike) {
            if (!hasValidCoordinates(bike.lat, bike.lng)) {
                if (ctx.markers[bike.id]) {
                    ctx.markers[bike.id].marker.remove();
                    delete ctx.markers[bike.id];
                }
                if (bike.zone) {
                    bike.zone = 'no-gps';
                    bike.distance = null;
                    syncFleetStatus(bike);
                    updateLegendCounts();
                }
                updateCenterButton();
                return;
            }
            bike.lat = Number(bike.lat);
            bike.lng = Number(bike.lng);
            var norm = normalizeZone(bike, ctx);
            bike.zone = norm.zone;
            if (bike.distance === null || bike.distance === undefined) bike.distance = norm.distance;

            var isBreach = bike.zone === 'breach' || bike.zone === 'outside';
            var color = markerColor(bike);
            if (ctx.markers[bike.id]) {
                var existing = ctx.markers[bike.id].marker;
                existing.setLngLat([bike.lng, bike.lat]);
                existing.setColor(color);
                if (existing.getPopup()) existing.getPopup().setHTML(popupHtml(bike));
                var markerEl = existing.getElement();
                if (markerEl) {
                    if (isBreach) markerEl.classList.add('marker-breach');
                    else markerEl.classList.remove('marker-breach');
                    markerEl.style.opacity = bike.gps_status === 'last_known' ? '0.55' : '1';
                }
                ctx.markers[bike.id].bike = bike;
            } else {
                addMarker(bike);
                if (isBreach) {
                    var markerEl2 = ctx.markers[bike.id].marker.getElement();
                    if (markerEl2) markerEl2.classList.add('marker-breach');
                }
            }
            syncFleetStatus(bike);
            updateLegendCounts();
            updateCenterButton();
        }

        function applyLive(data) {
            if (data.geofence && JSON.stringify(data.geofence) !== JSON.stringify(ctx.geofence)) {
                ctx.geofence = data.geofence;
                renderGeofence(map, ctx.geofence, options);
            }
            (data.bicycles || []).forEach(function (bike) {
                updateMarker({
                    id: bike.id,
                    name: bike.name,
                    lat: bike.current_lat !== undefined ? bike.current_lat : bike.currentLat,
                    lng: bike.current_lng !== undefined ? bike.current_lng : bike.currentLng,
                    gps_status: bike.gps_status,
                    last_gps_update: bike.last_gps_update,
                    gps_accuracy: bike.gps_accuracy,
                    gps_hdop: bike.gps_hdop,
                    gps_satellites: bike.gps_satellites,
                    gps_speed: bike.gps_speed,
                    status: bike.status,
                    battery: bike.battery_level !== undefined ? bike.battery_level : bike.batteryLevel,
                    locked: bike.lock_status === 'locked' || bike.lockStatus === 'locked',
                    heartbeat: bike.last_heartbeat || bike.lastHeartbeat,
                    zone: bike.zone_level || (bike.zone ? bike.zone.level : null) || 'unknown',
                    distance: bike.zone_distance || (bike.zone ? bike.zone.distance : null) || null
                });
            });
            if (options.alertBicycles && data.alerts) {
                options.alertBicycles = {};
                data.alerts.forEach(function (a) {
                    options.alertBicycles[a.bicycleId] = { status: a.status, acknowledged: a.acknowledged };
                });
            }
            if (options.onAlertsChange && data.alerts) {
                options.onAlertsChange(data);
            }
            if (typeof options.onLive === 'function') {
                options.onLive(data);
            }
        }

        function fetchLive() {
            var url = options.liveUrl;
            if (!url) return Promise.resolve(null);
            return global.fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('GPS monitoring request failed with status ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    applyLive(data);
                    return data;
                })
                .catch(function (error) {
                    console.error('Unable to refresh bicycle GPS positions.', error);
                    return null;
                });
        }

        function startPolling() {
            // No live source means no polling: a map initialised without a
            // liveUrl is a static view and should not keep hitting the network.
            if (ctx.cycling || !options.liveUrl) return;
            fetchLive();
            ctx.cycling = setInterval(fetchLive, options.pollMs || 15000);
        }

        map.on('load', function () {
            if (ctx.geofence) renderGeofence(map, ctx.geofence, options);
            (options.bicycles || []).forEach(function (bike) {
                updateMarker(initBikeShape(bike));
            });

            var centerBtn = el(options.buttons && options.buttons.center);
            ctx.centerButton = centerBtn;
            updateCenterButton();
            if (centerBtn && options.centerOnGps) {
                centerBtn.addEventListener('click', function () {
                    var status = el('centerMapStatus');
                    if (status) status.textContent = '';
                    fetchLive().then(function (data) {
                        var bike = currentGpsPosition();
                        if (!bike) {
                            if (status) {
                                status.textContent = data
                                    ? 'No valid GPS location is available.'
                                    : 'Could not refresh GPS; no valid location is available.';
                            }
                            return;
                        }
                        map.flyTo({
                            center: [bike.lng, bike.lat],
                            zoom: options.gpsZoom || 17,
                            pitch: options.pitch || 55,
                            bearing: options.bearing || -15,
                            duration: 1000
                        });
                        if (status) {
                            status.textContent = bike.gps_status === 'last_known'
                                ? ''
                                : (data ? '' : 'GPS refresh failed; showing last known GPS position.');
                        }
                        var tracked = ctx.markers[bike.id];
                        if (tracked && tracked.marker.getPopup()) {
                            tracked.marker.getPopup().addTo(map);
                        }
                    });
                });
            } else if (centerBtn && ctx.geofence) {
                centerBtn.addEventListener('click', function () {
                    map.flyTo({
                        center: [ctx.geofence.centerLng, ctx.geofence.centerLat],
                        zoom: options.zoom || 15,
                        pitch: options.pitch || 55,
                        bearing: options.bearing || -15,
                        duration: 1000
                    });
                });
            }
            var refreshBtn = el(options.buttons && options.buttons.refresh);
            if (refreshBtn) refreshBtn.addEventListener('click', fetchLive);
            var fsBtn = el(options.buttons && options.buttons.fullscreen);
            if (fsBtn) {
                fsBtn.addEventListener('click', function () {
                    var c = container;
                    if (c.requestFullscreen) c.requestFullscreen();
                    else if (c.webkitRequestFullscreen) c.webkitRequestFullscreen();
                    else if (c.msRequestFullscreen) c.msRequestFullscreen();
                });
            }

            // WebSocket live updates (fallback to polling)
            //
            // The geofence-alerts channel is an admin feed: it carries a breach
            // event for every bicycle in the fleet, not just the viewer's own.
            // A rider-facing map must opt out with `broadcast: false`, otherwise
            // it would render other riders' bicycles and their coordinates.
            var broadcastOn = options.broadcast !== false &&
                global.Pedalya && global.Pedalya.broadcastEnabled && global.Echo;
            if (broadcastOn) {
                global.Echo.private('geofence-alerts').listen('GeofenceAlert', function (e) {
                    var bike = e && e.bicycle ? e.bicycle : null;
                    if (!bike) return;
                    var zone = bike.zone || e.level || 'unknown';
                    if (!options.centerOnGps) {
                        updateMarker({
                            id: bike.id,
                            name: bike.name,
                            lat: bike.lat !== undefined ? parseFloat(bike.lat) : bike.latitude,
                            lng: bike.lng !== undefined ? parseFloat(bike.lng) : bike.longitude,
                            status: bike.status,
                            battery: bike.battery !== undefined ? bike.battery : bike.battery_level,
                            locked: bike.locked === true || bike.lockStatus === 'locked',
                            heartbeat: bike.updated_at || bike.last_heartbeat,
                            zone: zone,
                            distance: bike.zone_distance !== undefined ? parseFloat(bike.zone_distance) : (bike.distance !== undefined ? parseFloat(bike.distance) : null)
                        });
                    }
                    if (options.onBreachEvent) options.onBreachEvent(bike, zone);
                });

                global.Echo.private('geofence-alerts').listen('GeofenceUpdated', function (e) {
                    if (!e || !e.geofence) return;
                    ctx.geofence = e.geofence;
                    renderGeofence(map, ctx.geofence, options);
                    Object.keys(ctx.markers).forEach(function (id) {
                        var mk = ctx.markers[id];
                        if (mk && mk.bike && hasValidCoordinates(mk.bike.lat, mk.bike.lng)) updateMarker(mk.bike);
                    });
                });

                (options.bicycles || []).forEach(function (bike) {
                    global.Echo.private('gps.' + bike.id).listen('GpsUpdate', function (e) {
                        var b = e && e.bicycle ? e.bicycle : null;
                        if (!b) return;
                        updateMarker({
                            id: b.id,
                            name: b.name,
                            lat: b.lat !== undefined ? parseFloat(b.lat) : b.latitude,
                            lng: b.lng !== undefined ? parseFloat(b.lng) : b.longitude,
                            status: b.status,
                            battery: b.battery !== undefined ? b.battery : b.battery_level,
                            locked: b.locked === true || b.lockStatus === 'locked',
                            heartbeat: b.updated_at || b.last_heartbeat,
                            gps_status: 'fix',
                            last_gps_update: b.lastGpsUpdate || new Date().toISOString(),
                            gps_accuracy: b.accuracy,
                            gps_hdop: b.hdop,
                            gps_satellites: b.satellites,
                            gps_speed: b.speed,
                            zone: b.zone || 'unknown',
                            distance: b.zone_distance !== undefined ? parseFloat(b.zone_distance) : null
                        });
                    });
                });
                if (options.onLive) startPolling();
            } else {
                startPolling();
            }
        });

        return ctx;
    }

    function initBikeShape(bike) {
        return {
            id: bike.id,
            name: bike.name,
            lat: bike.lat !== undefined ? bike.lat : bike.currentLat,
            lng: bike.lng !== undefined ? bike.lng : bike.currentLng,
            gps_status: bike.gps_status,
            last_gps_update: bike.last_gps_update,
            gps_accuracy: bike.gps_accuracy,
            gps_hdop: bike.gps_hdop,
            gps_satellites: bike.gps_satellites,
            gps_speed: bike.gps_speed,
            status: bike.status,
            battery: bike.battery !== undefined ? bike.battery : bike.batteryLevel,
            locked: bike.locked === true || bike.lockStatus === 'locked',
            heartbeat: bike.heartbeat || bike.lastHeartbeat,
            zone: bike.zone,
            distance: bike.distance
        };
    }

    function zonePillMeta(zone) {
        switch (zone) {
            case 'breach':
            case 'outside': return { bg: '#e74c3c', label: 'Outside Zone' };
            case 'warning':
            case 'approaching': return { bg: '#f39c12', label: 'Near Boundary' };
            case 'safe': return { bg: '#2ecc71', label: 'Inside Zone' };
            default: return { bg: '#95a5a6', label: 'No GPS' };
        }
    }

    global.PedalyaGeoLibre = {
        init: init,
        clientZoneFor: clientZoneFor,
        pointInside: pointInside
    };
})(window);
