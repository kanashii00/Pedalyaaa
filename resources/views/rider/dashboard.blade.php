@extends('layouts.rider')

@section('title', 'Rider Dashboard')

@section('content')
<!-- Welcome Banner -->
<div class="rider-welcome mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h4>Welcome, {{ explode(' ', $user->name)[0] }}! <i class="bi bi-bicycle ms-2"></i></h4>
            <p>Ready for your next ride? Find available bicycles near you and start cycling!</p>
            <div class="d-flex gap-2 flex-wrap">
                <a href="{{ route('rider.rentals.create') }}" class="btn btn-light btn-lg fw-semibold"><i class="bi bi-bicycle me-2"></i>Rent Now</a>
                <a href="{{ route('rider.rentals.index') }}" class="btn btn-outline-light btn-lg"><i class="bi bi-clock-history me-2"></i>View History</a>
            </div>
        </div>
        <div class="col-md-4 text-center mt-3 mt-md-0">
            <div class="d-inline-block p-3 rounded-circle" style="background:rgba(255,255,255,0.15);">
                <i class="bi bi-bicycle" style="font-size:4rem;color:rgba(255,255,255,0.9);"></i>
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="rider-stats mb-4">
    <div class="rider-stat-card fade-in-up">
        <div class="stat-icon-lg" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-bicycle"></i></div>
        <div><div class="stat-value">{{ $totalRentals }}</div><div class="stat-label">Total Rentals</div></div>
    </div>
    <div class="rider-stat-card fade-in-up" style="animation-delay:0.1s;">
        <div class="stat-icon-lg" style="background:#E3F2FD;color:#1976D2;"><i class="bi bi-play-circle"></i></div>
        <div><div class="stat-value">{{ $activeRental ? 1 : 0 }}</div><div class="stat-label">Active Rental</div></div>
    </div>
    <div class="rider-stat-card fade-in-up" style="animation-delay:0.2s;">
        <div class="stat-icon-lg" style="background:#FFF3E0;color:#F57C00;"><i class="bi bi-cash"></i></div>
        <div><div class="stat-value">₱{{ number_format($totalSpent, 2) }}</div><div class="stat-label">Total Spent</div></div>
    </div>
    <div class="rider-stat-card fade-in-up" style="animation-delay:0.3s;">
        <div class="stat-icon-lg" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-shield-check"></i></div>
        <div><span class="badge-status badge-verified"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> {{ $user->verified ? 'Verified' : 'Unverified' }}</span><div class="stat-label mt-1">Account Status</div></div>
    </div>
</div>

<div class="row g-4">
    <!-- Active Rental -->
    <div class="col-lg-6">
        <div class="card-pedalya h-100">
            <div class="card-pedalya-header"><span><i class="bi bi-play-circle text-primary me-2"></i><strong>Active Rental</strong></span></div>
            <div class="card-pedalya-body" id="activeRentalCard">
                @if($activeRental)
@php
    $isReturnFlow = in_array($activeRental->status, ['expired', 'awaiting_return', 'overdue_return'], true);
    $penaltyRuleText = null;
    if (in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true)) {
        if ($activeRental->penaltyType === 'per_hour') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' per hour late';
        } elseif ($activeRental->penaltyType === 'per_minute') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' per minute late';
        } elseif ($activeRental->penaltyType === 'fixed') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' fixed late fee';
        } else {
            $penaltyRuleText = '₱' . number_format($lateReturnPenaltyPerHour ?? 20, 2) . ' per hour late';
        }
    }
@endphp
                    <div class="active-rental-card">
                        <div class="row align-items-center">
                            <div class="col-8">
                                <h6 class="mb-1">Bicycle <strong>{{ $activeRental->bicycle->serialNumber ?? 'N/A' }}</strong> - {{ $activeRental->bicycle->name ?? '' }}</h6>
                                <p class="mb-2 text-muted" style="font-size:0.85rem;">Started: {{ $activeRental->startTime->format('M d, g:i A') }}</p>
                                <div class="d-flex gap-3">
                                    <span><i class="bi bi-clock text-primary"></i> <strong id="rentalTimer">0:00:00</strong></span>
                                    <span><i class="bi bi-battery-three-quarters text-success"></i> {{ $activeRental->bicycle->batteryLevel ?? 0 }}%</span>
                                </div>
                                <div id="rentalWarning" class="mt-1" style="{{ $isReturnFlow ? '' : 'display:none;' }}">
                                    @if($activeRental->status === 'overdue_return')
                                        <span class="badge-status" style="background:#FDEBEB;color:#C62828;"><i class="bi bi-exclamation-triangle-fill me-1" style="font-size:0.5rem;"></i> Overdue &mdash; {{ $penaltyRuleText }}</span>
                                        @if($currentPenalty !== null)
                                            <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-cash-stack me-1" style="font-size:0.5rem;"></i> Penalty so far: &#8369;{{ number_format($currentPenalty, 2) }}</span>
                                        @endif
                                    @elseif($activeRental->status === 'awaiting_return')
                                        <span class="badge-status" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-check2-circle me-1" style="font-size:0.5rem;"></i> Awaiting admin return confirmation</span>
                                        @if($penaltyRuleText)
                                            <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-info-circle me-1" style="font-size:0.5rem;"></i> Return by the deadline &mdash; {{ $penaltyRuleText }}</span>
                                        @endif
