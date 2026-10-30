@extends('layouts.admin')

@php
    use App\Http\Controllers\Admin\MonitoringController;

    // The tabs come from one list, and the default view is read from the
    // controller so the tab link can never drift from the URL that renders it.
    $defaultSection = MonitoringController::DEFAULT_SECTION;

    $sectionTitles = [
        'gps' => 'Live GPS Tracking',
        'locks' => 'Smart Lock Control',
        'devices' => 'IoT Device Monitoring',
    ];
    $sectionSubs = [
        'gps' => 'Real-time position updates',
        'locks' => 'Remote smart lock control',
        'devices' => 'Device health and telemetry',
    ];
    $sectionIcons = ['gps' => 'bi-geo-alt', 'locks' => 'bi-lock', 'devices' => 'bi-cpu'];
@endphp

@section('title', ($sectionTitles[$section] ?? 'Monitoring') . ' — Pedalya Admin')

@section('styles')
<style>
    #monitoringMap {
        width: 100%;
        height: 480px;
        border-radius: 14px;
        overflow: hidden;
    }
    .map-legend {
        position: absolute;
        bottom: 12px;
        left: 12px;
        z-index: 2;
        background: rgba(255,255,255,0.98);
        border-radius: 10px;
        padding: 10px 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        font-size: 0.82rem;
        color: #1a1a1a;
        border: 1px solid rgba(0,0,0,0.08);
    }
    .map-legend div {
        display: flex;
        align-items: center;
        margin-bottom: 4px;
    }
    .map-legend div:last-child { margin-bottom: 0; }
    .map-legend .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
        flex-shrink: 0;
        border: 2px solid rgba(255,255,255,0.8);
        box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }
    .section-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .section-tabs a {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        height: 34px;
        padding: 0 13px;
        border-radius: 9px;
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        color: var(--text-2);
        font-size: 12.5px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s;
    }
    .section-tabs a:hover { border-color: var(--border-strong); color: var(--text-1); }
    .section-tabs a.active { background: var(--brand-soft); color: var(--brand-strong); border-color: transparent; }
    .zone-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
    }
    .zone-pill .dot { width: 7px; height: 7px; border-radius: 50%; }

    /* Bike Monitor Card - works in light & dark mode */
    .bike-monitor-card {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius);
        padding: 16px;
        box-shadow: var(--shadow-card);
        height: 100%;
        transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s;
    }
    .bike-monitor-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-pop); border-color: var(--border-strong); }
    .bike-monitor-card hr { border-color: var(--border-subtle) !important; }
    .bike-status-dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
    }
    .bike-status-dot.online { background: var(--success); }
    .bike-status-dot.stale { background: var(--warning); }

    /* Last-command status box: token surfaces so it reads on dark navy.
       Replaces the hardcoded #f6f7fb background that rendered theme-aware
       light text nearly invisible in dark mode. */
    .dev-cmd {
        background: var(--surface-2);
        border: 1px solid var(--border-subtle);
        border-radius: 9px;
        color: var(--text-1);
        padding: 8px 10px;
        font-size: 11.5px;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }
    .dev-cmd strong { color: var(--text-1); }
    .dev-cmd__status { font-weight: 700; white-space: nowrap; }
    .dev-cmd__status--pending { color: var(--warning); }
    .dev-cmd__status--success { color: var(--success); }
    .dev-cmd__status--error { color: var(--danger); }

    /* Bootstrap's fixed text utilities go dim on dark navy (notably the dark
       #198754 green), so remap them to theme tokens inside monitor cards.
       Beats Bootstrap's !important; page-scoped, nothing else affected. */
    .bike-monitor-card .text-success { color: var(--success) !important; }
    .bike-monitor-card .text-danger { color: var(--danger) !important; }
    .bike-status-dot.offline { background: var(--text-3); }
    /* Placeholder for bicycles without a registered device: visibly not live. */
    .bike-monitor-card--unregistered {
        border-style: dashed;
        background: var(--surface-2);
    }
    .bike-monitor-card--unregistered:hover { transform: none; }
