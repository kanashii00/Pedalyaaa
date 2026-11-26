@extends('layouts.rider')

@section('title', 'My Rental History')

@section('styles')
<style>
    /* Scoped to the rider's Bootstrap dialog: the admin has a distinct modal
       structure that historically reused this ID. */
    @media (max-width: 767.98px) {
        .rider-rental-detail-modal { display: flex !important; align-items: center; justify-content: center; padding: max(16px, env(safe-area-inset-top)) 0 max(16px, env(safe-area-inset-bottom)); overflow: hidden; }
        .rider-rental-detail-modal .rider-rental-detail__dialog { width: calc(100% - 32px); height: min(720px, calc(100dvh - 32px - env(safe-area-inset-top) - env(safe-area-inset-bottom))); max-height: calc(100dvh - 32px - env(safe-area-inset-top) - env(safe-area-inset-bottom)); margin: 0; }
        .rider-rental-detail-modal .rider-rental-detail__content { height: 100%; border-radius: 16px; }
        .rider-rental-detail-modal .rider-rental-detail__content { display: flex; flex-direction: column; overflow: hidden; }
        .rider-rental-detail-modal .rider-rental-detail__header { flex: 0 0 auto; padding: 14px 16px; }
        .rider-rental-detail-modal .rider-rental-detail__body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 14px 16px; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
        .rider-rental-detail-modal .rider-rental-detail__footer { display: block; flex: 0 0 auto; padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
        .rider-rental-detail-modal .rider-rental-detail__footer .btn { width: 100%; min-height: 44px; }
        .rider-rental-detail__grid { display: grid; grid-template-columns: 1fr; gap: 10px; margin: 0; }
        .rider-rental-detail__card { width: auto; min-width: 0; padding: 11px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: var(--gray-50); }
        .rider-rental-detail__label { display: block; margin-bottom: 5px; color: var(--gray-500); font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .rider-rental-detail__value { margin: 0; color: var(--gray-800); font-size: .9rem; font-weight: 600; line-height: 1.45; overflow-wrap: anywhere; }
    }
</style>
@endsection

@section('content')
@if($activeRental && in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true))
    @php
        $penaltyRuleText = '₱20 per completed 5-minute block after the original rental end time';
    @endphp
    <div class="card-pedalya mb-4" style="border-left:4px solid {{ $activeRental->status === 'overdue_return' ? '#C62828' : '#F57C00' }};">
        <div class="card-pedalya-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:{{ $activeRental->status === 'overdue_return' ? '#FDEBEB' : '#FFF3E0' }};color:{{ $activeRental->status === 'overdue_return' ? '#C62828' : '#E65100' }};">
                        <i class="bi {{ $activeRental->status === 'overdue_return' ? 'bi-exclamation-triangle' : 'bi-hourglass-split' }}"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="color:{{ $activeRental->status === 'overdue_return' ? '#C62828' : '#E65100' }};">
                            {{ $activeRental->status === 'overdue_return' ? 'Overdue Return' : 'Awaiting Return' }}
                            &mdash; Bicycle <strong>{{ $activeRental->bicycle->serialNumber ?? 'N/A' }}</strong>
                        </div>
                        <div class="text-muted" style="font-size:0.85rem;">
                            @if($activeRental->status === 'overdue_return')
                                Deadline passed {{ $activeRental->returnDeadline?->format('M d, g:i A') }} &middot; {{ $penaltyRuleText }}
                                @if($currentPenalty !== null)
                                    &middot; Penalty so far: <strong>&#8369;{{ number_format($currentPenalty, 2) }}</strong>
                                @endif
                            @else
                                Return by <strong>{{ $activeRental->returnDeadline?->format('M d, g:i A') ?? '—' }}</strong> (counts down below) &middot; {{ $penaltyRuleText }}
                            @endif
                        </div>
                        @if($activeRental->status === 'overdue_return')
                            <div style="margin-top:8px;background:#FDEBEB;border:1px solid #F5C6C6;border-radius:8px;padding:8px 12px;font-size:12.5px;max-width:420px;">
                                <div class="d-flex justify-content-between"><span>Overdue Time</span><strong>{{ floor(($overdueMinutes ?? 0) / 60) }}h {{ ($overdueMinutes ?? 0) % 60 }}m</strong></div>
                                <div class="d-flex justify-content-between"><span>Penalty Rate</span><strong>{{ $penaltyRuleText }}</strong></div>
                                <div class="d-flex justify-content-between"><span>Current Penalty</span><strong style="color:#C62828;">&#8369;{{ number_format($currentPenalty ?? 0, 2) }}</strong></div>
                                <div class="d-flex justify-content-between"><span>Original Rental Fee</span><strong>&#8369;{{ number_format($activeRental->totalFee ?? 0, 2) }}</strong></div>
                                <div class="d-flex justify-content-between"><span>Total Amount Due</span><strong style="color:#C62828;">&#8369;{{ number_format(($activeRental->totalFee ?? 0) + ($currentPenalty ?? 0), 2) }}</strong></div>
                            </div>
                        @endif
                        <div class="mt-2">
                            <div class="d-flex align-items-center gap-2">
                                <strong id="hstCdDisp">--:--:--</strong>
                                <span class="text-muted" style="font-size:0.8rem;">until {{ $activeRental->status === 'overdue_return' ? 'overdue' : 'return deadline' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                @if($activeRental->returnRequestedAt !== null)
                    <div role="status" style="background:#FFF8E1;border:1px solid #FFE0B2;border-radius:8px;padding:10px 12px;max-width:340px;text-align:left;">
                        <span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split me-1" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>
                        <div class="fw-bold mt-1" style="font-size:0.82rem;color:#E65100;">Return submitted &mdash; awaiting admin confirmation.</div>
                        <div class="text-muted" style="font-size:0.78rem;">Your return request has been submitted. Please wait for an administrator to inspect the bicycle and confirm the actual arrival time.</div>
                        <button type="button" class="btn btn-warning mt-2" disabled><i class="bi bi-check2-circle me-1"></i>Return Submitted</button>
                    </div>
                @else
                    <form action="{{ route('rider.rentals.return', $activeRental->id) }}" method="POST" data-return-form>
                        @csrf
                        <button type="submit" class="btn btn-warning" data-return-button><i class="bi bi-arrow-return-left me-1"></i>Return Bicycle Now</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif

<!-- Filters -->
<div class="card-pedalya mb-4">
    <div class="card-pedalya-body">
        <form method="GET" action="{{ route('rider.rentals.index') }}">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label-pedalya">From</label>
                    <input type="date" class="form-control-pedalya" name="from" value="{{ request('from') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label-pedalya">To</label>
                    <input type="date" class="form-control-pedalya" name="to" value="{{ request('to') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label-pedalya">Status</label>
                    <select class="form-select form-control-pedalya" name="status">
                        <option value="">All</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button type="submit" class="btn btn-pedalya btn-sm"><i class="bi bi-funnel"></i> Filter</button>
                    <a href="{{ route('rider.rentals.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" style="font-size:1.4rem;">{{ $totalRentals }}</div>
                    <div class="stat-label">Total Rentals</div>
                </div>
                <div class="stat-icon" style="background:#E8F5E9;color:#2E7D32;"><i class="bi bi-bicycle"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" style="font-size:1.4rem;">₱{{ number_format($totalSpent, 2) }}</div>
                    <div class="stat-label">Total Spent</div>
                </div>
                <div class="stat-icon" style="background:#FFF3E0;color:#F57C00;"><i class="bi bi-cash"></i></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-value" style="font-size:1.4rem;">{{ $totalTime }}</div>
                    <div class="stat-label">Total Time</div>
                </div>
                <div class="stat-icon" style="background:#E3F2FD;color:#1976D2;"><i class="bi bi-clock"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card-pedalya">
    <div class="card-pedalya-header"><span><strong>Rental History</strong></span></div>
    <div class="rental-history-scroll">
        <table class="table table-pedalya mb-0 rental-history-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Bicycle</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Duration</th>
                    <th>Cost</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rentals as $rental)
                    <tr>
                        <td><strong>{{ $rental->rentalId ?? 'R-' . str_pad($rental->id, 4, '0', STR_PAD_LEFT) }}</strong></td>
                        <td>{{ $rental->bicycle->serialNumber ?? 'N/A' }} - {{ $rental->bicycle->name ?? '' }}</td>
                        <td>{{ $rental->startTime?->format('M d, g:i A') ?? '—' }}</td>
                        <td>{{ $rental->endTime ? $rental->endTime->format('M d, g:i A') : '—' }}</td>
                        <td>{{ $rental->durationFormatted ?? ($rental->durationMinutes ? floor($rental->durationMinutes / 60) . 'h ' . ($rental->durationMinutes % 60) . 'm' : '—') }}</td>
                        <td>₱{{ number_format($rental->finalAmountCollected(), 2) }}</td>
                        <td>
                            @if($rental->paymentMethod === 'gcash')
                                <span class="badge bg-primary-subtle text-primary" style="font-size:0.75rem;"><i class="bi bi-phone me-1"></i>GCash</span>
                            @else
                                <span class="badge bg-success-subtle text-success" style="font-size:0.75rem;"><i class="bi bi-cash-stack me-1"></i>Cash</span>
                            @endif
                            @if($rental->paymentStatus === 'pending_verification')
                                <br><small class="text-warning"><i class="bi bi-hourglass-split"></i> Pending</small>
                            @elseif($rental->paymentStatus === 'partial')
                                <br><small class="text-warning"><i class="bi bi-hourglass-split"></i> Partial@if($rental->payment && (float) $rental->payment->balanceDue > 0) &middot; ₱{{ number_format($rental->payment->balanceDue, 2) }} due@endif</small>
                            @elseif($rental->paymentStatus === 'paid')
                                <br><small class="text-success"><i class="bi bi-check-circle"></i> Paid</small>
                            @endif
                        </td>
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
                            @if($rental->hasRideExtensions())
                                <br><small style="color:#1976D2;font-weight:600;" title="Extra time bought on this ride ({{ $rental->extensionMinutesLabel() }}, {{ $rental->extensionCount }} extension(s))"><i class="bi bi-plus-circle"></i> Extended &times;{{ $rental->extensionCount }}</small>
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewRental({{ $rental->id }})"><i class="bi bi-eye"></i></button>
                            @if($rental->status === 'completed')
                                <button class="btn btn-sm btn-outline-secondary ms-1" onclick="downloadReceipt('{{ $rental->rentalId ?? 'R-' . str_pad($rental->id, 4, '0', STR_PAD_LEFT) }}')"><i class="bi bi-download"></i></button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-clock-history" style="font-size:2rem;"></i>
                            <p class="mt-2 mb-0">No rental history found</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @php($historyPages = $rentals->withQueryString())
    <div class="card-pedalya-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">
            @if($historyPages->total() > 0)
                Showing {{ $historyPages->firstItem() }}&ndash;{{ $historyPages->lastItem() }} of {{ $historyPages->total() }} rentals
            @else
                Showing 0 rentals
            @endif
        </small>
        @if($historyPages->hasPages())
            <nav aria-label="Rental history pages">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item {{ $historyPages->onFirstPage() ? 'disabled' : '' }}">
                        @if($historyPages->onFirstPage())
                            <span class="page-link" aria-disabled="true">Previous</span>
                        @else
                            <a class="page-link" href="{{ $historyPages->previousPageUrl() }}" rel="prev">Previous</a>
                        @endif
                    </li>
                    @for($page = 1; $page <= $historyPages->lastPage(); $page++)
                        <li class="page-item {{ $page === $historyPages->currentPage() ? 'active' : '' }}">
                            @if($page === $historyPages->currentPage())
                                <span class="page-link" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="page-link" href="{{ $historyPages->url($page) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endfor
                    <li class="page-item {{ $historyPages->hasMorePages() ? '' : 'disabled' }}">
                        @if($historyPages->hasMorePages())
                            <a class="page-link" href="{{ $historyPages->nextPageUrl() }}" rel="next">Next</a>
                        @else
                            <span class="page-link" aria-disabled="true">Next</span>
                        @endif
                    </li>
                </ul>
            </nav>
        @endif
    </div>
</div>

<!-- Rental Detail Modal -->
<div class="modal fade rider-rental-detail-modal" id="rentalDetailModal" tabindex="-1" aria-labelledby="rentalDetailTitle" aria-hidden="true">
    <div class="modal-dialog rider-rental-detail__dialog">
        <div class="modal-content rider-rental-detail__content">
            <div class="modal-header rider-rental-detail__header">
                <h5 class="modal-title" id="rentalDetailTitle">Rental Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close rental details"></button>
            </div>
            <div class="modal-body rider-rental-detail__body">
                <div class="row g-3 rider-rental-detail__grid">
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Rental ID</label><p class="rider-rental-detail__value"><strong id="detailRentalId">—</strong></p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Bicycle</label><p class="rider-rental-detail__value" id="detailBicycle">—</p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Start Time</label><p class="rider-rental-detail__value" id="detailStart">—</p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">End Time</label><p class="rider-rental-detail__value" id="detailEnd">—</p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Duration</label><p class="rider-rental-detail__value" id="detailDuration">—</p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Total Cost</label><p class="rider-rental-detail__value"><strong class="text-primary" id="detailCost">—</strong></p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Payment Method</label><p class="rider-rental-detail__value" id="detailPaymentMethod">—</p></div>
                    <div class="col-sm-6 rider-rental-detail__card"><label class="form-label-pedalya rider-rental-detail__label">Status</label><p class="rider-rental-detail__value" id="detailStatus">—</p></div>
                </div>
            </div>
            <div class="modal-footer rider-rental-detail__footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@if($activeRental && in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true) && $activeRental->returnDeadline)
<script>
    (function() {
        var endTime = new Date('{{ $activeRental->returnDeadline->toIso8601String() }}');
        function tick() {
            var disp = document.getElementById('hstCdDisp');
            if (!disp) return;
            var rem = Math.max(0, Math.floor((endTime.getTime() - Date.now()) / 1000));
            var h = Math.floor(rem / 3600), m = Math.floor((rem % 3600) / 60), s = rem % 60;
            var pad = function(n) { return String(n).padStart(2, '0'); };
            disp.textContent = h + ':' + pad(m) + ':' + pad(s);
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>
@endif
<script>
    var rentalsData = {!! json_encode($rentals->map(fn($r) => [
        'id' => $r->id,
        'rentalId' => $r->rentalId ?? 'R-' . str_pad($r->id, 4, '0', STR_PAD_LEFT),
        'bicycle' => ($r->bicycle->serialNumber ?? 'N/A') . ' - ' . ($r->bicycle->name ?? ''),
        'start' => $r->startTime?->format('M d, Y g:i A') ?? '—',
        'end' => $r->endTime ? $r->endTime->format('M d, Y g:i A') : '—',
        'duration' => $r->durationFormatted ?? ($r->durationMinutes ? floor($r->durationMinutes / 60) . 'h ' . ($r->durationMinutes % 60) . 'm' : '—'),
        'cost' => '₱' . number_format($r->finalAmountCollected(), 2),
        'status' => $r->status,
        'paymentMethod' => ucfirst($r->paymentMethod ?? 'cash'),
        'payment' => $r->paymentStatus ?? 'pending',
    ])) !!};

    function viewRental(id) {
        var rental = rentalsData.find(function(r) { return r.id === id; });
        if (!rental) return;
        document.getElementById('detailRentalId').textContent = rental.rentalId;
        document.getElementById('detailBicycle').textContent = rental.bicycle;
        document.getElementById('detailStart').textContent = rental.start;
        document.getElementById('detailEnd').textContent = rental.end;
        document.getElementById('detailDuration').textContent = rental.duration;
        document.getElementById('detailCost').textContent = rental.cost;
        document.getElementById('detailPaymentMethod').textContent = rental.paymentMethod;
        var statusHtml = '';
        if (rental.status === 'active') statusHtml = '<span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>';
        else if (rental.status === 'completed') statusHtml = '<span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>';
        else if (rental.status === 'awaiting_return' || rental.status === 'overdue_return') statusHtml = '<span class="badge-status" style="background:#FFF3E0;color:#E65100;"><i class="bi bi-hourglass-split" style="font-size:0.5rem;"></i> Awaiting Admin Confirmation</span>';
        else statusHtml = '<span class="badge-status badge-cancelled"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> ' + rental.status.charAt(0).toUpperCase() + rental.status.slice(1) + '</span>';
        document.getElementById('detailStatus').innerHTML = statusHtml;
        new bootstrap.Modal(document.getElementById('rentalDetailModal')).show();
    }

    function downloadReceipt(id) {
        alert('Receipt for ' + id + ' downloading...');
    }

    // Disable the return button the instant it is clicked so a double-click
    // can never queue a second return request; the reloaded page then shows
    // the persisted "Awaiting Admin Confirmation" state instead.
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
