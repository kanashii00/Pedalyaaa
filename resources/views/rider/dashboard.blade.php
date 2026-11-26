@extends('layouts.rider')

@section('title', 'Rider Dashboard')
@section('mapAssets', true)

@section('styles')
<style>
    /* Active Rental live layout: single-column flow so the fixed-width
       countdown, fee, badges and return-confirmation panel can never spill
       over each other in this half-width card. Sections stack with even
       spacing at every viewport; nothing uses fixed positioning. */
    .rental-live-grid { display: block; }
    .rental-live-main { min-width: 0; }
    .rental-live-side { min-width: 0; margin-top: 12px; display: flex; flex-direction: column; align-items: flex-start; text-align: left; }
    .rental-live-side .countdown-timer { flex-wrap: wrap; justify-content: flex-start; }
    @media (max-width: 420px) {
        .rental-live-side .countdown-item .countdown-value { padding: 8px 10px; min-width: 48px; }
    }
</style>
@endsection

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
                <img src="{{ asset('assets/img/BIKE.png') }}" alt="Bicycle" width="64" height="64" style="width:64px;height:64px;object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div class="rider-stats mb-4">
    <div class="rider-stat-card fade-in-up">
        <div class="stat-icon-lg" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-bicycle"></i></div>
        <div><div class="stat-value" id="statTotalRentals">{{ $totalRentals }}</div><div class="stat-label">Total Rentals</div></div>
    </div>
    <div class="rider-stat-card fade-in-up" style="animation-delay:0.1s;">
        <div class="stat-icon-lg" style="background:#E3F2FD;color:#1976D2;"><i class="bi bi-play-circle"></i></div>
        <div><div class="stat-value" id="statActiveRental">{{ $activeRental ? 1 : 0 }}</div><div class="stat-label">Active Rental</div></div>
    </div>
    <div class="rider-stat-card fade-in-up" style="animation-delay:0.2s;">
        <div class="stat-icon-lg" style="background:#FFF3E0;color:#F57C00;"><i class="bi bi-cash"></i></div>
        <div><div class="stat-value" id="statTotalSpent">₱{{ number_format($totalSpent, 2) }}</div><div class="stat-label">Total Spent</div></div>
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
    // Persisted pending state: once the rider submits the return the rental
    // carries returnRequestedAt (and/or sits in awaiting_return /
    // overdue_return) until an admin confirms it. While set, the Return
    // Bicycle button stays hidden so another request cannot be submitted.
    $returnSubmitted = $activeRental->returnRequestedAt !== null
        || in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true);
    $penaltyRuleText = '₱20 per completed 5-minute block after the original rental end time';
