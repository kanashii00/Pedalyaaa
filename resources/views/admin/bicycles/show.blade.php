@extends('layouts.admin')

@section('title', 'Bicycle Details')

@section('page-header')
    <h1>Bicycle Details</h1>
    <p>Serial: {{ $bicycle->serialNumber }}</p>
@endsection

@section('actions')
    <a href="{{ route('admin.bicycles.index') }}" class="btn-admin btn-admin--secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Bicycles
    </a>
@endsection

@section('content')
@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

<div class="row g-4 bike-detail">
    <!-- Overview -->
    <div class="col-lg-5">
        <x-admin.card>
            <div class="bike-summary">
                <div class="bike-summary__icon" aria-hidden="true">
                    <i class="bi bi-bicycle"></i>
                </div>
                <h5 class="bike-summary__name">{{ $bicycle->name }}</h5>
                <p class="bike-summary__model">{{ $bicycle->model ?? 'Standard Model' }}</p>
                <div class="bike-summary__badges">
                    <x-admin.badge :type="match($bicycle->status) { 'available' => 'success', 'rented' => 'info', 'maintenance' => 'warning', default => 'neutral' }" :label="ucfirst($bicycle->status)" />
                    <x-admin.badge :type="$bicycle->lockStatus === 'locked' ? 'danger' : 'success'" :label="$bicycle->lockStatus === 'locked' ? 'Locked' : 'Unlocked'" />
                    <x-admin.badge :type="$bicycle->batteryLevel <= 20 ? 'danger' : 'success'" :label="$bicycle->batteryLevel . '%'" />
                </div>
                <div class="bike-summary__stats">
                    <div class="bike-stat"><strong>{{ $bicycle->totalRentals }}</strong><small>Rentals</small></div>
                    <div class="bike-stat"><strong>{{ $bicycle->totalDistance }} km</strong><small>Distance</small></div>
                    <div class="bike-stat"><strong>₱{{ number_format($bicycle->hourlyRate, 2) }}</strong><small>/hr</small></div>
                </div>
            </div>
        </x-admin.card>

        <x-admin.card title="Remote Lock Control">
            @php($pendingLockCommand = \App\Models\DeviceCommand::where('bicycleId', $bicycle->id)->whereIn('command', ['lock', 'unlock'])->whereIn('status', ['pending', 'sent'])->latest('id')->first())
            @php($deviceOffline = !$bicycle->lastHeartbeat || $bicycle->lastHeartbeat->lt(now()->subMinutes(2)))
            @if($pendingLockCommand)
                <div class="alert alert-warning py-2 mb-2"><i class="bi bi-hourglass-split me-1"></i>{{ ucfirst($pendingLockCommand->command) }}ing — pending ESP32 acknowledgement.</div>
            @elseif($deviceOffline)
                <div class="alert alert-secondary py-2 mb-2"><i class="bi bi-wifi-off me-1"></i>Device offline; command delivery waits for its next check-in.</div>
            @endif
            <p class="bike-hint">Queue a command for the ESP32 smart lock. It applies when the device next checks in — the lock state below updates only after the device acknowledges.</p>
            <div class="bike-lock-actions">
                <form action="{{ route('admin.bicycles.lock', $bicycle->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="action" value="lock">
                    <button type="submit" class="btn-admin btn-admin--danger bike-lock-btn" {{ $pendingLockCommand ? 'disabled' : '' }}>
                        <i class="bi bi-lock-fill me-1"></i>Lock
                    </button>
                </form>
                <form action="{{ route('admin.bicycles.lock', $bicycle->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="action" value="unlock">
                    <button type="submit" class="btn-admin btn-admin--secondary bike-lock-btn" {{ $pendingLockCommand ? 'disabled' : '' }}>
                        <i class="bi bi-unlock-fill me-1"></i>Unlock
                    </button>
                </form>
            </div>
            <small class="bike-hint bike-hint--muted d-block mt-2">
                Last hardware acknowledgement: {{ $bicycle->lastLockAction ? $bicycle->lastLockAction->diffForHumans() : 'Never' }}
                @if($bicycle->lockActionBy) by #{{ $bicycle->lockActionBy }} @endif
            </small>
        </x-admin.card>
    </div>

    <!-- Telemetry -->
    <div class="col-lg-7">
        <x-admin.card title="Device Telemetry">
            <?php
                $t = $bicycle->latestTelemetry;
                $hasDevice = (bool) ($bicycle->iotEnabled ?? $t);
                $freshCutoff = now()->subMinutes(5);
                $heartbeatFresh = $bicycle->lastHeartbeat && $bicycle->lastHeartbeat->gte($freshCutoff);
                $gpsFresh = $bicycle->lastGpsUpdate && $bicycle->lastGpsUpdate->gte($freshCutoff);
                $batteryLevel = $t?->battery['level'] ?? $bicycle->batteryLevel;
                $batteryShown = $hasDevice && $batteryLevel !== null;
                $lockShown = $hasDevice;
                $uptimeShown = $hasDevice && $t?->uptime;
                $fwShown = $hasDevice && $t?->deviceVersion;
            ?>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="bike-tel-tile">
                        <small class="bike-tel-label">Battery</small>
                        <strong class="bike-tel-value">{{ $batteryShown ? $batteryLevel . '%' : '—' }}</strong>
                        @if(!$hasDevice)
                            <small class="bike-tel-note">No device</small>
                        @elseif(!$heartbeatFresh && $batteryShown)
                            <small class="bike-tel-note bike-tel-note--stale">Stale</small>
                        @endif
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bike-tel-tile">
                        <small class="bike-tel-label">Lock</small>
                        <strong class="bike-tel-value">{{ $lockShown ? ucfirst($t?->lockStatus ?? $bicycle->lockStatus) : '—' }}</strong>
                        @if(!$hasDevice)
                            <small class="bike-tel-note">No device</small>
                        @endif
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bike-tel-tile">
                        <small class="bike-tel-label">Uptime</small>
                        <strong class="bike-tel-value">{{ $uptimeShown ? number_format($t->uptime / 3600, 1) . 'h' : '—' }}</strong>
                        @if($hasDevice && !$uptimeShown)
                            <small class="bike-tel-note">No report</small>
                        @endif
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="bike-tel-tile">
                        <small class="bike-tel-label">Firmware</small>
                        <strong class="bike-tel-value bike-tel-value--sm">{{ $fwShown ? $t->deviceVersion : '—' }}</strong>
                        @if($hasDevice && !$fwShown)
                            <small class="bike-tel-note">No report</small>
                        @elseif(!$hasDevice)
                            <small class="bike-tel-note">No device</small>
                        @endif
                    </div>
                </div>
            </div>
            <hr class="bike-divider">
            <table class="bike-tel-table">
                <tbody>
                    <tr>
                        <th>Last Heartbeat</th>
                        <td>
                            {{ $bicycle->lastHeartbeat ? $bicycle->lastHeartbeat->diffForHumans() : '—' }}
                            @if($bicycle->lastHeartbeat && !$heartbeatFresh)
                                <span class="bike-stale">Stale</span>
                            @elseif(!$bicycle->lastHeartbeat)
                                <span class="bike-unavailable">Unavailable</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>Last GPS Update</th>
                        <td>
                            {{ $bicycle->lastGpsUpdate ? $bicycle->lastGpsUpdate->diffForHumans() : '—' }}
                            @if($bicycle->lastGpsUpdate && !$gpsFresh)
                                <span class="bike-stale">Stale</span>
                            @elseif(!$bicycle->lastGpsUpdate)
                                <span class="bike-unavailable">Unavailable</span>
                            @endif
                        </td>
                    </tr>
                    <tr><th>Current Location</th>
                        <td>
                            @if($bicycle->currentLat && $bicycle->currentLng)
                                {{ $bicycle->currentLat }}, {{ $bicycle->currentLng }}
                            @else
                                <span class="bike-unavailable">—</span>
                            @endif
                        </td>
                    </tr>
                    <tr><th>Current Rider</th><td>{{ $bicycle->currentRiderUser->name ?? ($bicycle->currentRider ?? '—') }}</td></tr>
                    <tr><th>Condition</th><td>{{ ucfirst($bicycle->condition) }}</td></tr>
                </tbody>
            </table>
        </x-admin.card>

        <x-admin.card title="Live Position" :flush="true">
            <div class="bike-map" id="bicycleDetailMap" role="application" aria-label="Live bicycle position map"></div>
        </x-admin.card>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('bicycleDetailMap');
        if (!el || typeof maplibregl === 'undefined') return;

        const center = {
            lat: parseFloat({{ $bicycle->currentLat ?? '7.0990' }}),
            lng: parseFloat({{ $bicycle->currentLng ?? '125.6470' }})
        };

        const map = new maplibregl.Map({
            container: el,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [center.lng, center.lat],
            zoom: 16,
            pitch: 55,
            bearing: -20,
            attributionControl: true
        });

        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.addControl(new maplibregl.FullscreenControl(), 'top-right');

        new maplibregl.Marker({ color: '#e74c3c' })
            .setLngLat([center.lng, center.lat])
            .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(
                '<strong>{{ $bicycle->name }}</strong><br>#{{ $bicycle->serialNumber }}<br>{{ ucfirst($bicycle->status) }}'
            ))
            .addTo(map);
    });
</script>
@endsection