</style>
@endsection

@section('page-header')
    <h1>{{ $sectionTitles[$section] }}</h1>
    <p>{{ $sectionSubs[$section] }}</p>
@endsection

@section('actions')
<button class="btn-admin btn-admin--secondary btn-admin--sm" id="autoRefreshBtn" onclick="toggleAutoRefresh()">
    <i class="bi bi-arrow-clockwise me-1"></i><span id="refreshLabel">Auto-Refresh: Off</span>
</button>
@endsection

@section('content')
{{-- Section switcher --}}
<div class="section-tabs">
    @foreach($sectionTitles as $key => $title)
        <a href="{{ $key === $defaultSection ? route('admin.monitoring.index') : route('admin.monitoring.index') . '?section=' . $key }}"
           class="{{ $section === $key ? 'active' : '' }}">
            <i class="bi {{ $sectionIcons[$key] }}"></i> {{ $title }}
        </a>
    @endforeach
    {{-- Theft Detection is its own module/page, so it links straight to the route. --}}
    <a href="{{ route('admin.theft-alerts.index') }}"
       class="{{ request()->routeIs('admin.theft-alerts.*') ? 'active' : '' }}">
        <i class="bi bi-shield-exclamation"></i> Theft Detection
    </a>
</div>

{{-- Live GPS map --}}
@if($section === 'gps')
<x-admin.card title="Live GPS Tracking Map" bodyClass="p-0 position-relative mb-4">
    <x-slot:tools>
        <small class="text-muted me-3" id="fleetCount">{{ count($bicycles ?? []) }} bicycle(s)</small>
        <small class="text-muted">
            Geofence: <span id="geofenceRadiusText">{{ number_format($geofence['radius'], 0) }}m</span>
            <span id="geofenceAlertBadge">@if($geofence['alertEnabled'])
                <x-admin.badge type="success" label="Alerts ON"/>
            @endif</span>
        </small>
        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm" id="centerMapBtn" title="Center on bicycle GPS location" aria-label="Center on bicycle GPS location">
                <i class="bi bi-crosshair"></i>
            </button>
            <small id="centerMapStatus" class="text-muted align-self-center" role="status" aria-live="polite"></small>
            <button class="btn-admin btn-admin--ghost btn-admin--sm" id="refreshMapBtn" title="Refresh positions">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <button class="btn-admin btn-admin--ghost btn-admin--sm" id="fullscreenMapBtn" title="Fullscreen map">
                <i class="bi bi-fullscreen"></i>
            </button>
        </div>
    </x-slot:tools>

    <div id="monitoringMap"></div>
    <div class="map-legend">
        <div><span class="dot" style="background:#2ecc71;"></span>NEO-6M GPS fix available</div>
        <div><span class="dot" style="background:#95a5a6;"></span>GPS fix unavailable / last known</div>
        <div><span class="dot" style="background:#27ae60;"></span>Geofence Boundary</div>
    </div>
</x-admin.card>
@endif

@if($section === 'gps')
{{-- Live GPS Tracking table --}}
<div class="row align-items-center mb-3">
    <div class="col"><h5 class="mb-0">Live Position Feed</h5></div>
    <div class="col-auto">
        <small class="text-muted">Center: {{ number_format($geofence['centerLat'], 5) }}, {{ number_format($geofence['centerLng'], 5) }} · Radius {{ number_format($geofence['radius'], 0) }}m</small>
    </div>
