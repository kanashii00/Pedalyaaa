@extends('layouts.admin')

@section('title', 'Bicycle Status — Pedalya Admin')

@section('page-header')
    <div class="admin-pagehead">
        <div class="admin-pagehead__title">
            <h1>Bicycle Status</h1>
            <p>Live monitoring of each bicycle's current condition and operational state</p>
        </div>
    </div>
@endsection

@section('content')
{{-- Summary cards: essential seven only (Locked/Unlocked removed), even one-row grid --}}
<div class="status-summary-grid">
    <div>
        <x-admin.kpi title="Total Bicycles" :value="$summary['total']" icon="bi-bicycle" color="var(--brand)" />
    </div>
    <div>
        <x-admin.kpi title="Available" :value="$summary['available']" icon="bi-check-circle" color="var(--success)" />
    </div>
    <div>
        <x-admin.kpi title="Rented" :value="$summary['rented']" icon="bi-person-badge" color="var(--info)" />
    </div>
    <div>
        <x-admin.kpi title="Maintenance" :value="$summary['maintenance']" icon="bi-tools" color="var(--warning)" />
    </div>
    <div>
        <x-admin.kpi title="Out of Service" :value="$summary['out_of_service']" icon="bi-pause-circle" color="var(--text-3)" />
    </div>
    <div>
        <x-admin.kpi title="IoT Online" :value="$summary['online']" icon="bi-wifi" color="var(--success)" />
    </div>
    <div>
        <x-admin.kpi title="IoT Offline" :value="$summary['offline']" icon="bi-wifi-off" color="var(--text-3)" />
    </div>
</div>