@endphp
                    <div class="active-rental-card">
                        <div class="rental-live-grid">
                            <div class="rental-live-main">
                                <h6 class="mb-1">Bicycle <strong>{{ $activeRental->bicycle->serialNumber ?? 'N/A' }}</strong> - {{ $activeRental->bicycle->name ?? '' }}</h6>
                                <p class="mb-2 text-muted" style="font-size:0.85rem;" id="rentalStartedLine">Started: {{ $activeRental->startTime->format('M d, g:i A') }}</p>
                                @if($activeRental->returnRequestedAt !== null)
                                    <p class="mb-2 text-muted" style="font-size:0.85rem;" id="returnSubmittedAtLine">Return Submitted At: <strong id="returnSubmittedAtValue">{{ $activeRental->returnRequestedAt->format('M d, g:i A') }}</strong></p>
                                @endif
                                <div class="d-flex gap-3 flex-wrap">
                                    <span><i class="bi bi-clock text-primary"></i> <strong id="rentalTimer">0:00:00</strong></span>
                                    <span>
                                        <i class="bi bi-battery-three-quarters text-success"></i>
                                        {{ $activeRental->bicycle->batteryLevel !== null ? $activeRental->bicycle->batteryLevel . '%' : 'Battery not reported' }}
                                    </span>
                                </div>
                                <div id="rentalWarning" class="mt-1" style="{{ $isReturnFlow ? '' : 'display:none;' }}">
                                    @if($activeRental->status === 'overdue_return')
                                        <span class="badge-status" style="background:#FDEBEB;color:#C62828;"><i class="bi bi-exclamation-triangle-fill me-1" style="font-size:0.5rem;"></i> Overdue &mdash; {{ $penaltyRuleText }}</span>
                                        @if($currentPenalty !== null)
                                            <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-cash-stack me-1" style="font-size:0.5rem;"></i> Penalty so far: &#8369;{{ number_format($currentPenalty, 2) }}</span>
                                        @endif
                                    @elseif($activeRental->status === 'awaiting_return')
                                        <span class="badge-status" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-check2-circle me-1" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>
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
                            <div class="rental-live-side">
                                <div class="countdown-timer">
                                    <div class="countdown-item"><span class="countdown-value" id="cdHours">0</span><span class="countdown-label">Hrs</span></div>
                                    <div class="countdown-item"><span class="countdown-value" id="cdMins">0</span><span class="countdown-label">Min</span></div>
                                    <div class="countdown-item"><span class="countdown-value" id="cdSecs">00</span><span class="countdown-label">Sec</span></div>
                                </div>
                                @if($activeRental->status === 'expired')
                                    <p class="mt-2 mb-0 fw-bold" id="deadlineNoteLine" style="color:#E65100;">Waiting for deadline</p>
                                    <p class="mt-2 mb-0 fw-bold text-primary" id="rentalFeeLine" hidden style="display:none;">&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</p>
                                @else
                                    <p class="mt-2 mb-0 fw-bold" id="deadlineNoteLine" style="color:#E65100;display:none;" hidden>Waiting for deadline</p>
                                    <p class="mt-2 mb-0 fw-bold text-primary" id="rentalFeeLine">&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</p>
                                @endif
                                @if($activeRental->hasRideExtensions())
                                    <span class="badge-status mt-1" style="background:#E3F2FD;color:#1976D2;" title="This ride was extended {{ $activeRental->extensionCount }} time(s) by {{ $activeRental->extendedMinutes }} extra minutes ({{ $activeRental->extensionMinutesLabel() }}) worth &#8369;{{ number_format((float) $activeRental->extensionFee, 2) }}.">
                                        <i class="bi bi-plus-circle me-1" style="font-size:0.5rem;"></i>Extended &times;{{ $activeRental->extensionCount }} ({{ $activeRental->extensionMinutesLabel() }})
                                    </span>
                                @endif
                                @php
                                    // The same ceiling the server enforces: an extension can never
                                    // push the rental past the configured maximum rental length.
                                    $extMaxMinutes = (int) \App\Models\SystemSetting::getValue('rentalMaxDurationHours', 12) * 60;
                                    $extRate = (float) ($activeRental->ratePerHour ?? 15);
                                    $extOptions = array_values(array_filter([30, 60, 120, 180], function ($m) use ($activeRental, $extMaxMinutes) {
                                        return ($activeRental->durationMinutes ?? 0) + $m <= $extMaxMinutes;
                                    }));
                                @endphp
                                {{-- All three action states are rendered so the countdown script can
                                     swap between them in place when the deadline passes, without
                                     a page reload. Visibility is server-driven on first paint;
                                     the return form stays disabled until its state is confirmed
                                     (server render, or a live status refetch at zero). --}}
                                <div class="mt-2" id="extendActionWrap"@if(!($extOptions && in_array($activeRental->status, ['active', 'expired'], true) && !$returnSubmitted)) hidden style="display:none;"@endif>
                                    @if($extOptions)
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#extendRideModal">
                                            <i class="bi bi-plus-circle me-1"></i>Extend Ride
                                        </button>
                                    @endif
                                </div>
                                <div class="mt-2 p-2 rounded border" id="pendingReturnBox" role="status" style="background:#FFF8E1;border-color:#FFE0B2 !important;text-align:left;@if(!$returnSubmitted)display:none;@endif"@if(!$returnSubmitted) hidden@endif>
                                    <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split me-1" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>
                                    <p class="mb-1 mt-1 fw-bold" style="font-size:0.8rem;color:#E65100;">Return submitted &mdash; awaiting admin confirmation.</p>
                                    <p class="mb-0 text-muted" style="font-size:0.78rem;">Your return request has been submitted. Please wait for an administrator to inspect the bicycle and confirm the actual arrival time.</p>
                                </div>
                                <form action="{{ route('rider.rentals.return', $activeRental->id) }}" method="POST" class="mt-2" id="returnActionForm" data-return-form@if(!($isReturnFlow && !$returnSubmitted)) hidden style="display:none;"@endif>
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-pedalya" data-return-button@if(!($isReturnFlow && !$returnSubmitted)) disabled@endif><i class="bi bi-arrow-return-left me-1"></i>Return Bicycle</button>
                                </form>
                                {{-- Static warning badges the countdown clones when the rental
                                     transitions client-side (active -> expired / awaiting). --}}
                                <template id="expiredWarningTemplate">
                                    <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split me-1" style="font-size:0.5rem;"></i> Expired &mdash; an administrator will set your return deadline</span>
                                </template>
                                <template id="awaitingWarningTemplate">
                                    <span class="badge-status" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-check2-circle me-1" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>
                                </template>
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

    {{-- Settled-state markup for the return watcher: byte-identical to the
         no-rental branch above so the in-place transition matches a reload. --}}
    <template id="noActiveRentalTemplate">
        <div class="text-center py-4">
            <i class="bi bi-bicycle" style="font-size:3rem;color:var(--gray-300);"></i>
            <p class="text-muted mt-2 mb-0">No active rental</p>
            <a href="{{ route('rider.rentals.create') }}" class="btn btn-sm btn-pedalya mt-2"><i class="bi bi-bicycle me-1"></i> Rent Now</a>
        </div>
    </template>

    <!-- Recent History -->
    <div class="col-lg-6">
        <div class="card-pedalya h-100 recent-rentals-card">
            <div class="card-pedalya-header d-flex justify-content-between">
                <span><i class="bi bi-clock-history text-primary me-2"></i><strong>Recent Rentals</strong></span>
                <a href="{{ route('rider.rentals.index') }}" style="font-size:0.82rem;">View All</a>
            </div>
            <div class="card-pedalya-body p-0 recent-rentals-body">
                <div class="recent-rentals-scroll" aria-label="Recent rentals table scroll area">
                    <table class="table-pedalya mb-0 recent-rentals-table">
                        <thead>
                            <tr>
                                <th>Bicycle</th>
                                <th>Date</th>
                                <th>Duration</th>
                                <th>Cost</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="recentRentalsBody">
                            @forelse($recentRentals as $rental)
                                <tr>
                                    <td><strong>{{ $rental->bicycle->serialNumber ?? 'N/A' }}</strong></td>
                                    <td>{{ $rental->startTime->format('M d') }}</td>
                                    <td>{{ $rental->durationMinutes ? floor($rental->durationMinutes / 60) . 'h ' . ($rental->durationMinutes % 60) . 'm' : '—' }}</td>
                                    <td>₱{{ number_format($rental->finalAmountCollected(), 2) }}</td>
                                    <td>
                                        @if($rental->status === 'active')
                                            <span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>
                                        @elseif($rental->status === 'completed')
                                            <span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>
                                        @elseif(in_array($rental->status, ['awaiting_return', 'overdue_return'], true))
                                            <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>
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
</div>