</div>
<div class="admin-table-wrap">
    <table class="admin-table" id="gpsFeedTable">
        <thead>
            <tr>
                <th>Bicycle</th>
                <th>GPS Fix</th>
                <th>Latitude</th>
                <th>Longitude</th>
                <th>Accuracy / HDOP</th>
                <th>Zone</th>
                <th>Speed</th>
                <th>Distance from center</th>
                <th>Last GPS</th>
                <th>Last heartbeat</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bicycles as $bike)
                @php
                    $zoneLevel = $bike->zone['level'] ?? 'unknown';
                    $zoneStyle = match ($zoneLevel) {
                        'breach' => ['#e74c3c', 'Outside Zone'],
                        'warning', 'approaching' => ['#f39c12', 'Near Boundary'],
                        'safe' => ['#2ecc71', 'Inside Zone'],
                        default => ['#95a5a6', 'No GPS'],
                    };
                    $gps = $bike->gps_data;
                @endphp
                <tr data-gps-bike-id="{{ $bike->id }}">
                    <td data-label="Bicycle">
                        <div class="d-flex align-items-center gap-2">
                            <span class="bike-status-dot {{ ($bike->lastHeartbeat?->diffInMinutes(now()) ?? 999) <= 5 ? 'online' : 'offline' }}"></span>
                            <div>
                                <span class="fw-semibold">{{ $bike->name }}</span>
                                <small class="text-muted d-block">Bicycle #{{ $bike->id }}</small>
                            </div>
                        </div>
                    </td>
                    <td data-label="GPS Fix" data-gps-field="status">
                        @if($gps['status'] === 'fix')
                            GPS FIX AVAILABLE
                        @elseif($gps['status'] === 'last_known')
                            GPS FIX NOT AVAILABLE - LAST KNOWN LOCATION
                        @else
                            GPS FIX NOT AVAILABLE
                        @endif
                    </td>
                    <td data-label="Latitude" data-gps-field="latitude">{{ $gps['latitude'] !== null ? number_format($gps['latitude'], 7) : 'N/A' }}</td>
                    <td data-label="Longitude" data-gps-field="longitude">{{ $gps['longitude'] !== null ? number_format($gps['longitude'], 7) : 'N/A' }}</td>
                    <td data-label="Accuracy / HDOP" data-gps-field="accuracy">
                        @if($gps['accuracy'] !== null) {{ number_format($gps['accuracy'], 1) }} m accuracy @endif
                        @if($gps['hdop'] !== null){{ $gps['accuracy'] !== null ? ' · ' : '' }}HDOP {{ number_format($gps['hdop'], 2) }}@endif
                        @if($gps['accuracy'] === null && $gps['hdop'] === null)N/A @endif
                    </td>
                    <td data-label="Zone" data-gps-field="zone">
                        <span class="zone-pill" style="background: {{ $zoneStyle[0] }}22; color: {{ $zoneStyle[0] }};">
                            <span class="dot" style="background: {{ $zoneStyle[0] }};"></span>{{ $zoneStyle[1] }}
                        </span>
                    </td>
                    <td data-label="Speed" data-gps-field="speed">{{ $gps['speed'] !== null ? number_format($gps['speed'], 1) . ' km/h' : 'N/A' }}</td>
                    <td data-label="Distance from center" data-gps-field="distance">{{ $bike->zone['distance'] !== null ? number_format($bike->zone['distance'], 0) . ' m' : 'N/A' }}</td>
                    <td data-label="Last GPS" data-gps-field="updated-at">
                        @if($gps['updated_at'])
                            <time datetime="{{ $gps['updated_at'] }}" title="{{ $gps['updated_at'] }}">{{ $bike->latestGpsLog->created_at->diffForHumans() }}</time>
                        @else
                            Never
                        @endif
                    </td>
                    <td data-label="Last heartbeat">{{ $bike->lastHeartbeat?->diffForHumans() ?? 'Never' }}</td>
                </tr>
            @empty
                <tr><td colspan="10"><x-admin.empty-state icon="bi-bicycle" title="No bicycles to monitor"/></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endif

@if($section === 'locks')
{{-- Smart Lock Control --}}
<div class="row align-items-center mb-3">
    <div class="col"><h5 class="mb-0">Smart Lock Control</h5></div>
    <div class="col-auto">
        <small class="text-muted">Send lock / unlock commands to the ESP32 via WebSocket</small>
    </div>