@else
                                            <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split me-1" style="font-size:0.5rem;"></i> Expired &mdash; an administrator will set your return deadline</span>
                                        @endif
                                    </div>
                                    @if($activeRental->status === 'overdue_return')
                                    <div style="margin-top:10px;background:#FDEBEB;border:1px solid #F5C6C6;border-radius:8px;padding:10px 12px;font-size:12.5px;">
                                        <div class="fw-bold" style="color:#C62828;margin-bottom:6px;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Overdue Summary</div>
                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;">
                                            <span style="color:#6b6b6b;">Overdue Time</span><strong>{{ floor(($overdueMinutes ?? 0) / 60) }}h {{ ($overdueMinutes ?? 0) % 60 }}m</strong>
                                            <span style="color:#6b6b6b;">Penalty Rate</span><strong>{{ $penaltyRuleText }}</strong>
                                            <span style="color:#6b6b6b;">Current Penalty</span><strong style="color:#C62828;">&#8369;{{ number_format($currentPenalty ?? 0, 2) }}</strong>
                                            <span style="color:#6b6b6b;">Original Rental Fee</span><strong>&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</strong>
                                            <span style="color:#6b6b6b;">Total Amount Due</span><strong style="color:#C62828;">&#8369;{{ number_format(($activeRental->totalFee ?? 0) + ($currentPenalty ?? 0), 2) }}</strong>
                                        </div>
                                    </div>
                                    @endif
                            </div>
                            <div class="col-4 text-end">
                                <div class="countdown-timer">
                                    <div class="countdown-item"><span class="countdown-value" id="cdHours">0</span><span class="countdown-label">Hrs</span></div>
                                    <div class="countdown-item"><span class="countdown-value" id="cdMins">0</span><span class="countdown-label">Min</span></div>
                                    <div class="countdown-item"><span class="countdown-value" id="cdSecs">00</span><span class="countdown-label">Sec</span></div>
                                </div>
                                @if($activeRental->status === 'expired')
                                    <p class="mt-2 mb-0 fw-bold" style="color:#E65100;">Waiting for deadline</p>
                                @else
                                    <p class="mt-2 mb-0 fw-bold text-primary">&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</p>
                                @endif
                                @if($isReturnFlow)
                                    <form action="{{ route('rider.rentals.return', $activeRental->id) }}" method="POST" class="mt-2">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-pedalya"><i class="bi bi-arrow-return-left me-1"></i>Return Bicycle</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    <div class="text-center py-4">
                        <i class="bi bi-bicycle" style="font-size:3rem;color:var(--gray-300);"></i>
                        <p class="text-muted mt-2 mb-0">No active rental</p>
                        <a href="{{ route('rider.rentals.create') }}" class="btn btn-sm btn-pedalya mt-2"><i class="bi bi-bicycle me-1"></i> Rent Now</a>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Recent History -->
    <div class="col-lg-6">
        <div class="card-pedalya h-100">
            <div class="card-pedalya-header d-flex justify-content-between">
                <span><i class="bi bi-clock-history text-primary me-2"></i><strong>Recent Rentals</strong></span>
                <a href="{{ route('rider.rentals.index') }}" style="font-size:0.82rem;">View All</a>
            </div>
            <div class="card-pedalya-body p-0">
                <table class="table-pedalya mb-0">
                    <thead>
                        <tr>
                            <th>Bicycle</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Cost</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentRentals as $rental)
                            <tr>
                                <td><strong>{{ $rental->bicycle->serialNumber ?? 'N/A' }}</strong></td>
                                <td>{{ $rental->startTime->format('M d') }}</td>
                                <td>{{ $rental->durationMinutes ? floor($rental->durationMinutes / 60) . 'h ' . ($rental->durationMinutes % 60) . 'm' : '—' }}</td>
                                <td>₱{{ number_format($rental->totalFee ?? 0, 2) }}</td>
                                <td>
                                    @if($rental->status === 'active')
                                        <span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>
                                    @elseif($rental->status === 'completed')
                                        <span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>
                                    @else
                                        <span class="badge-status badge-cancelled"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> {{ ucfirst($rental->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No rental history yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Map -->
<div class="card-pedalya mt-4">
    <div class="card-pedalya-header"><span><i class="bi bi-geo-alt-fill text-primary me-2"></i><strong>Nearby Available Bicycles</strong></span></div>
    <div style="height:300px;"><div id="riderMap" style="width:100%;height:100%;"></div></div>
</div>
@endsection

@section('scripts')
@if($activeRental)
@php
    $isReturnFlow = in_array($activeRental->status, ['expired', 'awaiting_return', 'overdue_return'], true);
    $countdownIso = null;
    $overdueReturnFlag = false;
    if (in_array($activeRental->status, ['active', 'overdue'], true)) {
        // Same precedence as RentalService::rentalClockFor() so this countdown
        // can never disagree with the one the on-board LCD is showing.
        $countdownTarget = $activeRental->endTime
            ?? $activeRental->returnDeadline
            ?? $activeRental->expectedEndTime
            ?? optional($activeRental->startTime)->copy()->addMinutes($activeRental->durationMinutes ?? 60);
        $countdownIso = optional($countdownTarget)->toIso8601String();
    } elseif (in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true) && $activeRental->returnDeadline) {
        $countdownIso = $activeRental->returnDeadline->toIso8601String();
        $overdueReturnFlag = ($activeRental->status === 'overdue_return');
    }
    $warningSeconds = ($warningMinutes ?? 5) * 60;
@endphp
<script>
    (function() {
        var target = @json($countdownIso);
        var warningSeconds = {{ $warningSeconds }};
        var overdueReturn = {{ $overdueReturnFlag ? 'true' : 'false' }};
        var endTime = target ? new Date(target) : null;
        var expiredShown = false;

        function pad(n) { return String(n).padStart(2, '0'); }

        function updateTimer() {
            var warn = document.getElementById('rentalWarning');
            var els = {
                timer: document.getElementById('rentalTimer'),
                h: document.getElementById('cdHours'),
                m: document.getElementById('cdMins'),
                s: document.getElementById('cdSecs')
            };

            // A missing element used to throw a TypeError, which killed the
            // setInterval below and left the static 0:00:00 markup on screen
            // with no clue why.
            if (!els.timer || !els.h || !els.m || !els.s) {
                if (!updateTimer.warned) {
                    console.error('[pedalya] rental timer elements missing from the DOM');
                    updateTimer.warned = true;
                }
                return;
            }

            if (!endTime || isNaN(endTime.getTime())) {
                // Deliberate, but it used to be silent, so a mis-set deadline
                // looked identical to a working timer frozen at zero.
                if (!updateTimer.warned) {
                    console.warn('[pedalya] rental timer has no valid target, showing 0:00:00. target =', target);
                    updateTimer.warned = true;
                }
                els.timer.textContent = '0:00:00';
                els.h.textContent = '0';
                els.m.textContent = '0';
                els.s.textContent = '00';
                return;
            }
            var nowMs = Date.now();
            var remaining = Math.max(0, Math.floor((endTime.getTime() - nowMs) / 1000));
            var h = Math.floor(remaining / 3600);
            var m = Math.floor((remaining % 3600) / 60);
            var s = remaining % 60;

            els.timer.textContent = h + ':' + pad(m) + ':' + pad(s);
            els.h.textContent = h;
            els.m.textContent = m;
            els.s.textContent = pad(s);

            if (warn) {
                if (overdueReturn) {
                    warn.style.display = 'block';
                    ['cdHours', 'cdMins', 'cdSecs'].forEach(function(id) {
                        document.getElementById(id).style.color = '#C62828';
                    });
                } else if (remaining > 0 && remaining <= warningSeconds) {
                    warn.style.display = 'block';
                    ['cdHours', 'cdMins', 'cdSecs'].forEach(function(id) {
                        document.getElementById(id).style.color = '#E65100';
                    });
                } else {
                    warn.style.display = 'none';
                }
            }

            if (remaining <= 0 && !expiredShown) {
                expiredShown = true;
                els.timer.textContent = '0:00:00';
            }
        }
        updateTimer();
        setInterval(updateTimer, 1000);
    })();
</script>
@endif

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('riderMap');
        if (!el) return;
        if (typeof maplibregl === 'undefined') {
            el.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 bg-light"><small class="text-muted">Map loading...</small></div>';
            return;
        }
        var bicycles = {!! json_encode($bicycles->filter(fn($b) => $b->currentLat && $b->currentLng)->map(fn($b) => ['lat' => (float) $b->currentLat, 'lng' => (float) $b->currentLng, 'name' => $b->serialNumber . ' - ' . $b->name, 'status' => $b->status])) !!};
        var map = new maplibregl.Map({
            container: el,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: [125.6470, 7.0990],
            zoom: 14,
            attributionControl: true
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        var markers = [];
        bicycles.forEach(function(b) {
            var color = b.status === 'available' ? '#2E7D32' : (b.status === 'rented' ? '#F57C00' : '#D32F2F');
            var marker = new maplibregl.Marker({ color: color })
                .setLngLat([b.lng, b.lat])
                .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(
                    '<div style="padding:6px;font-family:Inter;"><strong>' + b.name + '</strong><br><span style="color:#2E7D32;text-transform:capitalize;">' + b.status + '</span></div>'
                ))
                .addTo(map);
            markers.push([b.lng, b.lat]);
        });
        if (markers.length > 1) {
            map.fitBounds(markers.reduce(function(b, c) {
                b.extend(c);
                return b;
            }, new maplibregl.LngLatBounds(markers[0], markers[0])), { padding: 60 });
        } else if (markers.length === 1) {
            map.setCenter(markers[0]);
        }
    });
</script>
@endsection