<!-- Operating Area -->
@php
    // Zone wording and colour are defined once here so the badge, the readout and
    // the legend can never end up describing the same state differently.
    $zoneTone = function (?string $level): array {
        return match ($level) {
            'safe' => ['label' => 'Inside riding area', 'icon' => 'bi-check-circle-fill', 'color' => '#2E7D32'],
            'warning', 'approaching' => ['label' => 'Near the boundary', 'icon' => 'bi-exclamation-triangle-fill', 'color' => '#F57C00'],
            'breach', 'outside' => ['label' => 'Outside riding area', 'icon' => 'bi-x-octagon-fill', 'color' => '#C62828'],
            default => ['label' => 'Waiting for GPS signal', 'icon' => 'bi-hourglass-split', 'color' => '#78909C'],
        };
    };
    // With a live rental the map holds exactly one bicycle - the one being ridden -
    // so this is the rider's own bike. Without one it is the available catalogue.
    $ownBike = $activeRental ? ($mapBicycles[0] ?? null) : null;
    $zone = $zoneTone($ownBike['zone'] ?? null);
    $initialDistance = $ownBike['distanceToBoundary'] ?? null;
@endphp
<div class="card-pedalya mt-4">
    <div class="card-pedalya-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-geo-alt-fill text-primary me-2"></i>
            <strong id="ridingAreaTitle">{{ $activeRental ? 'Your Riding Area' : 'Riding Area' }}</strong>
        </span>
        @if($activeRental)
        <span class="d-flex align-items-center gap-2" id="zoneBadgeWrap">
            <span id="zoneBadge" class="badge rounded-pill px-3 py-2" style="background:{{ $zone['color'] }};font-size:0.78rem;">
                <i class="bi {{ $zone['icon'] }} me-1"></i><span id="zoneBadgeText">{{ $zone['label'] }}</span>
            </span>
            <button type="button" id="centerMapBtn" class="btn btn-sm btn-outline-secondary" title="Center on my bicycle">
                <i class="bi bi-crosshair me-1"></i> Center
            </button>
        </span>
        @endif
    </div>
    <div style="height:{{ $activeRental ? '380px' : '300px' }};"><div id="riderMap" style="width:100%;height:100%;"></div></div>
    @if($activeRental)
    <div class="card-pedalya-body border-top">
        <div class="row g-3">
            <div class="col-sm-4">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Your bicycle</div>
                <strong id="zoneBikeName">{{ $ownBike['name'] ?? '—' }}</strong>
            </div>
            <div class="col-sm-4">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Position</div>
                <strong id="zoneStatusText">{{ $zone['label'] }}</strong>
            </div>
            <div class="col-sm-4">
                <div class="text-muted" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">Distance to boundary</div>
                <strong id="zoneDistanceText">{{ $initialDistance !== null ? number_format((float) $initialDistance, 0) . ' m' : '—' }}</strong>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-3" style="font-size:0.8rem;">
            <span class="d-inline-flex align-items-center gap-2"><span style="width:12px;height:12px;border-radius:50%;background:#2ecc71;display:inline-block;"></span> Inside riding area</span>
            <span class="d-inline-flex align-items-center gap-2"><span style="width:12px;height:12px;border-radius:50%;background:#f39c12;display:inline-block;"></span> Near the boundary</span>
            <span class="d-inline-flex align-items-center gap-2"><span style="width:12px;height:12px;border-radius:50%;background:#e74c3c;display:inline-block;"></span> Outside riding area</span>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:0.78rem;">
            <i class="bi bi-info-circle me-1"></i>Please ride inside the shaded area. The boundary is set by the operator and updates automatically, so always follow the shaded region on this map.
        </p>
    </div>
    @endif