</div>
<div class="row g-3">
    @forelse($bicycles as $bike)
        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="bike-monitor-card" data-bike-id="{{ $bike->id }}">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center">
                        <span class="bike-status-dot {{ ($bike->lastHeartbeat?->diffInMinutes(now()) ?? 999) <= 5 ? 'online' : 'offline' }}"></span>
                        <span class="fw-semibold ms-2">{{ $bike->name }}</span>
                    </div>
                    @if($bike->lockStatus === 'locked')
                        <x-admin.badge type="danger" label="Locked"/>
                    @else
                        <x-admin.badge type="success" label="Unlocked"/>
                    @endif
                </div>

                <div class="d-flex align-items-center gap-2 mb-2" style="font-size:13px;">
                    <i class="bi {{ $bike->lockStatus === 'locked' ? 'bi-lock-fill text-danger' : 'bi-unlock text-success' }}"></i>
                    <span class="text-muted">Last action:</span>
                    <span class="fw-semibold">{{ $bike->lastLockAction?->diffForHumans() ?? 'Never' }}</span>
                </div>

                <div class="row g-1 mb-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Battery</small>
                        <small class="fw-semibold">{{ $bike->batteryLevel }}%</small>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block">GPS</small>
                        <small class="fw-semibold">{{ $bike->currentLat && $bike->currentLng ? 'Valid' : 'N/A' }}</small>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('admin.bicycles.lock', $bike->id) }}" class="flex-grow-1">
                        @csrf
                        <input type="hidden" name="action" value="lock">
                        <button type="submit" class="btn-admin btn-admin--danger btn-admin--block {{ $bike->lockStatus === 'locked' ? 'disabled' : '' }}" {{ $bike->lockStatus === 'locked' ? 'disabled' : '' }}>
                            <i class="bi bi-lock-fill"></i> Lock
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.bicycles.lock', $bike->id) }}" class="flex-grow-1">
                        @csrf
                        <input type="hidden" name="action" value="unlock">
                        <button type="submit" class="btn-admin btn-admin--soft btn-admin--block {{ $bike->lockStatus !== 'locked' ? 'disabled' : '' }}" {{ $bike->lockStatus !== 'locked' ? 'disabled' : '' }}>
                            <i class="bi bi-unlock"></i> Unlock
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <x-admin.empty-state icon="bi-lock" title="No bicycles to control"/>
        </div>
    @endforelse
</div>
@endif

@if($section === 'devices')
{{-- IoT Device Monitoring.
     Only bicycles carrying a genuinely registered physical device (fitted
     hardware + stored device credential) render a live card. Every other row
     renders an explicit "No IoT device registered" placeholder instead of
     fabricated telemetry. No battery or RFID tiles: the deployed firmware
     reports neither, so there is nothing truthful to show. --}}
@php
    $registeredBicycles = $bicycles->filter(fn ($b) => $b->hasRegisteredDevice());
@endphp
<div class="row align-items-center mb-3">
    <div class="col"><h5 class="mb-0">IoT Device Monitoring</h5></div>
    <div class="col-auto">
        <small class="text-muted">Firmware, telemetry and device health · <span id="deviceRegisteredCount">{{ $registeredBicycles->count() }} registered</span></small>
    </div>
</div>
@if($registeredBicycles->isEmpty() && $bicycles->isNotEmpty())
    <div class="alert alert-pedalya alert-info alert-dismissible fade show" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i>No IoT devices registered. Key a physical device to a bicycle to begin monitoring.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
