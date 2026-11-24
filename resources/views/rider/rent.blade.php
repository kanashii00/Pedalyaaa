@extends('layouts.rider')

@section('title', 'Rent Bicycle')

@section('styles')
<style>
    .bicycle-rental-card {
        border: none;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        transition: all 0.3s ease;
    }
    .bicycle-rental-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
    }
    .bicycle-rental-card .card-image {
        background: linear-gradient(135deg, #C8E6C9, #E8F5E9);
        height: 140px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        color: #2E7D32;
    }
    .bicycle-rental-card .card-image img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        padding: 14px;
        display: block;
    }
    .bicycle-rental-card .card-body {
        padding: 16px;
    }
    /* Single-bike demo mode: the rest of the fleet stays visible but inert. */
    .bicycle-rental-card.bicycle-card-unavailable {
        filter: grayscale(1);
        opacity: 0.55;
        box-shadow: none;
    }
    .bicycle-rental-card.bicycle-card-unavailable:hover {
        transform: none;
        box-shadow: none;
    }
    .bicycle-rental-card.bicycle-card-unavailable .card-image {
        background: linear-gradient(135deg, #E0E0E0, #F5F5F5);
    }
    .badge-unavailable {
        background: #E0E0E0;
        color: #757575;
    }
    .bicycle-rental-card .card-details {
        display: flex;
        gap: 12px;
        font-size: 0.85rem;
        color: #666;
    }
    .bg-primary-custom {
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
    }
    .rent-map-wrap {
        height: 350px;
        position: relative;
    }
    .rent-map-wrap:fullscreen {
        height: 100vh;
        background: #1a1a1a;
    }
    .rent-map-wrap:-webkit-full-screen {
        height: 100vh;
        background: #1a1a1a;
    }
    #rentMapMaximize {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
        padding: 4px 10px;
        border-radius: 8px;
        border: 1px solid #d0d7de;
        background: #fff;
        color: #444;
        cursor: pointer;
        transition: all 0.15s;
    }
    #rentMapMaximize:hover { border-color: var(--primary); color: var(--primary); }
    /* Terms acceptance error state: red checkbox + red label text, matching
       the inline validation style used by the duration field above. */
    #rentalTerms.is-invalid {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
    }
    #rentalTerms.is-invalid ~ .form-check-label {
        color: #dc3545 !important;
        font-weight: 600;
    }

    @media (max-width: 767.98px) {
        .modal {
            padding-left: 0;
            padding-right: 0;
            padding-top: max(12px, env(safe-area-inset-top));
            padding-bottom: max(12px, env(safe-area-inset-bottom));
        }

        .modal-dialog {
            width: calc(100vw - 32px);
            max-width: calc(100vw - 32px);
            margin: 0 auto;
        }

        .modal-content {
            border-radius: 18px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.22);
            overflow: hidden;
        }

        .modal-header {
            padding: 12px 16px;
            gap: 10px;
        }

        .modal-header .modal-title {
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-header .btn-close {
            width: 0.8rem;
            height: 0.8rem;
            margin-right: 2px;
            opacity: 0.8;
        }

        .modal-body {
            padding: 14px 16px 12px;
            max-height: min(68vh, 440px);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal-body .d-inline-flex.rounded-circle {
            width: 58px !important;
            height: 58px !important;
            font-size: 1.45rem !important;
        }

        .modal-body h5.mt-2 {
            font-size: 1.05rem;
        }

        .modal-body .bg-light.rounded.p-3.mb-3 {
            padding: 12px !important;
            margin-bottom: 12px !important;
        }

        .modal-body .row.text-center .col-4 {
            padding-left: 4px;
            padding-right: 4px;
        }

        .modal-body .row.text-center small,
        .modal-body .row.text-center strong {
            font-size: 0.76rem;
        }

        .form-label-pedalya {
            font-size: 0.78rem;
            margin-bottom: 6px;
        }

        .modal-body .form-control,
        .modal-body .form-select {
            min-height: 42px;
            font-size: 0.9rem;
        }

        #durationPresets {
            gap: 6px !important;
        }

        #durationPresets .btn {
            padding: 6px 10px;
            min-height: 32px;
            font-size: 0.75rem;
        }

        .modal-body .d-flex.justify-content-between.mt-2 {
            font-size: 0.72rem;
            gap: 6px;
            flex-wrap: wrap;
        }

        .bg-primary-custom.text-white.rounded.p-3.text-center.mb-3 {
            padding: 12px !important;
            margin-bottom: 12px !important;
        }

        .bg-primary-custom.text-white.rounded.p-3.text-center.mb-3 small {
            font-size: 0.72rem;
        }

        .bg-primary-custom.text-white.rounded.p-3.text-center.mb-3 h4 {
            font-size: 1.45rem;
        }

        .modal-body .payment-method-option .text-center.p-3.rounded.border.h-100 {
            padding: 10px !important;
        }

        .modal-body .payment-method-option .text-center.p-3.rounded.border.h-100 i {
            font-size: 1.5rem !important;
        }

        .modal-body .payment-method-option .text-center.p-3.rounded.border.h-100 .fw-bold {
            font-size: 0.8rem !important;
        }

        .modal-body .payment-method-option .text-center.p-3.rounded.border.h-100 small {
            font-size: 0.7rem;
        }

        #rentalTermsWrap {
            margin-bottom: 4px;
        }

        #rentalTermsWrap .form-check-label {
            font-size: 0.76rem;
            line-height: 1.45;
        }

        .modal-footer {
            padding: 12px 16px 14px;
            gap: 8px;
        }

        .modal-footer .btn {
            min-height: 42px;
            flex: 1 1 0;
            font-size: 0.88rem;
        }
    }
