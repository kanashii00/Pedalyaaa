@extends('layouts.admin')

@section('title', 'Bicycle Management')

@section('page-header')
    <div class="admin-pagehead">
        <div class="admin-pagehead__title">
            <h1>Bicycles</h1>
            <p>Manage your bicycle fleet</p>
        </div>
    </div>
@endsection

@section('content')
{{-- Bicycles Table --}}
<div class="admin-table-wrap">
    <div class="admin-table-toolbar">
        <div class="grow">
            <i class="bi bi-search"></i>
            <input type="text" data-table-search placeholder="Search...">
        </div>
        <form method="GET" action="{{ route('admin.bicycles.index') }}" class="d-flex gap-2 align-items-center">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Available</option>
                <option value="rented" {{ request('status') === 'rented' ? 'selected' : '' }}>Rented</option>
                <option value="maintenance" {{ request('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
            </select>
            <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </form>
    </div>

    <table class="admin-table">
        <thead>
            <tr>
                <th class="sortable">Name <span class="sort-ind"></span></th>
                <th class="sortable">Serial <span class="sort-ind"></span></th>
                <th class="sortable">Status <span class="sort-ind"></span></th>
                <th>Current Renter</th>
                <th>Current Rental</th>
                <th class="sortable">Battery <span class="sort-ind"></span></th>
                <th class="sortable">Lock <span class="sort-ind"></span></th>
                <th class="sortable">Condition <span class="sort-ind"></span></th>
                <th class="sortable">Hourly Rate <span class="sort-ind"></span></th>
                <th class="sortable">Last Updated <span class="sort-ind"></span></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($bicycles as $bike)
                @php
                    $isLocked = $bike->lockStatus === 'locked';
                    $isRented = $bike->status === 'rented';
                    $inMaintenance = $bike->status === 'maintenance';

                    // The one rental holding this bicycle, eager loaded by the
                    // controller off the denormalised currentRentalId.
                    $rental = $isRented ? $bike->currentRental : null;

                    // processReturn() only accepts a rental that is already
                    // waiting on (or past) its return deadline.
                    $canProcessReturn = $rental && in_array($rental->status, [
                        \App\Models\Rental::STATUS_AWAITING_RETURN,
                        \App\Models\Rental::STATUS_OVERDUE_RETURN,
                    ], true);

                    // End Ride is the step that moves an in-progress rental
                    // into the return workflow.
                    $canEndRide = $rental && in_array($rental->status, [
                        \App\Models\Rental::STATUS_ACTIVE,
                        \App\Models\Rental::STATUS_OVERDUE,
                        \App\Models\Rental::STATUS_EXPIRED,
                    ], true);
                @endphp
                <tr>
                    <td class="cell-title" data-label="Name">{{ $bike->name }}</td>
                    <td data-label="Serial"><code>{{ $bike->serialNumber }}</code></td>
                    <td data-label="Status">
                        @if($bike->status === 'available')
                            <x-admin.badge type="success" label="Available" />
                        @elseif($bike->status === 'rented')
                            <x-admin.badge type="info" label="Rented" />
                        @elseif($bike->status === 'maintenance')
                            <x-admin.badge type="warning" label="Maintenance" />
                        @else
                            <x-admin.badge type="neutral" :label="ucfirst($bike->status)" />
                        @endif
                    </td>
                    <td data-label="Current Renter">
                        @if($isRented && $bike->currentRiderUser)
                            <div class="d-flex flex-column">
                                <span class="fw-semibold">{{ $bike->currentRiderUser->name }}</span>
                                <small class="text-muted">
                                    {{ $bike->currentRiderUser->studentId ?? $bike->currentRiderUser->email }}
                                </small>
                            </div>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td data-label="Current Rental">
                        @if($rental)
                            <div class="d-flex flex-column">
                                <a href="{{ route('admin.rentals.show', $rental->id) }}"
                                   class="text-decoration-none">
                                    <code>{{ $rental->rentalId }}</code>
                                </a>
                                <small class="text-muted">
                                    {{ \Illuminate\Support\Str::headline($rental->status) }}
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-play-circle"></i>
                                    {{ \Illuminate\Support\Carbon::parse($rental->startTime)->format('M j, g:i A') }}
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-flag"></i>
                                    Due
                                    {{ \Illuminate\Support\Carbon::parse($rental->expectedEndTime)->format('M j, g:i A') }}
                                </small>
                                <small class="text-muted">
                                    <i class="bi bi-hourglass-split"></i>
                                    {{ $rental->durationFormatted ?: ($rental->durationMinutes . ' min') }}
                                </small>
                            </div>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td data-label="Battery">
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px; max-width:80px;">
                                <div class="progress-bar bg-{{ $bike->batteryLevel <= 20 ? 'danger' : ($bike->batteryLevel <= 50 ? 'warning' : 'success') }}"
                                     style="width:{{ $bike->batteryLevel }}%"></div>
                            </div>
                            <small>{{ $bike->batteryLevel }}%</small>
                        </div>
                    </td>
                    <td data-label="Lock">
                        @if($isLocked)
                            <x-admin.badge type="danger" label="Locked" />
                        @else
                            <x-admin.badge type="success" label="Unlocked" />
                        @endif
                    </td>
                    <td data-label="Condition">{{ ucfirst($bike->condition ?? 'good') }}</td>
                    <td data-label="Hourly Rate">₱{{ number_format($bike->hourlyRate, 2) }}/hr</td>
                    <td data-label="Last Updated"><small class="text-muted">{{ $bike->updated_at->diffForHumans() }}</small></td>
                    <td data-label="Actions">
                        <div class="d-flex gap-1">
                            @if($inMaintenance)
                                {{-- Under maintenance: all row actions disabled --}}
                                <button class="btn-admin btn-admin--secondary btn-admin--sm" type="button" disabled
                                        title="Disabled while bicycle is under maintenance"
                                        aria-label="Edit disabled, bicycle under maintenance">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-admin btn-admin--secondary btn-admin--sm" type="button" disabled
                                        title="Disabled while bicycle is under maintenance"
                                        aria-label="Lock control disabled, bicycle under maintenance">
                                    <i class="bi bi-{{ $isLocked ? 'lock' : 'unlock' }}-fill"></i>
                                    <span>{{ $isLocked ? 'Lock' : 'Unlock' }}</span>
                                </button>
                                <button class="btn-admin btn-admin--danger btn-admin--sm" type="button" disabled
                                        title="Disabled while bicycle is under maintenance"
                                        aria-label="Delete disabled, bicycle under maintenance">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @else
                                <button class="btn-admin btn-admin--secondary btn-admin--sm" type="button"
                                        onclick="PedalyaModal.open('editBicycleModal{{ $bike->id }}')" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                @if($isRented)
                                    {{-- Rented: lock is controlled by the active rider --}}
                                    <button class="btn-admin btn-admin--secondary btn-admin--sm" type="button" disabled
                                            title="Bicycle is currently rented - the smart lock is controlled by the rider"
                                            aria-label="Bicycle in use, lock control unavailable">
                                        <i class="bi bi-bicycle"></i>
                                        <span>In Use</span>
                                    </button>
                                @elseif(!$isLocked)
                                    <form action="{{ route('admin.bicycles.lock', $bike->id) }}" method="POST">
                                        @csrf
                                        {{-- Label and icon mirror the Lock badge; clicking toggles the state --}}
                                        <input type="hidden" name="action" value="lock">
                                        <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm"
                                                title="Unlocked - click to lock"
                                                aria-label="Unlocked bicycle, click to lock">
                                            <i class="bi bi-unlock-fill"></i>
                                            <span>Unlock</span>
                                        </button>
                                    </form>
                                @else
                                    <form action="{{ route('admin.bicycles.lock', $bike->id) }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="action" value="unlock">
                                        <button type="submit" class="btn-admin btn-admin--danger btn-admin--sm"
                                                title="Locked - click to unlock"
                                                aria-label="Locked bicycle, click to unlock">
                                            <i class="bi bi-lock-fill"></i>
                                            <span>Lock</span>
                                        </button>
                                    </form>
                                @endif

                                <form action="{{ route('admin.bicycles.destroy', $bike->id) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-admin btn-admin--danger btn-admin--sm" title="Delete"
                                            data-confirm="Are you sure you want to delete this bicycle?">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif

                            {{-- Rental-aware actions. Availability follows the
                                 bicycle's live status, so a row can never offer
                                 an action the server would reject. --}}
                            @if($rental)
                                <a href="{{ route('admin.rentals.show', $rental->id) }}"
                                   class="btn-admin btn-admin--primary btn-admin--sm" title="View Rental {{ $rental->rentalId }}">
                                    <i class="bi bi-receipt"></i>
                                </a>
                            @endif

                            @if($canEndRide)
                                <form action="{{ route('admin.rentals.end-ride', $rental->id) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn-admin btn-admin--warning btn-admin--sm"
                                            title="End Ride - rider is returning the bicycle"
                                            data-confirm="End the ride for {{ $rental->rentalId }}?">
                                        <i class="bi bi-stop-circle"></i>
                                    </button>
                                </form>
                            @endif

                            @if($canProcessReturn)
                                <button type="button" class="btn-admin btn-admin--success btn-admin--sm"
                                        title="Return Bicycle - confirm the rider returned it"
                                        onclick="PedalyaModal.open('returnBicycleModal{{ $rental->id }}')">
                                    <i class="bi bi-arrow-return-left"></i>
                                </button>
                            @endif

                            <div class="dropdown">
                                <button class="btn-admin btn-admin--soft btn-admin--sm dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown" aria-expanded="false"
                                        title="More actions for {{ $bike->serialNumber }}">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.bicycles.show', $bike->id) }}">
                                            <i class="bi bi-info-circle me-2"></i>View Details
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item"
                                           href="{{ route('admin.rentals.index', ['bicycle_id' => $bike->id]) }}">
                                            <i class="bi bi-clock-history me-2"></i>Rental History
                                        </a>
                                    </li>
                                    @if($rental)
                                        <li>
                                            <a class="dropdown-item" href="{{ route('admin.rentals.show', $rental->id) }}">
                                                <i class="bi bi-receipt me-2"></i>View Rental
                                            </a>
                                        </li>
                                    @endif
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        {{-- A rented bicycle is in use, so it is pulled
                                             back through the return workflow first. --}}
                                        @if($isRented)
                                            <span class="dropdown-item disabled"
                                                  title="Unavailable while the bicycle is rented">
                                                <i class="bi bi-wrench-adjustable me-2"></i>Mark Maintenance
                                            </span>
                                        @elseif($inMaintenance)
                                            <a class="dropdown-item"
                                               href="{{ route('admin.maintenance.index', ['bicycleId' => $bike->id]) }}">
                                                <i class="bi bi-wrench-adjustable me-2"></i>View Maintenance
                                            </a>
                                        @else
                                            <button type="button" class="dropdown-item"
                                                    onclick="PedalyaModal.open('maintenanceBicycleModal{{ $bike->id }}')">
                                                <i class="bi bi-wrench-adjustable me-2"></i>Mark Maintenance
                                            </button>
                                        @endif
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>

                {{-- Return Bicycle Modal: the inspected condition decides whether
                     the bicycle returns to Available or is routed to Maintenance. --}}
                @if($canProcessReturn)
                    <div class="admin-modal" id="returnBicycleModal{{ $rental->id }}">
                        <div class="admin-modal__backdrop" data-modal-close></div>
                        <div class="admin-modal__dialog">
                            <form action="{{ route('admin.rentals.process-return', $rental->id) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <div class="admin-modal__head">
                                    <h3>Return {{ $rental->rentalId }}</h3>
                                    <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <div class="admin-modal__body">
                                    <div class="admin-form">
                                        <p class="text-muted">
                                            Confirm the return of <strong>{{ $bike->serialNumber }} - {{ $bike->name }}</strong>
                                            and record the condition it came back in.
                                        </p>

                                        <div class="mb-3">
                                            <label class="form-label" for="returnCondition{{ $rental->id }}">Condition on return</label>
                                            <select class="form-select" id="returnCondition{{ $rental->id }}" name="condition">
                                                <option value="good">Good - returns to Available</option>
                                                <option value="fair">Fair - returns to Available</option>
                                                <option value="damaged">Damaged - routes to Maintenance</option>
                                                <option value="needs_maintenance">Needs maintenance - routes to Maintenance</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="returnNote{{ $rental->id }}">Note (optional)</label>
                                            <textarea class="form-control" id="returnNote{{ $rental->id }}" name="note"
                                                      rows="2" maxlength="1000"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="admin-modal__foot">
                                    <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Cancel</button>
                                    <button type="submit" class="btn-admin btn-admin--success">Confirm Return</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Mark Maintenance Modal: posts to the existing maintenance
                     endpoint, which opens a record and moves the bicycle to
                     maintenance + locked in one step. --}}
                @if(!$isRented && !$inMaintenance)
                    <div class="admin-modal" id="maintenanceBicycleModal{{ $bike->id }}">
                        <div class="admin-modal__backdrop" data-modal-close></div>
                        <div class="admin-modal__dialog">
                            <form action="{{ route('admin.maintenance.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="bicycleId" value="{{ $bike->id }}">
                                <div class="admin-modal__head">
                                    <h3>Mark {{ $bike->serialNumber }} for Maintenance</h3>
                                    <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <div class="admin-modal__body">
                                    <div class="admin-form">
                                        <p class="text-muted">
                                            <strong>{{ $bike->serialNumber }} - {{ $bike->name }}</strong> will be taken out
                                            of service: its status becomes Maintenance and the smart lock is locked.
                                        </p>

                                        <div class="mb-3">
                                            <label class="form-label" for="maintType{{ $bike->id }}">Issue type</label>
                                            <select class="form-select" id="maintType{{ $bike->id }}" name="type" required>
                                                <option value="routine">Routine</option>
                                                <option value="repair">Repair</option>
                                                <option value="battery">Battery</option>
                                                <option value="lock_mechanism">Lock mechanism</option>
                                                <option value="gps_module">GPS module</option>
                                                <option value="frame">Frame</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="maintSeverity{{ $bike->id }}">Severity</label>
                                            <select class="form-select" id="maintSeverity{{ $bike->id }}" name="severity">
                                                <option value="low">Low</option>
                                                <option value="medium">Medium</option>
                                                <option value="high">High</option>
                                                <option value="critical">Critical</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="maintDesc{{ $bike->id }}">Description</label>
                                            <textarea class="form-control" id="maintDesc{{ $bike->id }}" name="description"
                                                      rows="3" maxlength="1000" required
                                                      placeholder="What needs attention on this bicycle?"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="admin-modal__foot">
                                    <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Cancel</button>
                                    <button type="submit" class="btn-admin btn-admin--warning">Mark for Maintenance</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Edit Modal --}}
                <div class="admin-modal" id="editBicycleModal{{ $bike->id }}">
                    <div class="admin-modal__backdrop" data-modal-close></div>
                    <div class="admin-modal__dialog admin-modal__dialog--lg">
                        <form action="{{ route('admin.bicycles.update', $bike->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <div class="admin-modal__head">
                                <h3>Edit Bicycle - {{ $bike->name }}</h3>
                                <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <div class="admin-modal__body">
                                <div class="admin-form">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Name</label>
                                            <input type="text" name="name" class="form-control" value="{{ $bike->name }}" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Model</label>
                                            <input type="text" name="model" class="form-control" value="{{ $bike->model }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Serial Number</label>
                                            <input type="text" name="serialNumber" class="form-control" value="{{ $bike->serialNumber }}" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Hourly Rate (₱)</label>
                                            <input type="number" name="hourlyRate" class="form-control" value="{{ $bike->hourlyRate }}" step="0.01" min="0" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Latitude</label>
                                            <input type="number" name="currentLat" class="form-control" value="{{ $bike->currentLat }}" step="any">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Longitude</label>
                                            <input type="number" name="currentLng" class="form-control" value="{{ $bike->currentLng }}" step="any">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Battery Level (%)</label>
                                            <input type="number" name="batteryLevel" class="form-control" value="{{ $bike->batteryLevel }}" min="0" max="100">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-select">
                                                <option value="available" {{ $bike->status === 'available' ? 'selected' : '' }}>Available</option>
                                                <option value="rented" {{ $bike->status === 'rented' ? 'selected' : '' }}>Rented</option>
                                                <option value="maintenance" {{ $bike->status === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3">{{ $bike->description }}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="admin-modal__foot">
                                <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Cancel</button>
                                <button type="submit" class="btn-admin btn-admin--primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            @empty
                <tr>
                    <td colspan="9">
                        <x-admin.empty-state icon="bi-bicycle" title="No bicycles found" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="admin-table-foot">
        <span>Showing {{ method_exists($bicycles, 'total') ? $bicycles->total() : $bicycles->count() }} records</span>
        @if(method_exists($bicycles, 'links'))
            {{ $bicycles->withQueryString()->links() }}
        @endif
    </div>
</div>
@endsection