</div>

{{-- Extend Ride: buy extra time on the ongoing ride. The options, the fee per
     option and the maximum length are all derived from the same settings the
     server re-checks, so the price a rider sees here is the price recorded. --}}
@if($activeRental && $extOptions && in_array($activeRental->status, ['active', 'expired'], true))
<div class="modal fade" id="extendRideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" action="{{ route('rider.rentals.extend', $activeRental->id) }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Extend Ride</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" style="font-size:0.88rem;color:#6b6b6b;">
                    Bicycle <strong>{{ $activeRental->bicycle->serialNumber ?? 'N/A' }}</strong> &middot;
                    current fee <strong>&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</strong>.
                    Extra time is charged at <strong>&#8369;{{ number_format($extRate, 2) }}/hour</strong>
                    (part of an hour counts as a full hour) and is added to your rental fee, payable on return.
                </p>
                <div class="d-grid gap-2">
                    @foreach($extOptions as $extMinutes)
                        @php
                            $extHours = max((int) ceil($extMinutes / 60), 1);
                            $extFee = round($extRate * $extHours, 2);
                            $extLabel = $extMinutes < 60
                                ? $extMinutes . ' minutes'
                                : ($extMinutes % 60 === 0
                                    ? ($extMinutes / 60) . ' hour' . ((int) ($extMinutes / 60) > 1 ? 's' : '')
                                    : intdiv($extMinutes, 60) . 'h ' . ($extMinutes % 60) . 'm');
                        @endphp
                        <label class="d-flex justify-content-between align-items-center rounded border p-2 mb-0" style="cursor:pointer;font-size:0.9rem;">
                            <span>
                                <input type="radio" name="extensionMinutes" value="{{ $extMinutes }}" required class="me-2">
                                <strong>+ {{ $extLabel }}</strong>
                            </span>
                            <span class="fw-bold">+&#8369;{{ number_format($extFee, 2) }}</span>
                        </label>
                    @endforeach
                </div>
                <small class="text-muted d-block mt-3">
                    <i class="bi bi-info-circle me-1"></i>Extending also updates your countdown, and the extension shows on your receipt.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i>Confirm Extension</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@section('scripts')
