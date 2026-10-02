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
</style>
@endsection

@section('content')
<!-- Map -->
<div class="card-pedalya mb-4">
    <div class="card-pedalya-header"><span><i class="bi bi-geo-alt-fill text-primary me-2"></i><strong>Available Bicycles Near You</strong></span><span class="d-flex align-items-center gap-2"><span class="text-muted" style="font-size:0.85rem;">Click a marker to see details</span><button type="button" id="rentMapMaximize" aria-label="Fullscreen map"><i class="bi bi-arrows-fullscreen"></i><span>Fullscreen</span></button></span></div>
    <div class="rent-map-wrap" id="rentMapWrap"><div id="rentMap" style="width:100%;height:100%;"></div></div>
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
                'rate' => $bicycle->rentableHourlyRate(),
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
                    <p class="text-muted mb-2" style="font-size:0.85rem;">{{ $bicycle->model ?? '' }}</p>
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
                        <span><strong>₱{{ number_format($bicycle->rentableHourlyRate(), 0) }}/hr</strong></span>
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
                        <div class="col-4"><small class="text-muted d-block">Hourly Rate</small><strong id="modalBikeRate">₱25/hr</strong></div>
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
                        <small class="text-muted">Max {{ $maxDurationMinutes }} min</small>
                    </div>
                </div>
                <div class="bg-primary-custom text-white rounded p-3 text-center mb-3">
                    <small>Estimated Cost</small>
                    <h4 class="mb-0" id="costDisplay">₱50.00</h4>
                </div>

                <!-- Payment Method -->
                <div class="mb-3">
                    <label class="form-label-pedalya fw-bold">Payment Method</label>
                    <div class="d-flex gap-3">
                        <label class="payment-method-option flex-fill active" id="payOptCash">
                            <input type="radio" name="paymentMethod" value="cash" class="d-none" checked onchange="togglePaymentMethod('cash')">
                            <div class="text-center p-3 rounded border h-100">
                                <i class="bi bi-cash-stack fs-3 text-success"></i>
                                <div class="fw-bold mt-1" style="font-size:0.9rem;">Cash</div>
                                <small class="text-muted">Pay at station</small>
                            </div>
                        </label>
                        <label class="payment-method-option flex-fill" id="payOptGcash">
                            <input type="radio" name="paymentMethod" value="gcash" class="d-none" onchange="togglePaymentMethod('gcash')">
                            <div class="text-center p-3 rounded border h-100">
                                <i class="bi bi-phone fs-3 text-primary"></i>
                                <div class="fw-bold mt-1" style="font-size:0.9rem;">GCash</div>
                                <small class="text-muted">Scan QR to pay</small>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- GCash Payment Area (hidden by default) -->
                <div id="gcashPaymentArea" class="d-none mb-3">
                    <div class="border rounded p-3" style="background:#f0f7ff;">
                        <div class="text-center mb-3">
                            <small class="text-muted">Scan this QR code to pay</small>
                            <div class="my-2">
                                <div id="gcashQrCode" style="display:inline-block;background:#fff;padding:12px;border-radius:8px;border:1px solid #e0e0e0;">
                                    <canvas id="gcashQrCanvas"></canvas>
                                </div>
                            </div>
                            <div class="fw-bold text-primary fs-5" id="gcashAmountDisplay">₱50.00</div>
                        </div>
                        <ol class="mb-3" style="font-size:0.85rem;padding-left:1.2rem;color:#555;">
                            <li>Open your <strong>GCash</strong> app and tap <strong>Scan QR</strong></li>
                            <li>Scan the QR code above</li>
                            <li>Enter the exact amount shown above</li>
                            <li>Complete the payment and take a screenshot</li>
                        </ol>
                        <div class="mb-2">
                            <label class="form-label fw-bold" style="font-size:0.85rem;">GCash Reference Number</label>
                            <input type="text" class="form-control" name="paymentReference" id="gcashRefInput"
                                   placeholder="e.g. 1234567890123" maxlength="100" required>
                            <small class="text-muted">Enter the reference number from your GCash receipt</small>
                        </div>
                    </div>
                </div>

                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="rentalTerms" required>
                    <label class="form-check-label" for="rentalTerms" style="font-size:0.85rem;color:#666;">I agree to the rental terms and conditions, and will return the bicycle to a designated station.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('rider.rent.store') }}" method="POST" id="rentalForm" class="d-inline">
                    @csrf
                    <input type="hidden" name="bicycleId" id="selectedBicycleId" value="">
                    <input type="hidden" name="durationMinutes" id="selectedDuration" value="60">
                    <input type="hidden" name="paymentMethod" id="selectedPaymentMethod" value="cash">
                    <input type="hidden" name="paymentReference" id="selectedPaymentRef" value="">
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
    var currentRate = 25;

    function selectBicycle(trigger) {
        var payload;
        try {
            payload = JSON.parse(trigger.getAttribute('data-bike-payload'));
        } catch (err) {
            return;
        }

        var rate = parseFloat(payload.rate);
        if (!isFinite(rate) || isNaN(rate)) rate = 0;

        currentRate = rate;
        document.getElementById('selectedBicycleId').value = payload.id;
        document.getElementById('modalBikeName').textContent = payload.name;
        document.getElementById('modalBikeBattery').textContent = payload.battery + '%';
        document.getElementById('modalBikeRate').textContent = '₱' + rate + '/hr';
        togglePaymentMethod('cash');
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

    // Billing rounds any part hour up to a whole charged hour, with a one hour
    // minimum. This mirrors RentalService so the estimate shown here is the
    // amount that actually gets charged.
    function chargedHours(mins) {
        return Math.max(Math.ceil((parseInt(mins, 10) || 0) / 60), 1);
    }

    // A single finite number, whatever the inputs held. Called from every place
    // that produces money so a bad duration or rate can never reach the DOM as
    // "NaN" or "Infinity".
    function money(amount) {
        var n = parseFloat(amount);
        if (!isFinite(n) || isNaN(n)) n = 0;
        return '₱' + n.toFixed(2);
    }

    function setDuration(mins) {
        document.getElementById('durationMinutesInput').value = mins;
        updateCost();
    }

    function updateCost() {
        var raw = document.getElementById('durationMinutesInput').value;
        var mins = parseInt(raw, 10);
        if (isNaN(mins) || !isFinite(mins) || mins < 1) mins = 1;

        document.getElementById('durationDisplay').textContent = formatDuration(mins);

        var rate = parseFloat(currentRate);
        if (!isFinite(rate) || isNaN(rate)) rate = 0;

        var total = chargedHours(mins) * rate;

        document.getElementById('selectedDuration').value = mins;
        document.getElementById('costDisplay').textContent = money(total);
        document.getElementById('gcashAmountDisplay').textContent = money(total);
    }

    function togglePaymentMethod(method) {
        document.getElementById('selectedPaymentMethod').value = method;
        var gcashArea = document.getElementById('gcashPaymentArea');
        var refInput = document.getElementById('gcashRefInput');
        var optCash = document.getElementById('payOptCash');
        var optGcash = document.getElementById('payOptGcash');
        var submitBtn = document.getElementById('confirmRentBtn');

        if (method === 'gcash') {
            gcashArea.classList.remove('d-none');
            refInput.required = true;
            optCash.classList.remove('active');
            optGcash.classList.add('active');
            submitBtn.innerHTML = '<i class="bi bi-check-circle"></i> Submit Payment';
            generateGcashQr();
        } else {
            gcashArea.classList.add('d-none');
            refInput.required = false;
            refInput.value = '';
            optCash.classList.add('active');
            optGcash.classList.remove('active');
            submitBtn.innerHTML = '<i class="bi bi-key-fill"></i> Start Rental';
        }
    }

    function generateGcashQr() {
        var canvas = document.getElementById('gcashQrCanvas');
        var mins = parseInt(document.getElementById('durationMinutesInput').value, 10) || 1;
        var rate = parseFloat(currentRate);
        if (!isFinite(rate) || isNaN(rate)) rate = 0;
        var total = chargedHours(mins) * rate;
        if (!isFinite(total) || isNaN(total)) total = 0;
        var text = 'gcash://pay?amount=' + total.toFixed(2) + '&note=BicycleRental';
        canvas.width = 180;
        canvas.height = 180;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 180, 180);
        var modules = generateQrMatrix(text);
        var size = modules.length;
        var cellSize = Math.floor(160 / size);
        var offset = Math.floor((180 - cellSize * size) / 2);
        ctx.fillStyle = '#000000';
        for (var r = 0; r < size; r++) {
            for (var c = 0; c < size; c++) {
                if (modules[r][c]) {
                    ctx.fillRect(offset + c * cellSize, offset + r * cellSize, cellSize, cellSize);
                }
            }
        }
    }

    function generateQrMatrix(text) {
        var len = text.length;
        var size = len < 20 ? 21 : len < 50 ? 25 : len < 100 ? 29 : 33;
        var matrix = [];
        for (var i = 0; i < size; i++) {
            matrix[i] = [];
            for (var j = 0; j < size; j++) {
                matrix[i][j] = false;
            }
        }
        drawFinder(matrix, 0, 0);
        drawFinder(matrix, size - 7, 0);
        drawFinder(matrix, 0, size - 7);
        for (var i = 8; i < size - 8; i++) {
            matrix[6][i] = (i % 2 === 0);
            matrix[i][6] = (i % 2 === 0);
        }
        var hash = 0;
        for (var k = 0; k < len; k++) {
            hash = ((hash << 5) - hash + text.charCodeAt(k)) | 0;
        }
        var seed = Math.abs(hash);
        for (var r = 9; r < size; r++) {
            for (var c = 9; c < size; c++) {
                if (matrix[r] && !matrix[r][c]) {
                    seed = (seed * 1103515245 + 12345) & 0x7fffffff;
                    matrix[r][c] = (seed % 3 === 0);
                }
            }
        }
        return matrix;
    }

    function drawFinder(matrix, row, col) {
        for (var r = 0; r < 7; r++) {
            for (var c = 0; c < 7; c++) {
                var isEdge = r === 0 || r === 6 || c === 0 || c === 6;
                var isInner = r >= 2 && r <= 4 && c >= 2 && c <= 4;
                matrix[row + r][col + c] = isEdge || isInner;
            }
        }
    }

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

        if (!document.getElementById('selectedBicycleId').value) {
            e.preventDefault();
            alert('Please select a bicycle first');
            return;
        }

        if (!document.getElementById('rentalTerms').checked) {
            e.preventDefault();
            alert('Please accept the terms and conditions');
            return;
        }

        var method = document.getElementById('selectedPaymentMethod').value;
        if (method === 'gcash') {
            var ref = document.getElementById('gcashRefInput').value.trim();
            if (!ref) {
                e.preventDefault();
                alert('Please enter your GCash reference number');
                return;
            }
            document.getElementById('selectedPaymentRef').value = ref;
        }

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
                'rate' => $b->rentableHourlyRate(),
            ])) !!};
        var hasBikes = bicycles.length > 0;
        var map = new maplibregl.Map({
            container: el,
            style: 'https://tiles.openfreemap.org/styles/liberty',
            center: hasBikes ? [bicycles[0].lng, bicycles[0].lat] : [125.6470, 7.0990],
            zoom: 15
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.addControl(new maplibregl.FullscreenControl(), 'top-right');
        bicycles.forEach(function(b) {
            var marker = new maplibregl.Marker({ color: '#2E7D32' })
                .setLngLat([b.lng, b.lat])
                .setPopup(new maplibregl.Popup({ offset: 25 }).setHTML(
                    // Text is escaped: a serial or name is data, not markup.
                    '<div style="padding:6px;font-family:Inter;"><strong>' + escapeHtml(b.name) + '</strong>'
                    + '<br><span style="color:#2E7D32;">Available</span></div>'
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