</style>
@endsection

@section('content')
<!-- Map -->
<div class="card-pedalya mb-4">
    <div class="card-pedalya-header"><span><i class="bi bi-geo-alt-fill text-primary me-2"></i><strong>Riding Area &amp; Available Bicycles</strong></span><span class="d-flex align-items-center gap-2"><span class="text-muted" style="font-size:0.85rem;">Click a marker to see details</span><button type="button" id="rentMapMaximize" aria-label="Fullscreen map"><i class="bi bi-arrows-fullscreen"></i><span>Fullscreen</span></button></span></div>
    <div class="rent-map-wrap" id="rentMapWrap"><div id="rentMap" style="width:100%;height:100%;"></div></div>
    <div class="card-pedalya-body">
        <p class="text-muted mb-0" style="font-size:0.82rem;">
            <i class="bi bi-info-circle me-1"></i>The shaded green area is where you are allowed to ride. Once your ride starts, your dashboard map shows this same area with a live indicator for whether you are inside it, near the boundary, or outside it.
        </p>
    </div>
</div>

<!-- Bicycle Cards -->
<h5 class="mb-3"><i class="bi bi-collection me-2"></i>Bicycles Near You</h5>
<div class="row g-4">
    @forelse($bicycles as $index => $bicycle)
        @php
            // Three independent gates, and a card is only actionable when all
            // three pass.
            //
            // 1. In service - the unit has controllable hardware. A bicycle
            //    parked in the inventory has no smart lock, so it can never be
            //    rented, whatever status it carries.
            // 2. Live status - re-derived here rather than trusted from the query,
            //    so a bicycle claimed between the query and this render cannot be
            //    offered.
            // 3. Whitelist - the single-bike feature flag.
            //
            // availabilityLabel() owns the wording so this card, the admin
            // inventory and the JSON payload cannot disagree about the same row.
            [$availabilityLabel, $availableForRent] = $bicycle->availabilityLabel();
            $whitelisted = $selectableBicycleIds === [] || isset($selectableBicycleIds[$bicycle->id]);
            $liveAvailable = $bicycle->status === \App\Models\Bicycle::STATUS_AVAILABLE;
            $inService = $bicycle->isInService();
            $isSelectable = $inService && $whitelisted && $liveAvailable && $availableForRent;

            // The badge has to agree with the button. availabilityLabel() only
            // knows about status, so the two gates it does not cover are folded
            // in here - otherwise a bicycle withheld by the feature flag would be
            // badged "Available" while offering nothing to click.
            if ($isSelectable) {
                $availabilityLabel = 'Available';
            } elseif (! $inService) {
                $availabilityLabel = 'Not In Service';
            } elseif (! $whitelisted) {
                $availabilityLabel = 'Unavailable';
            }

            // Rate and battery reach the client as JSON on a data attribute
            // rather than being interpolated into an inline onclick(). A null
            // column would render as an empty argument and break the whole
            // script block, and an unquoted value would render NaN in the cost
            // estimate.
            $bikePayload = json_encode([
                'id' => (int) $bicycle->id,
                'name' => $bicycle->serialNumber . ' - ' . $bicycle->name,
                // Coerced so a null column becomes a number the arithmetic in
                // updateCost() can work with. The rate comes from the same
                // accessor RentalService bills with, so the estimate shown here
                // is the amount that actually gets charged.
                'battery' => (int) ($bicycle->batteryLevel ?? 0),
                'ratePerMinute' => $rentalRatePerMinute,
            ], JSON_THROW_ON_ERROR);
        @endphp
        <div class="col-lg-4 col-md-6">
            <div class="bicycle-rental-card fade-in-up {{ $isSelectable ? '' : 'bicycle-card-unavailable' }}" style="animation-delay:{{ $index * 0.1 }}s;">
                <div class="card-image"><img src="{{ asset('assets/img/BIKE.png') }}" alt="{{ $bicycle->name }} bicycle" class="bicycle-card-img"></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h6>{{ $bicycle->serialNumber }} - {{ $bicycle->name }}</h6>
                        @if($isSelectable)
                            <span class="badge-status badge-available">Available</span>
                        @else
                            <span class="badge-status badge-unavailable">{{ $availabilityLabel }}</span>
                        @endif
                    </div>
                    <p class="text-muted mb-2" style="font-size:0.85rem;">
                        <strong>Category:</strong> {{ $bicycle->model ?? '—' }}
                    </p>
                    <div class="card-details">
                        <span>
                            @if($bicycle->batteryLevel >= 70)
                                <i class="bi bi-battery-full text-success"></i>
                            @elseif($bicycle->batteryLevel >= 40)
                                <i class="bi bi-battery-three-quarters text-success"></i>
                            @else
                                <i class="bi bi-battery-half text-warning"></i>
                            @endif
                            {{ (int) ($bicycle->batteryLevel ?? 0) }}%
                        </span>
                        <span><strong>IoT-enabled:</strong> {{ $bicycle->isInService() ? 'Yes' : 'No' }}</span>
                        <span><strong>₱{{ number_format($rentalRatePerMinute, 2) }}/min</strong></span>
                    </div>
                    @if($isSelectable)
                        <button type="button" class="btn btn-pedalya w-100 justify-content-center mt-3"
                            data-bike-payload="{{ $bikePayload }}"
                            onclick="selectBicycle(this)">
                            <i class="bi bi-key-fill"></i> Select & Rent
                        </button>
                    @else
                        <button type="button" class="btn btn-secondary w-100 justify-content-center mt-3" disabled
                            aria-disabled="true">
                            <i class="bi bi-slash-circle"></i>
                            {{ $availabilityLabel }}
                            @unless($inService)
                                <span class="visually-hidden">(this bicycle is not in service)</span>
                            @endunless
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="text-center py-5">
                <i class="bi bi-bicycle" style="font-size:4rem;color:#ccc;"></i>
                <h5 class="mt-3 text-muted">No bicycles available</h5>
                <p class="text-muted">Check back later or try a different station.</p>
            </div>
        </div>
    @endforelse