<script src="{{ asset('js/geolibre-map.js') }}"></script>
<script>
    (function() {
        // Return watcher: while a return awaits admin confirmation the card
        // would sit stale until a manual reload, because the confirmation
        // happens in a separate (admin) session. Poll the authoritative
        // dashboard state and swap to the normal UI in place when the rental
        // settles. Singleton-guarded; fully torn down after settling.
        if (window.PedalyaReturnWatch) {
            window.PedalyaReturnWatch.destroy();
        }
        var stateUrl = @json(route('rider.dashboard.state'));
        var hasActiveRental = @json((bool) $activeRental);
        var pending = @json($activeRental ? ($activeRental->returnRequestedAt !== null) : false);
        var settled = false;
        var destroyed = false;
        var inflight = false;
        var pollTimer = 0;
        var echoConfirmSeen = false;
        var POLL_MS = 12000;
        var RENTAL_TYPES = ['rental_started', 'rental_approved', 'rental_returned',
            'return_requested', 'rental_completed', 'rental_expired',
            'rental_overdue_return', 'rental_deadline', 'rental_extended'];

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s === null || s === undefined ? '' : String(s);
            return d.innerHTML;
        }
        function money(n) {
            return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function setText(id, value) {
            var node = document.getElementById(id);
            if (node) node.textContent = value;
        }

        function badgeFor(status) {
            if (status === 'active') {
                return '<span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>';
            }
            if (status === 'completed') {
                return '<span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>';
            }
            if (status === 'awaiting_return' || status === 'overdue_return') {
                return '<span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>';
            }
            var label = esc(status.charAt(0).toUpperCase() + status.slice(1));
            return '<span class="badge-status badge-cancelled"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> ' + label + '</span>';
        }

        function renderRecent(rows) {
            var tbody = document.getElementById('recentRentalsBody');
            if (!tbody) return;
            if (!rows || !rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No rental history yet</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(function (r) {
                return '<tr>' +
                    '<td><strong>' + esc(r.serial) + '</strong></td>' +
                    '<td>' + esc(r.dateLabel) + '</td>' +
                    '<td>' + esc(r.durationLabel) + '</td>' +
                    '<td>' + money(r.cost) + '</td>' +
                    '<td>' + badgeFor(r.status) + '</td>' +
                '</tr>';
            }).join('');
        }

        function settleDashboard(data) {
            settled = true;
            stop();
            if (window.PedalyaRentalCountdown) {
                try { window.PedalyaRentalCountdown.destroy(); } catch (e) { /* already gone */ }
            }
            // Active card -> normal empty state (same markup as a reload).
            var card = document.getElementById('activeRentalCard');
            var tpl = document.getElementById('noActiveRentalTemplate');
            if (card && tpl && ('content' in tpl)) {
                card.innerHTML = '';
                card.appendChild(tpl.content.cloneNode(true));
            }
            // Stats: active count to 0, totals refreshed from the backend.
            setText('statTotalRentals', String(data.stats.totalRentals));
            setText('statActiveRental', String(data.stats.activeCount));
            setText('statTotalSpent', money(data.stats.totalSpent));
            renderRecent(data.recentRentals);
            // Map header back to the pre-rental state.
            setText('ridingAreaTitle', 'Riding Area');
            var badgeWrap = document.getElementById('zoneBadgeWrap');
            if (badgeWrap) badgeWrap.style.display = 'none';
            if (window.PedalyaNotifications && window.PedalyaNotifications.refreshBadge) {
                try { window.PedalyaNotifications.refreshBadge(); } catch (e) { /* badge keeps stale count */ }
            }
            // The Echo toast already announced confirmations that arrived
            // live; only announce ones discovered by polling.
            if (!echoConfirmSeen && window.PedalyaNotifications && window.PedalyaNotifications.toast) {
                var fee = data.lastSettled ? money(data.lastSettled.finalFee) : null;
                var msg = data.lastSettled
                    ? 'Your rental ' + data.lastSettled.rentalId + ' has been confirmed. Final fee: ' + fee + '.'
                    : 'Your bicycle return has been confirmed by an administrator.';
                try { window.PedalyaNotifications.toast(msg, 'success', 'Return confirmed'); } catch (e) { /* toast is best-effort */ }
            }
        }

        function applyState(data) {
            inflight = false;
            if (destroyed || settled || !data) return;
            // Backend is the source of truth: only a settled (non-live)
            // rental may clear the card — never a clock or a guess.
            if (hasActiveRental && data.activeRental === null) {
                settleDashboard(data);
            }
        }

        function checkState() {
            if (destroyed || settled || inflight || !stateUrl) return;
            // Nothing to watch until a return is actually submitted; the
            // countdown module owns the pre-return UI.
            if (!pending) return;
            // Keep the cadence alive no matter which path armed the watcher
            // (initial pending render, submit elsewhere, live event).
            startPolling();
            inflight = true;
            fetch(stateUrl + (stateUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(applyState)
                .catch(function () {
                    inflight = false;
                    // Next poll / visibility event retries; never loops hot.
                });
        }

        function startPolling() {
            if (pollTimer || destroyed || settled) return;
            pollTimer = setInterval(function () {
                if (!document.hidden) checkState();
            }, POLL_MS);
        }

        function stop() {
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = 0;
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('pageshow', onPageShow);
            window.removeEventListener('pedalya:notifications-changed', onNotifications);
        }

        function onVisibility() {
            if (!document.hidden && !destroyed && !settled && pending) checkState();
        }

        function onPageShow(event) {
            if (!destroyed && !settled && pending && event && event.persisted) checkState();
        }

        function onNotifications(event) {
            var type = event && event.detail && event.detail.type;
            if (type === 'rental_completed') echoConfirmSeen = true;
            if (type && RENTAL_TYPES.indexOf(type) !== -1) {
                // A return submitted from another tab still arms the watcher.
                if (type === 'rental_returned' || type === 'return_requested') pending = true;
                checkState();
            }
        }

        function destroy() {
            destroyed = true;
            stop();
            if (window.PedalyaReturnWatch === api) window.PedalyaReturnWatch = null;
        }

        var api = { destroy: destroy, check: checkState };
        window.PedalyaReturnWatch = api;
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('pageshow', onPageShow);
        window.addEventListener('pedalya:notifications-changed', onNotifications);
        // Arm immediately when the page already renders the pending state;
        // otherwise the watcher idles until a return is submitted.
        if (hasActiveRental && pending) {
            checkState();
            startPolling();
        }
    })();
</script>
@if($activeRental)
@php
    $isReturnFlow = in_array($activeRental->status, ['expired', 'awaiting_return', 'overdue_return'], true);
    $countdownIso = null;
    $overdueReturnFlag = false;
    if (in_array($activeRental->status, ['active', 'overdue'], true)) {
        // Same precedence as RentalService::rentalClockFor() so this countdown
        // can never disagree with the one the on-board LCD is showing.
        $countdownTarget = $activeRental->returnDeadline
            ?? $activeRental->expectedEndTime
            ?? $activeRental->endTime
            ?? optional($activeRental->startTime)->copy()->addMinutes($activeRental->durationMinutes ?? 60);
        $countdownIso = optional($countdownTarget)->toIso8601String();
    } elseif (in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true) && $activeRental->returnDeadline) {
        $countdownIso = $activeRental->returnDeadline->toIso8601String();
        $overdueReturnFlag = ($activeRental->status === 'overdue_return');
    }
    $warningSeconds = ($warningMinutes ?? 5) * 60;
    $countdownServerTimeIso = now()->toIso8601String();
    $countdownStatusUrl = route('rider.rentals.active-status');
    $countdownInitialStatus = $activeRental->status;
    $countdownReturnSubmitted = $activeRental->returnRequestedAt !== null;
@endphp
<script>
    (function() {
        // Singleton: a soft navigation can evaluate this block twice, and two
        // intervals would double-tick the display and double-fire the sync.
        if (window.PedalyaRentalCountdown) {
            window.PedalyaRentalCountdown.destroy();
        }
        var target = @json($countdownIso);
        var warningSeconds = {{ $warningSeconds }};
        var overdueReturn = {{ $overdueReturnFlag ? 'true' : 'false' }};
        var initialStatus = @json($countdownInitialStatus);
        var initialReturnSubmitted = @json($countdownReturnSubmitted);
        var statusUrl = @json($countdownStatusUrl);
        var serverTimeIso = @json($countdownServerTimeIso);
        var endTime = target ? new Date(target) : null;
        var expiredShown = false;

        // Client-clock correction: the countdown measures against the
        // authoritative server clock, not the device clock. The offset is
        // captured at render and refreshed on every status refetch.
        var serverOffsetMs = 0;
        try {
            var renderServerMs = Date.parse(serverTimeIso);
            if (!isNaN(renderServerMs)) serverOffsetMs = renderServerMs - Date.now();
        } catch (offsetErr) { /* device clock stands in */ }

        var synced = false;
        var syncing = false;
        var retryTimer = 0;
        var interval = 0;
        var destroyed = false;

        function pad(n) { return String(n).padStart(2, '0'); }
        function nowMs() { return Date.now() + serverOffsetMs; }
        function byId(id) { return document.getElementById(id); }
        function show(el) { if (el) { el.hidden = false; el.style.display = ''; } }
        function hide(el) { if (el) { el.hidden = true; el.style.display = 'none'; } }

        function remainingSeconds() {
            if (!endTime || isNaN(endTime.getTime())) return null;
            return Math.max(0, Math.floor((endTime.getTime() - nowMs()) / 1000));
        }

        // Only an active/overdue ride with no submitted return can transition
        // into the return flow. Every other state already renders its final
        // actions server-side, so no refetch is ever needed for it.
        function needsSync() {
            if (synced || syncing || initialReturnSubmitted) return false;
            return initialStatus === 'active' || initialStatus === 'overdue';
        }

        function cloneTemplate(id) {
            var tpl = byId(id);
            if (!tpl || !('content' in tpl)) return null;
            return tpl.content.cloneNode(true);
        }

        function showReturnForm(status) {
            var form = byId('returnActionForm');
            var pending = byId('pendingReturnBox');
            hide(pending);
            show(form);
            // The button stays disabled until this backend confirmation lands,
            // so a crafted clock can never enable a return the server forbids.
            if (form) {
                var button = form.querySelector('[data-return-button]');
                if (button) button.disabled = false;
            }
            if (status === 'expired') {
                hide(byId('rentalFeeLine'));
                show(byId('deadlineNoteLine'));
                var warn = byId('rentalWarning');
                if (warn) {
                    var frag = cloneTemplate('expiredWarningTemplate');
                    if (frag) {
                        warn.innerHTML = '';
                        warn.appendChild(frag);
                    }
                    warn.style.display = 'block';
                }
            } else {
                var awaitingWarn = byId('rentalWarning');
                if (awaitingWarn) {
                    var awaitingFrag = cloneTemplate('awaitingWarningTemplate');
                    if (awaitingFrag) {
                        awaitingWarn.innerHTML = '';
                        awaitingWarn.appendChild(awaitingFrag);
                    }
                    awaitingWarn.style.display = 'block';
                }
            }
        }

        function showPendingBox() {
            hide(byId('returnActionForm'));
            hide(byId('extendActionWrap'));
            show(byId('pendingReturnBox'));
            var warn = byId('rentalWarning');
            if (warn) {
                var frag = cloneTemplate('awaitingWarningTemplate');
                if (frag) {
                    warn.innerHTML = '';
                    warn.appendChild(frag);
                }
                warn.style.display = 'block';
            }
        }

        // "Return Submitted At" sits directly below the Started line. A return
        // submitted from another tab/device updates this card in place, so the
        // line is created (or refreshed) from the authoritative payload rather
        // than relying on the next full page load.
        function setReturnSubmittedAt(iso) {
            var d = new Date(iso);
            if (isNaN(d.getTime())) return;
            var line = byId('returnSubmittedAtLine');
            if (!line) {
                var started = byId('rentalStartedLine');
                if (!started || !started.parentNode) return;
                line = document.createElement('p');
                line.id = 'returnSubmittedAtLine';
                line.className = 'mb-2 text-muted';
                line.style.fontSize = '0.85rem';
                line.innerHTML = 'Return Submitted At: <strong id="returnSubmittedAtValue"></strong>';
                started.parentNode.insertBefore(line, started.nextSibling);
            }
            var value = byId('returnSubmittedAtValue');
            if (value) {
                value.textContent = d.toLocaleString('en-PH', {
                    timeZone: 'Asia/Manila', month: 'short', day: 'numeric',
                    hour: 'numeric', minute: '2-digit',
                });
            }
        }

        function applyStatus(payload) {
            syncing = false;
            if (destroyed) return;
            try {
                var serverMs = Date.parse(payload && payload.serverTime);
                if (!isNaN(serverMs)) serverOffsetMs = serverMs - Date.now();
            } catch (clockErr) { /* keep the previous offset */ }
            var rental = payload && payload.rental;
            if (!rental) {
                // Nothing live anymore (settled elsewhere): freeze at zero.
                synced = true;
                updateTimer();
                return;
            }
            if (rental.deadline) {
                var next = new Date(rental.deadline);
                if (!isNaN(next.getTime()) && (!endTime || next.getTime() !== endTime.getTime())) {
                    endTime = next;
                    target = rental.deadline;
                }
            }
            initialStatus = rental.status;
            initialReturnSubmitted = !!rental.returnSubmitted;
            if (rental.returnRequestedAt) setReturnSubmittedAt(rental.returnRequestedAt);
            var remaining = remainingSeconds();
            if (remaining !== null && remaining > 0) {
                // Extended in another tab, or the clock correction moved the
                // deadline back into the future: keep counting and allow a
                // fresh sync when the new deadline arrives.
                expiredShown = false;
                synced = false;
                updateTimer();
                return;
            }
            synced = true;
            if (rental.returnSubmitted) {
                showPendingBox();
            } else if (rental.canReturn) {
                showReturnForm(rental.status);
            }
            if (!rental.canExtend) {
                hide(byId('extendActionWrap'));
            }
            updateTimer();
        }

        function fetchStatus() {
            if (syncing || synced || destroyed) return;
            if (!statusUrl) return;
            syncing = true;
            var url = statusUrl + (statusUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(applyStatus)
                .catch(function (err) {
                    syncing = false;
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('[pedalya] rental status sync failed, will retry once', err);
                    }
                    // One retry, not a poll loop: the next paint already shows
                    // 0:00:00, and any navigation re-renders from the server.
                    if (!synced && !destroyed && !retryTimer) {
                        retryTimer = setTimeout(function () {
                            retryTimer = 0;
                            fetchStatus();
                        }, 5000);
                    }
                });
        }

        function updateTimer() {
            var warn = byId('rentalWarning');
            var els = {
                timer: byId('rentalTimer'),
                h: byId('cdHours'),
                m: byId('cdMins'),
                s: byId('cdSecs')
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
            var remaining = remainingSeconds();
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
                        var node = byId(id);
                        if (node) node.style.color = '#C62828';
                    });
                } else if (remaining > 0 && remaining <= warningSeconds) {
                    warn.style.display = 'block';
                    ['cdHours', 'cdMins', 'cdSecs'].forEach(function(id) {
                        var node = byId(id);
                        if (node) node.style.color = '#E65100';
                    });
                } else if (!expiredShown) {
                    warn.style.display = 'none';
                }
            }

            if (remaining <= 0 && !expiredShown) {
                expiredShown = true;
                els.timer.textContent = '0:00:00';
                // The row has likely transitioned server-side (active ->
                // expired via rental.sync). Re-read the authoritative state
                // once and swap the actions in place: no reload needed.
                if (needsSync()) fetchStatus();
            }
        }

        function onPageShow(event) {
            // Back-forward cache restores a frozen DOM with a stale clock: if
            // the countdown already reads zero and a sync is still due, run it
            // so the actions match the server without a manual refresh.
            if (destroyed) return;
            updateTimer();
            if (event && event.persisted && remainingSeconds() === 0 && needsSync()) {
                fetchStatus();
            }
        }

        function destroy() {
            destroyed = true;
            if (interval) clearInterval(interval);
            if (retryTimer) clearTimeout(retryTimer);
            window.removeEventListener('pageshow', onPageShow);
            if (window.PedalyaRentalCountdown === api) window.PedalyaRentalCountdown = null;
        }

        var api = { destroy: destroy, refresh: fetchStatus };
        window.PedalyaRentalCountdown = api;
        window.addEventListener('pageshow', onPageShow);
        updateTimer();
        interval = setInterval(updateTimer, 1000);
        // No pagehide teardown: the back-forward cache suspends this interval
        // and resumes it on restore (handled by onPageShow above). Tearing it
        // down here would freeze the countdown after a back navigation. A full
        // unload destroys the JS context — and its timers — on its own, while
        // a soft re-evaluation is covered by the singleton guard at the top.
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

        var hasActiveRental = {{ $activeRental ? 'true' : 'false' }};

        // Mirrors the server-side zoneTone() above and the colours the shared map
        // module uses for markers, so badge, readout and pin always agree.
        function zoneTone(level) {
            if (level === 'safe') {
                return { label: 'Inside riding area', icon: 'bi-check-circle-fill', color: '#2E7D32' };
            }
            if (level === 'warning' || level === 'approaching') {
                return { label: 'Near the boundary', icon: 'bi-exclamation-triangle-fill', color: '#F57C00' };
            }
            if (level === 'breach' || level === 'outside') {
                return { label: 'Outside riding area', icon: 'bi-x-octagon-fill', color: '#C62828' };
            }
            return { label: 'Waiting for GPS signal', icon: 'bi-hourglass-split', color: '#78909C' };
        }

        function formatDistance(metres) {
            if (metres === null || metres === undefined || metres === '' || isNaN(metres)) return '—';
            var m = Number(metres);
            if (m >= 1000) return (m / 1000).toFixed(2) + ' km';
            return Math.round(m) + ' m';
        }

        function setText(id, value) {
            var node = document.getElementById(id);
            if (node) node.textContent = value;
        }

        // Accepts either the server-rendered seed shape (zone / distanceToBoundary)
        // or the live payload shape (zone_level / distance_to_boundary).
        function applyZone(bicycle) {
            if (!bicycle) {
                var none = zoneTone(null);
                setText('zoneBadgeText', none.label);
                setText('zoneStatusText', none.label);
                setText('zoneBikeName', 'No active rental');
                setText('zoneDistanceText', '—');
                var emptyBadge = document.getElementById('zoneBadge');
                if (emptyBadge) emptyBadge.style.background = none.color;
                var emptyIcon = emptyBadge && emptyBadge.querySelector('i');
                if (emptyIcon) emptyIcon.className = 'bi ' + none.icon + ' me-1';
                return;
            }

            var level = bicycle.zone_level !== undefined ? bicycle.zone_level : bicycle.zone;
            var distance = bicycle.distance_to_boundary !== undefined
                ? bicycle.distance_to_boundary
                : bicycle.distanceToBoundary;
            var tone = zoneTone(level);

            setText('zoneBadgeText', tone.label);
            setText('zoneStatusText', tone.label);
            setText('zoneBikeName', bicycle.name || '—');
            setText('zoneDistanceText', formatDistance(distance));

            var badge = document.getElementById('zoneBadge');
            if (badge) badge.style.background = tone.color;
            var icon = badge && badge.querySelector('i');
            if (icon) icon.className = 'bi ' + tone.icon + ' me-1';
        }

        // Paint from the server-rendered values first so the badge is correct
        // before the first poll arrives. Only a live rental has a zone to report.
        applyZone(@json($activeRental ? ($mapBicycles[0] ?? null) : null));

        PedalyaGeoLibre.init({
            container: 'riderMap',
            // The same geofence configuration the admin monitoring map draws, so
            // the operating area a rider is shown is always the operator's area.
            geofence: @json($geofence),
            // While a rental is live this list holds exactly one entry: the rider's
            // own bicycle. Other bicycles are never sent to a rider who is riding.
            bicycles: @json($mapBicycles),
            liveUrl: @if($activeRental){{ route('rider.operating-area.live') }}@else null @endif,
            pollMs: 15000,
            // The geofence-alerts channel is an admin feed carrying an event for
            // every bicycle in the fleet, so a rider map must never subscribe to
            // it: polling this rider's own endpoint is the only live source here.
            broadcast: false,
            buttons: @json(['center' => 'centerMapBtn']),
            onLive: function (data) {
                if (!hasActiveRental) return;
                applyZone((data.bicycles && data.bicycles[0]) || null);
            }
        });
    });
</script>

<script>
    // The return POST reloads the page into the persisted pending state, but
    // the button must react the instant it is clicked: disable it at submit
    // time so a double-click can never queue a second return request.
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-return-form]').forEach(function(returnForm) {
            returnForm.addEventListener('submit', function() {
                var button = returnForm.querySelector('[data-return-button]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Return submitted\u2026';
                }
            });
        });
    });
</script>
@endsection