<div class="row g-3" id="deviceGrid">
    @forelse($bicycles as $bike)
        @if($bike->hasRegisteredDevice())
            @php
                $tel = $bike->latestTelemetry;
                $cmd = $bike->latestCommand;
                // Three distinct connection states: a device that never checked
                // in reads differently from one that went quiet.
                $hbState = $bike->lastHeartbeat === null
                    ? 'never'
                    : (($bike->lastHeartbeat->diffInMinutes(now()) <= 5) ? 'online' : 'offline');
                $hbLabel = $hbState === 'online' ? 'Online' : ($hbState === 'offline' ? 'Offline' : 'Never connected');
            @endphp
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="bike-monitor-card" data-bike-id="{{ $bike->id }}" data-device-card="{{ $bike->id }}">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center">
                            <span class="bike-status-dot {{ $hbState === 'online' ? 'online' : 'offline' }}" data-dev="conn-dot"></span>
                            <span class="fw-semibold ms-2">{{ $bike->name }} <small class="text-muted">{{ $bike->serialNumber }}</small></span>
                        </div>
                        <span class="text-muted small"><i class="bi bi-cpu"></i> <span data-dev="version">{{ $tel?->deviceVersion ?? '—' }}</span></span>
                    </div>
                    <div class="mb-2"><span class="badge-admin badge-admin--plain {{ $hbState === 'online' ? 'badge-admin--success' : 'badge-admin--neutral' }}" data-dev="conn">{{ $hbLabel }}</span></div>

                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <small class="text-muted d-block">Uptime</small>
                            <small class="fw-semibold" data-dev="uptime">{{ $tel?->uptime !== null ? gmdate('H:i:s', (int) $tel->uptime) : '—' }}</small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Lock</small>
                            <span data-dev="lock">
                                @if($bike->lockStatus === 'locked')
                                    <small class="fw-semibold text-danger"><i class="bi bi-lock-fill me-1"></i>Locked</small>
                                @else
                                    <small class="fw-semibold text-success"><i class="bi bi-unlock me-1"></i>Unlocked</small>
                                @endif
                            </span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Last GPS fix</small>
                            <small class="fw-semibold" data-dev="gps">{{ $bike->latestGpsLog?->created_at->diffForHumans() ?? 'No fix yet' }}</small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Last lock action</small>
                            <small class="fw-semibold" data-dev="lock-action">{{ $bike->lastLockAction?->diffForHumans() ?? 'Never' }}</small>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="row g-1">
                        <div class="col-6">
                            <small class="text-muted d-block">Last Heartbeat</small>
                            <small class="fw-semibold" data-dev="heartbeat">{{ $bike->lastHeartbeat?->diffForHumans() ?? 'Never' }}</small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">Last Telemetry</small>
                            <small class="fw-semibold" data-dev="telemetry">{{ $tel?->eventTimestamp?->diffForHumans() ?? 'Never' }}</small>
                        </div>
                    </div>

                    @php
                        $cmdStatusClass = match($cmd?->status) {
                            'pending', 'sent' => 'dev-cmd__status--pending',
                            'executed' => 'dev-cmd__status--success',
                            'failed' => 'dev-cmd__status--error',
                            default => '',
                        };
                    @endphp
                    <div class="mt-2 dev-cmd" data-dev="command">
                        @if($cmd)
                            <strong>Last command:</strong> {{ $cmd->command }}
                            <span class="dev-cmd__status {{ $cmdStatusClass }}"> · {{ $cmd->status }}</span><span class="text-muted">@if($cmd->response) — “{{ \Illuminate\Support\Str::limit($cmd->response, 60) }}”@endif · {{ $cmd->updated_at?->diffForHumans() }}</span>
                        @else
                            <span class="text-muted">No lock commands recorded.</span>
                        @endif
                    </div>
                </div>
            </div>
        @else
            <div class="col-xl-3 col-lg-4 col-md-6">
                <div class="bike-monitor-card bike-monitor-card--unregistered" data-unregistered-card="{{ $bike->id }}">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-semibold">{{ $bike->name }} <small class="text-muted">{{ $bike->serialNumber }}</small></span>
                    </div>
                    <span class="badge-admin badge-admin--neutral badge-admin--plain"><i class="bi bi-cpu me-1"></i>No IoT device registered</span>
                    <small class="text-muted d-block mt-2">Telemetry, lock commands and health appear here once a physical device is keyed to this bicycle.</small>
                </div>
            </div>
        @endif
    @empty
        <div class="col-12">
            <x-admin.empty-state icon="bi-cpu" title="No IoT devices registered"/>
        </div>
    @endforelse
</div>
@endif
@endsection

