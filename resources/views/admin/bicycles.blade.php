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
@php
    // Shared row helpers. The row loop and the dialogs that live below the
    // table must agree on what a bicycle's live rental is, otherwise a dialog
    // could be rendered for a rental the table never offered.
    $inMaintenanceFor = fn ($bike) => $bike->status === 'maintenance';
    $bicyclesRentalFor = fn ($bike) => $bike->status === 'rented' ? $bike->currentRental : null;
@endphp

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
                <option value="{{ \App\Models\Bicycle::STATUS_OUT_OF_SERVICE }}" {{ request('status') === \App\Models\Bicycle::STATUS_OUT_OF_SERVICE ? 'selected' : '' }}>Out of Service</option>
            </select>
            <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </form>
    </div>

    <div class="admin-table-scroll">
    <table class="admin-table admin-table--inventory">
        <colgroup>
            {{-- Percentage widths in a fixed-layout table: every column keeps
                 its share of the card at any viewport, so the whole inventory is
                 visible without horizontal scrolling. --}}
            <col class="col-name">
            <col class="col-serial">
            <col class="col-status">
            <col class="col-renter">
            <col class="col-rental">
            <col class="col-battery">
            <col class="col-lock">
            <col class="col-condition">
            <col class="col-rate">
            <col class="col-updated">
            <col class="col-actions">
        </colgroup>
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
                    $inMaintenance = $inMaintenanceFor($bike);

                    // The one rental holding this bicycle, eager loaded by the
                    // controller off the denormalised currentRentalId.
                    $rental = $bicyclesRentalFor($bike);

                    // processReturn() only accepts a rental that is already
                    // waiting on (or past) its return deadline.
                    $canProcessReturn = $rental && in_array($rental->status, [
                        \App\Models\Rental::STATUS_AWAITING_RETURN,
                        \App\Models\Rental::STATUS_OVERDUE_RETURN,
                    ], true);
                @endphp
                <tr>
                    <td class="cell-title" data-label="Name" title="{{ $bike->name }}">{{ $bike->name }}</td>
                    <td data-label="Serial"><code title="{{ $bike->serialNumber }}">{{ $bike->serialNumber }}</code></td>
                    <td data-label="Status">
                        {{-- A bicycle that is not in service is shown as such regardless
                             of the status column, because that is the fact that governs
                             whether it can be rented or commanded at all. --}}
                        @unless($bike->isInService())
                            <x-admin.badge type="neutral" label="Not In Service" />
                        @elseif($bike->status === 'available')
                            <x-admin.badge type="success" label="Available" />
                        @elseif($bike->status === 'rented')
                            <x-admin.badge type="info" label="Rented" />
                        @elseif($bike->status === 'maintenance')
                            <x-admin.badge type="warning" label="Maintenance" />
                        @else
                            <x-admin.badge type="neutral" :label="ucfirst($bike->status)" />
                        @endunless
                    </td>
                    <td data-label="Current Renter">
                        @if($isRented && $bike->currentRiderUser)
                            <div class="cell-stack">
                                <span class="cell-title" title="{{ $bike->currentRiderUser->name }}">{{ $bike->currentRiderUser->name }}</span>
                                <small class="cell-sub" title="{{ $bike->currentRiderUser->studentId ?? $bike->currentRiderUser->email }}">
                                    {{ $bike->currentRiderUser->studentId ?? $bike->currentRiderUser->email }}
                                </small>
                            </div>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td data-label="Current Rental">
                        @if($rental)
                            <div class="cell-stack">
                                <a href="{{ route('admin.rentals.show', $rental->id) }}" class="cell-key" title="{{ $rental->rentalId }}">
                                    <code>{{ $rental->rentalId }}</code>
                                </a>
                                <small class="cell-sub">
                                    {{ \Illuminate\Support\Str::headline($rental->status) }}
                                </small>
                                @php
                                    $rentalStart = \Illuminate\Support\Carbon::parse($rental->startTime)->format('M j, g:i A');
                                    $rentalDue = \Illuminate\Support\Carbon::parse($rental->expectedEndTime)->format('M j, g:i A');
                                    $rentalDuration = $rental->durationFormatted ?: ($rental->durationMinutes . ' min');
                                @endphp
                                <small class="cell-sub" title="Started {{ $rentalStart }}">
                                    <i class="bi bi-play-circle"></i>
                                    {{ $rentalStart }}
                                </small>
                                <small class="cell-sub" title="Due {{ $rentalDue }}">
                                    <i class="bi bi-flag"></i>
                                    Due
                                    {{ $rentalDue }}
                                </small>
                                <small class="cell-sub" title="{{ $rentalDuration }}">
                                    <i class="bi bi-hourglass-split"></i>
                                    {{ $rentalDuration }}
                                </small>
                            </div>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td data-label="Battery">
                        <div class="d-flex align-items-center gap-2">
                            {{-- The bar flexes with the column so a narrow viewport
                                 never pushes the row wider than the card; its height
                                 comes from the stylesheet. --}}
                            <div class="progress flex-grow-1">
                                <div class="progress-bar bg-{{ $bike->batteryLevel <= 20 ? 'danger' : ($bike->batteryLevel <= 50 ? 'warning' : 'success') }}"
                                     style="width:{{ $bike->batteryLevel }}%"></div>
                            </div>
                            <small>{{ $bike->batteryLevel }}%</small>
                        </div>
                    </td>
                    <td data-label="Lock">
                        {{-- The recorded lock state of a parked bicycle is just where
                             it happens to be sitting; there is no device behind it. --}}
                        @unless($bike->isInService())
                            <x-admin.badge type="neutral" label="No Device" />
                        @elseif($isLocked)
                            <x-admin.badge type="danger" label="Locked" />
                        @else
                            <x-admin.badge type="success" label="Unlocked" />
                        @endunless
                    </td>
                    <td data-label="Condition">{{ ucfirst($bike->condition ?? 'good') }}</td>
                    <td data-label="Hourly Rate">₱{{ number_format($bike->hourlyRate, 2) }}/hr</td>
                    <td data-label="Last Updated">
                        <small class="text-muted" title="{{ $bike->updated_at->format('M j, Y g:i A') }}">
                            {{ $bike->updated_at->diffForHumans() }}
                        </small>
                    </td>
                    <td data-label="Actions">
                        <div class="actions-row">
                            @if($inMaintenance)
                                {{-- Under maintenance: the direct row actions stay
                                     disabled; the lock/device controls live in the
                                     actions dropdown below. --}}
                                <button class="btn-admin btn-admin--secondary btn-admin--sm" type="button" disabled
                                        title="Disabled while bicycle is under maintenance"
                                        aria-label="Edit disabled, bicycle under maintenance">
                                    <i class="bi bi-pencil"></i>
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
                                 an action the server would reject. The opener
                                 only carries the rental; the dialog itself is
                                 rendered once below the table.

                                 End Ride is deliberately NOT offered here. Settling
                                 an in-progress rental lives in Active Rentals
                                 only: one home for it stops the inventory row
                                 from terminating a ride that an admin merely
                                 meant to inspect. --}}
                            @if($canProcessReturn)
                                <button type="button" class="btn-admin btn-admin--success btn-admin--sm"
                                        title="Return Bicycle - confirm the rider returned it"
                                        onclick="PedalyaModal.open('returnBicycleModal{{ $rental->id }}')">
                                    <i class="bi bi-arrow-return-left"></i>
                                </button>
                            @endif

                            {{-- data-row-menu is picked up by the controller in js/admin.js, which
                                     places this menu against the viewport so it can open above or
                                     below the row without the table clipping it. --}}
                            <div class="dropdown" data-row-menu>
                                <button class="btn-admin btn-admin--soft btn-admin--sm dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false"
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
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        {{-- Device and lock controls live in the actions menu so the
                                             row only keeps Edit and Delete. The conditions below
                                             are unchanged: a row still never offers a control the
                                             server would reject. --}}
                                        @unless($bike->isInService())
                                            <span class="dropdown-item disabled"
                                                  title="{{ $bike->serialNumber }} is not in service and has no controllable lock"
                                                  aria-label="Lock control unavailable, bicycle not in service">
                                                <i class="bi bi-plug me-2"></i>No Device
                                            </span>
                                        @else
                                            <a class="dropdown-item"
                                               href="{{ route('admin.monitoring.index', ['section' => 'devices']) }}"
                                               title="Open IoT device monitoring for {{ $bike->serialNumber }}">
                                                <i class="bi bi-cpu me-2"></i>Device Management
                                            </a>
                                        @endunless
                                    </li>
                                    <li>
                                        {{-- Lock control only exists for a bicycle that
                                             has a device; the parked case is already
                                             reported by the "No Device" entry above. --}}
                                        @if($bike->isInService())
                                            @if($inMaintenance)
                                                <span class="dropdown-item disabled"
                                                      title="Disabled while bicycle is under maintenance"
                                                      aria-label="Lock control disabled, bicycle under maintenance">
                                                    <i class="bi bi-{{ $isLocked ? 'lock' : 'unlock' }}-fill me-2"></i>{{ $isLocked ? 'Lock' : 'Unlock' }}
                                                </span>
                                            @elseif($isRented)
                                                {{-- Rented: lock is controlled by the active rider --}}
                                                <span class="dropdown-item disabled"
                                                      title="Bicycle is currently rented - the smart lock is controlled by the rider"
                                                      aria-label="Bicycle in use, lock control unavailable">
                                                    <i class="bi bi-bicycle me-2"></i>In Use
                                                </span>
                                            @else
                                                {{-- Label and icon mirror the Lock badge; submitting toggles the state --}}
                                                <form action="{{ route('admin.bicycles.lock', $bike->id) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="action" value="{{ $isLocked ? 'unlock' : 'lock' }}">
                                                    <button type="submit" class="dropdown-item"
                                                            title="{{ $isLocked ? 'Locked - click to unlock' : 'Unlocked - click to lock' }}"
                                                            aria-label="{{ $isLocked ? 'Locked bicycle, click to unlock' : 'Unlocked bicycle, click to lock' }}">
                                                        <i class="bi bi-{{ $isLocked ? 'lock' : 'unlock' }}-fill me-2"></i>{{ $isLocked ? 'Lock' : 'Unlock' }}
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    </li>
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
            @empty
                <tr>
                    <td colspan="11">
                        <x-admin.empty-state icon="bi-bicycle" title="No bicycles found" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>{{-- /.admin-table-scroll --}}

    {{-- Row dialogs live outside the table on purpose. Browsers foster-parent
         a <div> written directly inside <tbody>, which silently breaks the row
         layout and leaves orphaned dialogs in the DOM. --}}
    @foreach ($bicycles as $bike)
        @php
            $rental = $bicyclesRentalFor($bike);
            $canProcessReturnRow = $rental && in_array($rental->status, [
                \App\Models\Rental::STATUS_AWAITING_RETURN,
                \App\Models\Rental::STATUS_OVERDUE_RETURN,
            ], true);

            // Must match the condition the row dropdown uses to offer "Mark
            // Maintenance" (status-based, not rental-pointer based), so the
            // dialog only exists for a bicycle that action can be performed on.
            $canMarkMaintenanceRow = $bike->status !== 'maintenance'
                && $bike->status !== 'rented';
        @endphp

        {{-- Edit Bicycle --}}
        <div class="admin-modal" id="editBicycleModal{{ $bike->id }}">
            <div class="admin-modal__backdrop" data-modal-close></div>
            <div class="admin-modal__dialog admin-modal__dialog--lg">
                <form action="{{ route('admin.bicycles.update', $bike->id) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="admin-modal__head">
                        <h3>Edit {{ $bike->serialNumber }}</h3>
                        <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <div class="admin-modal__body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" value="{{ $bike->name }}" required>
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
                                    <option value="{{ \App\Models\Bicycle::STATUS_OUT_OF_SERVICE }}" {{ $bike->status === \App\Models\Bicycle::STATUS_OUT_OF_SERVICE ? 'selected' : '' }}>Out of Service</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3">{{ $bike->description }}</textarea>
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

        {{-- Return Bicycle: the inspected condition decides whether the bicycle
             returns to Available or is routed to Maintenance. --}}
        @if ($canProcessReturnRow)
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

        {{-- Mark Maintenance.

             Posts to admin.maintenance.store, the single existing endpoint that
             opens a maintenance record and moves the bicycle to Maintenance.
             It takes the bicycle as a form field rather than a URL parameter,
             and expects description + type as required, so the dialog mirrors
             that contract exactly. The modal id matches the id the row dropdown
             opens ('maintenanceBicycleModal{...}').

             Rendered on the same condition the dropdown offers the action: a
             bicycle that is neither already under maintenance nor out on a
             ride. A rented bicycle has to come back through the return
             workflow first. --}}
        @if ($canMarkMaintenanceRow)
            <div class="admin-modal" id="maintenanceBicycleModal{{ $bike->id }}">
                <div class="admin-modal__backdrop" data-modal-close></div>
                <div class="admin-modal__dialog">
                    <form action="{{ route('admin.maintenance.store') }}" method="POST">
                        @csrf
                        <div class="admin-modal__head">
                            <h3>Mark {{ $bike->serialNumber }} Under Maintenance</h3>
                            <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="admin-modal__body">
                            <div class="admin-form">
                                <p class="text-muted">
                                    This opens a maintenance record for
                                    <strong>{{ $bike->serialNumber }} - {{ $bike->name }}</strong>
                                    and takes it out of service.
                                </p>

                                {{-- The bicycle comes from the row that opened this
                                     dialog, so it is never left to a stale select. --}}
                                <input type="hidden" name="bicycleId" value="{{ $bike->id }}">

                                <div class="mb-3">
                                    <label class="form-label" for="maintenanceType{{ $bike->id }}">
                                        Type <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select @error('type') is-invalid @enderror"
                                            id="maintenanceType{{ $bike->id }}" name="type" required>
                                        <option value="">Select Type</option>
                                        <option value="routine" {{ old('type') === 'routine' ? 'selected' : '' }}>Routine</option>
                                        <option value="repair" {{ old('type') === 'repair' ? 'selected' : '' }}>Repair</option>
                                        <option value="battery" {{ old('type') === 'battery' ? 'selected' : '' }}>Battery Service</option>
                                        <option value="lock_mechanism" {{ old('type') === 'lock_mechanism' ? 'selected' : '' }}>Lock Mechanism</option>
                                        <option value="gps_module" {{ old('type') === 'gps_module' ? 'selected' : '' }}>GPS Module</option>
                                        <option value="frame" {{ old('type') === 'frame' ? 'selected' : '' }}>Frame</option>
                                        <option value="other" {{ old('type') === 'other' ? 'selected' : '' }}>Other</option>
                                    </select>
                                    @error('type')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="maintenanceReason{{ $bike->id }}">
                                        Description <span class="text-danger">*</span>
                                    </label>
                                    <textarea class="form-control @error('description') is-invalid @enderror"
                                              id="maintenanceReason{{ $bike->id }}" name="description"
                                              rows="2" maxlength="1000" required
                                              placeholder="e.g. Rear brake not responding">{{ old('description') }}</textarea>
                                    @error('description')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="maintenanceSeverity{{ $bike->id }}">Severity</label>
                                    <select class="form-select" id="maintenanceSeverity{{ $bike->id }}" name="severity">
                                        <option value="low" {{ old('severity') === 'low' ? 'selected' : '' }}>Low</option>
                                        <option value="medium" {{ old('severity') === 'medium' ? 'selected' : '' }}>Medium</option>
                                        <option value="high" {{ old('severity') === 'high' ? 'selected' : '' }}>High</option>
                                        <option value="critical" {{ old('severity') === 'critical' ? 'selected' : '' }}>Critical</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="maintenanceNotes{{ $bike->id }}">Notes (optional)</label>
                                    <textarea class="form-control" id="maintenanceNotes{{ $bike->id }}" name="notes"
                                              rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                                </div>
                            </div>
                        </div>
                        <div class="admin-modal__foot">
                            <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Cancel</button>
                            <button type="submit" class="btn-admin btn-admin--danger">Mark Under Maintenance</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach

    <div class="admin-table-foot">
        <span>Showing {{ method_exists($bicycles, 'total') ? $bicycles->total() : $bicycles->count() }} records</span>
        @if(method_exists($bicycles, 'links'))
            {{ $bicycles->withQueryString()->links() }}
        @endif
    </div>
</div>
@endsection