</div>

<!-- Rental Confirmation Modal -->
<div class="modal fade" id="rentalModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bicycle text-primary me-2"></i>Confirm Rental</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:70px;height:70px;background:var(--primary);color:#fff;font-size:1.8rem;">
                        <i class="bi bi-bicycle"></i>
                    </div>
                    <h5 class="mt-2 mb-0" id="modalBikeName">Bicycle</h5>
                    <span class="badge-status badge-available">Available</span>
                </div>
                <div class="bg-light rounded p-3 mb-3">
                    <div class="row text-center">
                        <div class="col-4"><small class="text-muted d-block">Battery</small><strong id="modalBikeBattery">0%</strong></div>
                        <div class="col-4"><small class="text-muted d-block">Rate</small><strong id="modalBikeRate">₱1.50/min</strong></div>
                        <div class="col-4"><small class="text-muted d-block">Status</small><strong class="text-success">Available</strong></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label-pedalya" for="durationMinutesInput">Rental Duration (minutes)</label>
                    <input type="number" class="form-control" id="durationMinutesInput"
                           min="1" max="{{ $maxDurationMinutes }}" step="1" value="60"
                           inputmode="numeric" oninput="updateCost()">
                    @error('durationMinutes')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                    <div class="text-danger small mt-1 d-none" id="durationError" role="alert"></div>
                    <div class="d-flex flex-wrap gap-2 mt-2" id="durationPresets">
                        @foreach([15, 30, 45, 60, 90, 120] as $presetMinutes)
                            @if($presetMinutes <= $maxDurationMinutes)
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="setDuration({{ $presetMinutes }})">{{ $presetMinutes }} min</button>
                            @endif
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted">Min 1 minute</small>
                        <strong id="durationDisplay">1 hour</strong>
                        <small class="text-muted">Max {{ $maxDurationMinutes }} min{{ $maxDurationMinutes >= 720 ? ' (12 hours)' : '' }}</small>
                    </div>
                </div>
                <div class="bg-primary-custom text-white rounded p-3 text-center mb-3">
                    <small>Estimated Cost</small>
                    <h4 class="mb-0" id="costDisplay">₱90.00</h4>
                </div>

                <!-- Payment Method -->
                <div class="mb-3">
                    <label class="form-label-pedalya fw-bold">Payment Method</label>
                    <div class="d-flex">
                        <div class="payment-method-option active flex-fill" id="payOptCash">
                            <div class="text-center p-3 rounded border h-100">
                                <i class="bi bi-cash-stack fs-3 text-success"></i>
                                <div class="fw-bold mt-1" style="font-size:0.9rem;">Cash</div>
                                <small class="text-muted">Pay at station</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-check" id="rentalTermsWrap">
                    <input type="checkbox" class="form-check-input" id="rentalTerms" required
                           aria-describedby="rentalTermsError" aria-invalid="false">
                    <label class="form-check-label" for="rentalTerms" style="font-size:0.85rem;color:#666;">I agree to the rental terms and conditions, and will return the bicycle to a designated station.</label>
                </div>
                <div class="text-danger small mt-1 d-none" id="rentalTermsError" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('rider.rent.store') }}" method="POST" id="rentalForm" class="d-inline">
                    @csrf
                    <input type="hidden" name="bicycleId" id="selectedBicycleId" value="">
                    <input type="hidden" name="durationMinutes" id="selectedDuration" value="60">
                    <input type="hidden" name="paymentMethod" id="selectedPaymentMethod" value="cash">
                    <button type="submit" class="btn btn-pedalya" id="confirmRentBtn"><i class="bi bi-key-fill"></i> Start Rental</button>
                </form>
            </div>
        </div>
    </div>