@section('scripts')
@if($section === 'gps')
@php
    $bicycleLocations = collect($bicycles)->map(fn($b) => [
        'id' => $b->id,
        'name' => $b->name,
        'lat' => $b->gps_data['latitude'],
        'lng' => $b->gps_data['longitude'],
        'gps_status' => $b->gps_data['status'],
        'last_gps_update' => $b->gps_data['updated_at'],
        'gps_accuracy' => $b->gps_data['accuracy'],
        'gps_hdop' => $b->gps_data['hdop'],
        'gps_satellites' => $b->gps_data['satellites'],
        'gps_speed' => $b->gps_data['speed'],
        'status' => $b->status,
        'battery' => $b->batteryLevel,
        'locked' => $b->lockStatus === 'locked',
        'heartbeat' => $b->lastHeartbeat?->toISOString(),
        'zone' => $b->zone['level'] ?? 'unknown',
        'distance' => $b->zone['distance'] ?? null,
    ])->values();
@endphp
<script src="{{ asset('js/geolibre-map.js') }}"></script>
<script>
(function () {
    var geofence = {!! json_encode($geofence) !!};
    var bicycles = {!! $bicycleLocations->toJson() !!};

    function updateGpsFeed(data) {
        (data.bicycles || []).forEach(function (bike) {
            var row = document.querySelector('[data-gps-bike-id="' + bike.id + '"]');
            if (!row) return;

            var status = bike.gps_status === 'fix'
                ? 'GPS FIX AVAILABLE'
                : (bike.gps_status === 'last_known'
                    ? 'GPS FIX NOT AVAILABLE - LAST KNOWN LOCATION'
                    : 'GPS FIX NOT AVAILABLE');
            var fields = {
                status: status,
                latitude: bike.current_lat !== null && bike.current_lat !== undefined && Number.isFinite(Number(bike.current_lat)) ? Number(bike.current_lat).toFixed(7) : 'N/A',
                longitude: bike.current_lng !== null && bike.current_lng !== undefined && Number.isFinite(Number(bike.current_lng)) ? Number(bike.current_lng).toFixed(7) : 'N/A',
                accuracy: [
                    bike.gps_accuracy !== null && bike.gps_accuracy !== undefined ? Number(bike.gps_accuracy).toFixed(1) + ' m accuracy' : null,
                    bike.gps_hdop !== null && bike.gps_hdop !== undefined ? 'HDOP ' + Number(bike.gps_hdop).toFixed(2) : null
                ].filter(Boolean).join(' · ') || 'N/A',
                speed: bike.gps_speed !== null && bike.gps_speed !== undefined ? Number(bike.gps_speed).toFixed(1) + ' km/h' : 'N/A',
                zone: bike.zone_level === 'breach' || bike.zone_level === 'outside'
                    ? 'Outside Zone'
                    : (bike.zone_level === 'warning' || bike.zone_level === 'approaching'
                        ? 'Near Boundary'
                        : (bike.gps_status === 'fix' ? 'Inside Zone' : 'No GPS')),
                distance: bike.zone_distance !== null && bike.zone_distance !== undefined
                    ? Math.round(Number(bike.zone_distance)) + ' m'
                    : 'N/A',
                'updated-at': bike.last_gps_update
                    ? new Date(bike.last_gps_update).toLocaleString()
                    : 'Never'
            };

            Object.keys(fields).forEach(function (field) {
                var cell = row.querySelector('[data-gps-field="' + field + '"]');
                if (!cell) return;
                if (field === 'zone') {
                    var colors = {
                        'Outside Zone': '#e74c3c',
                        'Near Boundary': '#f39c12',
                        'Inside Zone': '#2ecc71',
                        'No GPS': '#95a5a6'
                    };
                    var color = colors[fields.zone];
                    cell.innerHTML = '<span class="zone-pill" style="background:' + color + '22;color:' + color + ';">' +
                        '<span class="dot" style="background:' + color + ';"></span>' + fields.zone + '</span>';
                } else {
                    cell.textContent = fields[field];
                }
            });

            var updatedCell = row.querySelector('[data-gps-field="updated-at"]');
            if (updatedCell && bike.last_gps_update) {
                updatedCell.title = bike.last_gps_update;
            }
        });
    }

    window.PedalyaGeoLibre.init({
        container: 'monitoringMap',
        geofence: geofence,
        bicycles: bicycles,
        liveUrl: {!! json_encode(route('admin.monitoring.live')) !!},
        pollMs: 15000,
        zoom: 15,
        gpsZoom: 17,
        centerOnGps: true,
        pitch: 55,
        bearing: -15,
        readout: { radius: 'geofenceRadiusText', alertBadge: 'geofenceAlertBadge' },
        buttons: { center: 'centerMapBtn', refresh: 'refreshMapBtn', fullscreen: 'fullscreenMapBtn' },
        fleetCount: 'fleetCount',
        onLive: function (data) {
            updateGpsFeed(data);
        }
    });
})();
</script>
@endif
@if($section === 'devices')
<script>
/* IoT devices live patch: refreshes the registered-device cards from the
   monitoring live feed every 15s (same cadence as the GPS map) without a
   page reload. Only real device reports are ever painted; unregistered
   placeholders are never touched. Singleton-guarded; timers die with the
   page, and bfcache restores simply resume the interval. */