{{-- Filters: single aligned horizontal toolbar (search + 3 dropdowns + Filter + Reset) --}}
<div class="admin-table-wrap">
    <div class="admin-table-toolbar status-toolbar">
        <form method="GET" action="{{ route('admin.bicycles.status') }}" class="status-toolbar__form">
            <div class="grow status-toolbar__search">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search bicycle name or serial number..." value="{{ request('search') }}" autocomplete="off">
            </div>
            <select name="status" class="form-select form-select-sm status-toolbar__select" aria-label="Availability">
                <option value="">All Availability</option>
                <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>Available</option>
                <option value="rented" {{ request('status') === 'rented' ? 'selected' : '' }}>Rented</option>
                <option value="maintenance" {{ request('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                <option value="{{ \App\Models\Bicycle::STATUS_OUT_OF_SERVICE }}" {{ request('status') === \App\Models\Bicycle::STATUS_OUT_OF_SERVICE ? 'selected' : '' }}>Out of Service</option>
            </select>
            <select name="lock" class="form-select form-select-sm status-toolbar__select" aria-label="Smart Lock">
                <option value="">All Locks</option>
                <option value="locked" {{ request('lock') === 'locked' ? 'selected' : '' }}>Locked</option>
                <option value="unlocked" {{ request('lock') === 'unlocked' ? 'selected' : '' }}>Unlocked</option>
            </select>
            <select name="connectivity" class="form-select form-select-sm status-toolbar__select" aria-label="Connectivity">
                <option value="">All Connectivity</option>
                <option value="online" {{ request('connectivity') === 'online' ? 'selected' : '' }}>Online</option>
                <option value="offline" {{ request('connectivity') === 'offline' ? 'selected' : '' }}>Offline</option>
            </select>
            <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
            @if(request()->filled('search') || request()->filled('status') || request()->filled('lock') || request()->filled('connectivity'))
                <a href="{{ route('admin.bicycles.status') }}" class="btn-admin btn-admin--ghost btn-admin--sm" title="Reset all filters">
                    <i class="bi bi-x-lg me-1"></i>Reset
                </a>
            @endif
        </form>
    </div>

    <div class="table-responsive">
    <table class="admin-table status-table">
        <thead>
            <tr>
                <th class="sortable">Name <span class="sort-ind"></span></th>
                <th class="sortable">Serial <span class="sort-ind"></span></th>
                <th class="sortable">Availability <span class="sort-ind"></span></th>
                <th class="sortable">Battery <span class="sort-ind"></span></th>
                <th class="sortable">Smart Lock <span class="sort-ind"></span></th>
                <th class="sortable">Condition <span class="sort-ind"></span></th>
                <th class="sortable">Connectivity <span class="sort-ind"></span></th>
                <th>Current Rider</th>
                <th class="sortable">Last Updated <span class="sort-ind"></span></th>
            </tr>
        </thead>
        <tbody>
            @forelse($bicycles as $bike)
                <tr>
                    <td class="cell-title" data-label="Name">
                        <div class="d-flex align-items-center gap-2">
                            <span class="bike-status-dot {{ $bike->connectivity === 'online' ? 'online' : 'offline' }}"></span>
                            <span>{{ $bike->name }}</span>
                        </div>
                    </td>
                    <td data-label="Serial"><code>{{ $bike->serialNumber }}</code></td>
                    <td data-label="Availability">
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
                    <td data-label="Battery">
                        @if(!$bike->hasRegisteredDevice())
                            <small class="text-muted">Not equipped</small>
                        @elseif($bike->batteryLevel === null)
                            <span class="text-muted">—</span>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:6px; max-width:40px;">
                                    <div class="progress-bar bg-{{ $bike->batteryLevel <= 20 ? 'danger' : ($bike->batteryLevel <= 50 ? 'warning' : 'success') }}"
                                         style="width:{{ $bike->batteryLevel }}%"></div>
                                </div>
                                <small>{{ $bike->batteryLevel }}%</small>
                            </div>
                        @endif
                    </td>
                    <td data-label="Smart Lock">
                        @if(!$bike->hasRegisteredDevice())
                            <small class="text-muted">Not applicable</small>
                        @elseif($bike->lockStatus === 'locked')
                            <x-admin.badge type="danger" label="Locked" />
                        @else
                            <x-admin.badge type="success" label="Unlocked" />
                        @endif
                    </td>
                    <td data-label="Condition">{{ ucfirst($bike->condition ?? 'good') }}</td>
                    <td data-label="Connectivity">
                        @if(($bike->connectivity ?? 'none') === 'online')
                            <x-admin.badge type="success" label="Online" />
                        @elseif(($bike->connectivity ?? 'none') === 'offline')
                            <x-admin.badge type="neutral" label="Offline" />
                        @else
                            <small class="text-muted">Not equipped</small>
                        @endif
                    </td>
                    <td data-label="Current Rider">
                        @if($bike->status === 'rented' && $bike->currentRiderUser)
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-person-badge text-info"></i>
                                <div style="min-width:0;">
                                    <div class="fw-semibold">{{ $bike->currentRiderUser->name }}</div>
                                    <small class="text-muted status-rider-email" title="{{ $bike->currentRiderUser->email }}">{{ $bike->currentRiderUser->email }}</small>
                                </div>
                            </div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Last Updated">
                        <div class="bike-updated">
                            <small class="text-muted">{{ $bike->updated_at?->diffForHumans() ?? '—' }}</small>
                            @if(!$bike->hasRegisteredDevice())
                                <small class="text-muted">Not applicable</small>
                            @elseif(!$bike->lastHeartbeat)
                                <small class="text-muted">—</small>
                            @else
                                <small class="text-muted">{{ $bike->lastHeartbeat->diffForHumans() }}</small>
                                @if(($bike->connectivity ?? 'none') === 'offline')
                                    <span class="bike-stale">Stale</span>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-admin.empty-state icon="bi-bicycle" title="No bicycles match the current filters" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div class="admin-table-foot">
        <span>Showing {{ count($bicycles) }} bicycle(s)</span>
        <small class="text-muted">Statuses sync automatically with Rental Management, Maintenance, GPS Monitoring and Smart Lock Control.</small>
    </div>
</div>

<style>
    .bike-status-dot {
        width: 10px; height: 10px;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }
    .bike-status-dot.online { background: var(--success); }
    .bike-status-dot.offline { background: var(--text-3); }
</style>
@endsection