</div>
<style>
    .payment-method-option { cursor: pointer; }
    .payment-method-option .border { transition: all 0.2s; }
    .payment-method-option.active .border,
    .payment-method-option input:checked + .border {
        border-color: var(--primary) !important;
        background: #f0f7ff;
        box-shadow: 0 0 0 2px var(--primary);
    }
</style>
@endsection

@section('scripts')
<script>
    // Rates come from the card's data attribute as a coerced number, so this can
// never be null/undefined and every cost calculation below stays numeric.
    var currentRate = {{ $rentalRatePerMinute }};

    function selectBicycle(trigger) {
        var payload;
        try {
            payload = JSON.parse(trigger.getAttribute('data-bike-payload'));
        } catch (err) {
            return;
        }

        var rate = parseFloat(payload.ratePerMinute);
        if (!isFinite(rate) || isNaN(rate)) rate = 0;

        currentRate = rate;
        document.getElementById('selectedBicycleId').value = payload.id;
        document.getElementById('modalBikeName').textContent = payload.name;
        document.getElementById('modalBikeBattery').textContent = payload.battery + '%';
        document.getElementById('modalBikeRate').textContent = '₱' + rate.toFixed(2) + '/min';
        clearTermsError();
        updateCost();
        new bootstrap.Modal(document.getElementById('rentalModal')).show();
    }

    // Minutes are what the customer picks; hours are only a display format.
    function formatDuration(mins) {
        mins = parseInt(mins, 10) || 0;
        if (mins < 60) return mins + (mins === 1 ? ' minute' : ' minutes');
        var h = Math.floor(mins / 60), m = mins % 60;
        return h + (h === 1 ? ' hour' : ' hours') + (m ? ' ' + m + 'm' : '');
    }

    // A single finite number, whatever the inputs held. Called from every place
    // that produces money so a bad duration or rate can never reach the DOM as
    // "NaN" or "Infinity".
    function money(amount) {
        var n = parseFloat(amount);
        if (!isFinite(n) || isNaN(n)) n = 0;
        return '₱' + n.toFixed(2);
    }

    // Absolute ceiling for one rental, in whole minutes. Rendered from the
    // same server-side cap (RentController::MAX_DURATION_MINUTES) that the
    // backend validates against, so the form can never offer or submit more
    // than the server will accept.
    var maxDurationMinutes = {{ $maxDurationMinutes }};

    // Strict whole-minutes check: digits only, then the 1..max range. Anything
    // else (empty, letters, symbols, decimals, negatives) is invalid and gets
    // an inline message instead of being silently coerced.
    function validateDuration() {
        var raw = (document.getElementById('durationMinutesInput').value || '').toString().trim();

        if (raw === '' || !/^\d+$/.test(raw)) {
            return { valid: false, mins: null, message: 'Please enter a whole number of minutes.' };
        }
        var mins = parseInt(raw, 10);
        if (mins < 1) {
            return { valid: false, mins: mins, message: 'The rental duration must be at least 1 minute.' };
        }
        if (mins > maxDurationMinutes) {
            return {
                valid: false,
                mins: mins,
                message: maxDurationMinutes >= 720
                    ? 'Maximum rental duration is 720 minutes.'
                    : 'Maximum rental duration is ' + maxDurationMinutes + ' minutes.'
            };
        }
        return { valid: true, mins: mins, message: '' };
    }

    function setDuration(mins) {
        document.getElementById('durationMinutesInput').value = mins;
        updateCost();
    }

    function updateCost() {
        var input = document.getElementById('durationMinutesInput');
        var errorBox = document.getElementById('durationError');
        var submitBtn = document.getElementById('confirmRentBtn');
        var check = validateDuration();

        if (!check.valid) {
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            errorBox.textContent = check.message;
            errorBox.classList.remove('d-none');
            submitBtn.disabled = true;
            // Clear every value derived from the previous valid input. This
            // prevents a blank or invalid edit from retaining a stale estimate
            // or posting the former duration through the hidden form field.
            document.getElementById('durationDisplay').textContent = '—';
            document.getElementById('selectedDuration').value = '';
            document.getElementById('costDisplay').textContent = money(0);
            return;
        }

        input.classList.remove('is-invalid');
        input.setAttribute('aria-invalid', 'false');
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
        submitBtn.disabled = false;

        var mins = check.mins;
        document.getElementById('durationDisplay').textContent = formatDuration(mins);

        var rate = parseFloat(currentRate);
        if (!isFinite(rate) || isNaN(rate)) rate = 0;

        var total = mins * rate;

        document.getElementById('selectedDuration').value = mins;
        document.getElementById('costDisplay').textContent = money(total);
    }

    // Sync the error state and the submit button with the default value as
    // soon as the page loads, so the modal can never open in a stale state.
    updateCost();

    // Terms acceptance: inline error instead of a browser popup. The modal
    // stays open with the chosen duration and payment method untouched; the
    // error clears the moment the checkbox is selected.
    function showTermsError() {
        var termsCheckbox = document.getElementById('rentalTerms');
        var termsError = document.getElementById('rentalTermsError');
        termsCheckbox.classList.add('is-invalid');
        termsCheckbox.setAttribute('aria-invalid', 'true');
        termsError.textContent = 'Please accept the rental terms and conditions before starting your rental.';
        termsError.classList.remove('d-none');
    }

    function clearTermsError() {
        var termsCheckbox = document.getElementById('rentalTerms');
        var termsError = document.getElementById('rentalTermsError');
        if (!termsCheckbox || !termsError) {
            return;
        }
        termsCheckbox.classList.remove('is-invalid');
        termsCheckbox.setAttribute('aria-invalid', 'false');
        termsError.textContent = '';
        termsError.classList.add('d-none');
    }

    document.getElementById('rentalTerms').addEventListener('change', function() {
        if (this.checked) {
            clearTermsError();
        }
    });

    // One rental per submission. The button is disabled on the way out and the
    // form is flagged, so a double click (or a second Enter while the first
    // request is still in flight) cannot start two rentals on one bicycle.
    var rentalSubmitting = false;

    document.getElementById('rentalForm').addEventListener('submit', function(e) {
        var form = e.target;

        if (rentalSubmitting || form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }

        // Last line of defence in the browser: re-validate the duration even
        // if the submit was triggered without the disabled button (e.g. an
        // Enter keypress), so an invalid value can never leave this form. The
        // server validates the same 1..720 range again on receipt.
        updateCost();
        if (!validateDuration().valid) {
            e.preventDefault();
            return;
        }

        if (!document.getElementById('selectedBicycleId').value) {
            e.preventDefault();
            alert('Please select a bicycle first');
            return;
        }

        if (!document.getElementById('rentalTerms').checked) {
            e.preventDefault();
            showTermsError();
            document.getElementById('rentalTerms').focus({ preventScroll: false });
            return;
        }

        clearTermsError();

        rentalSubmitting = true;
        form.dataset.submitting = '1';

        var btn = document.getElementById('confirmRentBtn');
        btn.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Processing...';
        btn.disabled = true;
    });

    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('rentMap');
        if (!el) return;
        if (typeof maplibregl === 'undefined') {
            el.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 bg-light"><small class="text-muted">Map loading...</small></div>';
            return;
        }
        // Only genuinely rentable bicycles reach the map: isRentable() is the same
        // rule the cards and the server use, so a bicycle parked out of service
        // can never get a pin a rider could click. Every field is coerced to a
        // number so the popup and selectBicycle() cannot render NaN. Coordinates
        // with a zero component are dropped rather than placed off the coast.
        var bicycles = {!! json_encode($bicycles
            ->filter(fn($b) => $b->isRentable()
                && $b->currentLat !== null && $b->currentLng !== null
                && (float) $b->currentLat !== 0.0 && (float) $b->currentLng !== 0.0)
            ->map(fn($b) => [
                'id' => (int) $b->id,
                'lat' => (float) $b->currentLat,
                'lng' => (float) $b->currentLng,
                'name' => $b->serialNumber . ' ' . $b->name,
                'battery' => (int) ($b->batteryLevel ?? 0),
                'ratePerMinute' => $rentalRatePerMinute,
            ])) !!};
        var hasBikes = bicycles.length > 0;
        // The operator's configured geofence: used as the fallback map centre and
        // as the coordinate source for the shaded operating area below.
        var geofence = @json($geofence);
        var map = new maplibregl.Map({
            container: el,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: hasBikes ? [bicycles[0].lng, bicycles[0].lat] : [geofence.centerLng, geofence.centerLat],
            zoom: 15,
            pitch: 55,
            bearing: -15
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.addControl(new maplibregl.FullscreenControl(), 'top-right');
        map.addControl(new maplibregl.GeolocateControl({
            positionOptions: { enableHighAccuracy: true },
            trackUserLocation: false,
            showUserHeading: true
        }), 'top-right');

        // The operating area, drawn from the operator's geofence using geometry
        // resolved server-side. The polygon arrives pre-built so the shaded region
        // on this map is byte-for-byte the shape the server measures a bicycle
        // against, and no boundary maths is duplicated in the browser.
        var boundary = @json($geofenceGeoJson);
        if (boundary) {
            map.addSource('rental-geofence', { type: 'geojson', data: boundary });
            map.addLayer({
                id: 'rental-geofence-fill',
                type: 'fill',
                source: 'rental-geofence',
                paint: { 'fill-color': '#27ae60', 'fill-opacity': 0.12 }
            });
            map.addLayer({
                id: 'rental-geofence-line',
                type: 'line',
                source: 'rental-geofence',
                paint: { 'line-color': '#1e8449', 'line-width': 3, 'line-dasharray': [0, 2, 2, 2] }
            });
        }
        bicycles.forEach(function(b) {
            var marker = new maplibregl.Marker({ color: '#2ecc71', pitchAlignment: 'auto', rotationAlignment: 'auto' })
                .setLngLat([b.lng, b.lat])
                .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(
                    // Text is escaped: a serial or name is data, not markup.
                    '<div style="padding:6px;font-family:Inter;"><strong>' + escapeHtml(b.name) + '</strong>'
                    + '<br><span style="color:#2ecc71;">Available</span></div>'
                ))
                .addTo(map);
            marker.getElement().addEventListener('click', function() {
                // Goes through the same payload path as the card button, so the
                // modal and the cost estimate behave identically.
                selectBicycle({
                    getAttribute: function() {
                        return JSON.stringify(b);
                    }
                });
            });
        });

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        var wrap = document.getElementById('rentMapWrap');
        var maxBtn = document.getElementById('rentMapMaximize');
        if (wrap && maxBtn) {
            function syncFullscreenIcon() {
                var fs = document.fullscreenElement === wrap;
                maxBtn.innerHTML = fs
                    ? '<i class="bi bi-fullscreen-exit"></i><span>Exit Fullscreen</span>'
                    : '<i class="bi bi-arrows-fullscreen"></i><span>Fullscreen</span>';
            }
            maxBtn.addEventListener('click', function() {
                if (document.fullscreenElement === wrap) {
                    document.exitFullscreen();
                } else if (wrap.requestFullscreen) {
                    wrap.requestFullscreen();
                } else if (wrap.webkitRequestFullscreen) {
                    wrap.webkitRequestFullscreen();
                }
            });
            document.addEventListener('fullscreenchange', syncFullscreenIcon);
            document.addEventListener('webkitfullscreenchange', syncFullscreenIcon);
        }
    });
</script>
@endsection
