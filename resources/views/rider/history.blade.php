@extends('layouts.rider')

@section('title', 'My Rental History')

@section('content')
@if($activeRental && in_array($activeRental->status, ['awaiting_return', 'overdue_return'], true))
    @php
        $penaltyRuleText = null;
        if ($activeRental->penaltyType === 'per_hour') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' per hour late';
        } elseif ($activeRental->penaltyType === 'per_minute') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' per minute late';
        } elseif ($activeRental->penaltyType === 'fixed') {
            $penaltyRuleText = '₱' . number_format((float) $activeRental->penaltyValue, 2) . ' fixed late fee';
        } else {
            $penaltyRuleText = '₱' . number_format($lateReturnPenaltyPerHour ?? 20, 2) . ' per hour late';
        }
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
                <form action="{{ route('rider.rentals.return', $activeRental->id) }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-return-left me-1"></i>Return Bicycle Now</button>
                </form>
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
    <div class="table-responsive">
        <table class="table table-pedalya mb-0">
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
                        <td>₱{{ number_format($rental->totalFee ?? 0, 2) }}</td>
                        <td>
                            @if($rental->paymentMethod === 'gcash')
                                <span class="badge bg-primary-subtle text-primary" style="font-size:0.75rem;"><i class="bi bi-phone me-1"></i>GCash</span>
                            @else
                                <span class="badge bg-success-subtle text-success" style="font-size:0.75rem;"><i class="bi bi-cash-stack me-1"></i>Cash</span>
                            @endif
                            @if($rental->paymentStatus === 'pending_verification')
                                <br><small class="text-warning"><i class="bi bi-hourglass-split"></i> Pending</small>
                            @elseif($rental->paymentStatus === 'paid')
                                <br><small class="text-success"><i class="bi bi-check-circle"></i> Paid</small>
                            @endif
                        </td>
                        <td>
                            @if($rental->status === 'active')
                                <span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>
                            @elseif($rental->status === 'completed')
                                <span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>
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
    @if($rentals->hasPages())
        <div class="card-pedalya-body d-flex justify-content-between align-items-center">
            <small class="text-muted">Showing {{ $rentals->firstItem() }}-{{ $rentals->lastItem() }} of {{ $rentals->total() }} rentals</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    {{ $rentals->links() }}
                </ul>
            </nav>
        </div>
    @endif
</div>

<!-- Rental Detail Modal -->
<div class="modal fade" id="rentalDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rental Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label-pedalya">Rental ID</label><p><strong id="detailRentalId">—</strong></p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Bicycle</label><p id="detailBicycle">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Start Time</label><p id="detailStart">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">End Time</label><p id="detailEnd">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Duration</label><p id="detailDuration">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Total Cost</label><p><strong class="text-primary" id="detailCost">—</strong></p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Payment Method</label><p id="detailPaymentMethod">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Status</label><p id="detailStatus">—</p></div>
                    <div class="col-sm-6"><label class="form-label-pedalya">Payment</label><p id="detailPayment">—</p></div>
                </div>
            </div>
            <div class="modal-footer">
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
        'cost' => '₱' . number_format($r->totalFee ?? 0, 2),
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
        document.getElementById('detailPayment').textContent = rental.payment;
        var statusHtml = '';
        if (rental.status === 'active') statusHtml = '<span class="badge-status badge-active"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active</span>';
        else if (rental.status === 'completed') statusHtml = '<span class="badge-status badge-completed"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Completed</span>';
        else statusHtml = '<span class="badge-status badge-cancelled"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> ' + rental.status.charAt(0).toUpperCase() + rental.status.slice(1) + '</span>';
        document.getElementById('detailStatus').innerHTML = statusHtml;
        new bootstrap.Modal(document.getElementById('rentalDetailModal')).show();
    }

    function downloadReceipt(id) {
        alert('Receipt for ' + id + ' downloading...');
    }
</script>
@endsection