(function () {
    if (window.PedalyaDeviceLive) return;
    var liveUrl = {!! json_encode(route('admin.monitoring.live')) !!};
    var POLL_MS = 15000;
    var ONLINE_SECONDS = 300;
    var destroyed = false;
    var timer = 0;
    var inflight = false;

    function pad(n) { return String(n).padStart(2, '0'); }

    function ago(iso) {
        if (!iso) return null;
        var t = new Date(iso).getTime();
        if (isNaN(t)) return null;
        var s = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (s < 60) return s + 's ago';
        var m = Math.floor(s / 60);
        if (m < 60) return m + 'm ago';
        var h = Math.floor(m / 60);
        if (h < 48) return h + 'h ago';
        return Math.floor(h / 24) + 'd ago';
    }

    function fmtUptime(seconds) {
        if (seconds === null || seconds === undefined) return null;
        var s = Math.max(0, parseInt(seconds, 10) || 0);
        // Matches PHP gmdate('H:i:s'): hours wrap at 24.
        return pad(Math.floor(s / 3600) % 24) + ':' + pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
    }

    function setText(card, field, text) {
        var node = card.querySelector('[data-dev="' + field + '"]');
        if (node && node.textContent !== text) node.textContent = text;
    }

    function paintLock(card, lockStatus) {
        if (card.dataset.lockState === lockStatus) return;
        card.dataset.lockState = lockStatus;
        var host = card.querySelector('[data-dev="lock"]');
        if (!host) return;
        host.innerHTML = '';
        var small = document.createElement('small');
        var icon = document.createElement('i');
        if (lockStatus === 'locked') {
            small.className = 'fw-semibold text-danger';
            icon.className = 'bi bi-lock-fill me-1';
            small.appendChild(icon);
            small.appendChild(document.createTextNode('Locked'));
        } else {
            small.className = 'fw-semibold text-success';
            icon.className = 'bi bi-unlock me-1';
            small.appendChild(icon);
            small.appendChild(document.createTextNode('Unlocked'));
        }
        host.appendChild(small);
    }

    function paintCommand(card, cmd) {
        var sig = cmd ? [cmd.command, cmd.status, cmd.response, cmd.at].join('|') : 'none';
        if (card.dataset.cmdSig === sig) return;
        card.dataset.cmdSig = sig;
        var host = card.querySelector('[data-dev="command"]');
        if (!host) return;
        host.innerHTML = '';
        if (!cmd) {
            var none = document.createElement('span');
            none.className = 'text-muted';
            none.textContent = 'No lock commands recorded.';
            host.appendChild(none);
            return;
        }
        var strong = document.createElement('strong');
        strong.textContent = 'Last command: ' + cmd.command + ' ';
        var statusClass = cmd.status === 'executed' ? 'dev-cmd__status--success'
            : (cmd.status === 'failed' ? 'dev-cmd__status--error'
            : ((cmd.status === 'pending' || cmd.status === 'sent') ? 'dev-cmd__status--pending' : ''));
        var status = document.createElement('span');
        status.className = ('dev-cmd__status ' + statusClass).trim();
        status.textContent = '· ' + cmd.status;
        var meta = document.createElement('span');
        meta.className = 'text-muted';
        meta.textContent = (cmd.response ? ' — “' + cmd.response + '”' : '')
            + (cmd.at && ago(cmd.at) ? ' · ' + ago(cmd.at) : '');
        host.appendChild(strong);
        host.appendChild(status);
        host.appendChild(meta);
    }

    function paintCard(card, bike) {
        var hbAgo = ago(bike.last_heartbeat);
        var online = bike.last_heartbeat
            && (Date.now() - new Date(bike.last_heartbeat).getTime()) <= ONLINE_SECONDS * 1000;
        var dot = card.querySelector('[data-dev="conn-dot"]');
        var conn = card.querySelector('[data-dev="conn"]');
        var label = !bike.last_heartbeat ? 'Never connected' : (online ? 'Online' : 'Offline');
        if (dot) dot.className = 'bike-status-dot ' + (online ? 'online' : 'offline');
        if (conn && conn.textContent !== label) {
            conn.textContent = label;
            conn.className = 'badge-admin badge-admin--plain ' + (online ? 'badge-admin--success' : 'badge-admin--neutral');
        }
        setText(card, 'heartbeat', hbAgo || 'Never');
        var telAgo = ago(bike.last_telemetry);
        setText(card, 'telemetry', telAgo || 'Never');
        var gpsAgo = ago(bike.last_gps_update);
        setText(card, 'gps', gpsAgo || 'No fix yet');
        var lockAgo = ago(bike.last_lock_action);
        setText(card, 'lock-action', lockAgo || 'Never');
        setText(card, 'version', bike.device_version || '—');
        var up = fmtUptime(bike.device_uptime);
        setText(card, 'uptime', up || '—');
        paintLock(card, bike.lock_status);
        paintCommand(card, bike.last_command);
    }

    function poll() {
        if (destroyed || inflight || document.hidden) return;
        if (document.querySelector('.admin-modal.open, .pr-modal.open, .rcpt-modal.open')) return;
        inflight = true;
        fetch(liveUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
            .then(function (data) {
                if (destroyed) return;
                (data.bicycles || []).forEach(function (bike) {
                    if (!bike.has_registered_device) return;
                    var card = document.querySelector('[data-device-card="' + bike.id + '"]');
                    if (card) paintCard(card, bike);
                });
            })
            .catch(function () { /* next tick retries */ })
            .finally(function () { inflight = false; });
    }

    window.PedalyaDeviceLive = {
        destroy: function () {
            destroyed = true;
            if (timer) clearInterval(timer);
            if (window.PedalyaDeviceLive === this) window.PedalyaDeviceLive = null;
        },
        refresh: poll,
    };
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !destroyed) poll();
    });
    timer = setInterval(poll, POLL_MS);
})();
</script>
@endif
<script>
function toggleAutoRefresh() {
    var btn = document.getElementById('autoRefreshBtn');
    var label = document.getElementById('refreshLabel');
    var active = window._autoRefreshInterval;

    if (active) {
        clearInterval(active);
        window._autoRefreshInterval = null;
        label.textContent = 'Auto-Refresh: Off';
        btn.classList.remove('btn-admin--primary');
        btn.classList.add('btn-admin--secondary');
    } else {
        window._autoRefreshInterval = setInterval(function () {
            window.location.reload();
        }, 30000);
        label.textContent = 'Auto-Refresh: 30s';
        btn.classList.remove('btn-admin--secondary');
        btn.classList.add('btn-admin--primary');
    }
}
</script>
@endsection
