@extends('layouts.admin')

@section('title', 'Accident Reports')

@section('styles')
<style>
    /* ============================================================
       ACCIDENT MONITORING TABLE & MODAL STYLES
       ============================================================ */

    /* Clickable bicycle name with subtle hover */
    .accident-bike-link,
    .accident-bike-link *,
    .theft-bike-link,
    .theft-bike-link * {
        cursor: pointer !important;
    }
    .accident-bike-link,
    .theft-bike-link {
        color: var(--text-1, #f8fafc);
        text-decoration: none;
        display: inline-block;
        transition: color 0.18s ease, text-decoration 0.18s ease;
        position: relative;
        z-index: 1;
    }
    .accident-bike-link:hover,
    .accident-bike-link:focus,
    .theft-bike-link:hover,
    .theft-bike-link:focus {
        color: var(--brand, #10b981) !important;
        text-decoration: underline;
        text-underline-offset: 3px;
    }
    .accident-bike-link:hover .fw-semibold,
    .accident-bike-link:focus .fw-semibold,
    .theft-bike-link:hover .fw-semibold,
    .theft-bike-link:focus .fw-semibold {
        color: var(--brand, #10b981) !important;
    }

    /* Modal Acknowledgment Card (Theft Detection styling) */
    .theft-modal-ack-card {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.25);
        border-radius: 10px;
        transition: all 0.2s ease;
    }
    .theft-modal-ack-card.is-unacknowledged {
        background: rgba(245, 158, 11, 0.08);
        border-color: rgba(245, 158, 11, 0.3);
    }
    .acc-ack-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* Consistent Badges */
    .theft-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.25;
        white-space: nowrap;
    }
    .theft-badge--critical {
        background: rgba(220, 38, 38, 0.15);
        color: #ef4444;
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .theft-badge--warning {
        background: rgba(217, 119, 6, 0.15);
        color: #f59e0b;
        border: 1px solid rgba(217, 119, 6, 0.35);
    }
    .theft-badge--info {
        background: rgba(14, 165, 233, 0.15);
        color: #38bdf8;
        border: 1px solid rgba(14, 165, 233, 0.35);
    }
    .theft-badge--success {
        background: rgba(22, 163, 74, 0.15);
        color: #22c55e;
        border: 1px solid rgba(22, 163, 74, 0.35);
    }
    .theft-badge--neutral {
        background: rgba(148, 163, 184, 0.12);
        color: #94a3b8;
        border: 1px solid rgba(148, 163, 184, 0.25);
    }

    /* Modal Dialog Fixed Header/Footer & Scrollable Body */
    #accidentDetailModal .admin-modal__dialog {
        max-width: 780px;
        width: 92vw;
        max-height: 88vh;
        display: flex;
        flex-direction: column;
    }
    #accidentDetailModal .admin-modal__head {
        flex-shrink: 0;
        padding: 16px 22px;
        border-bottom: 1px solid var(--border-subtle);
    }
    #accidentDetailModal .admin-modal__body {
        flex: 1 1 auto;
        overflow-y: auto;
        padding: 18px 22px;
    }
    #accidentDetailModal .admin-modal__foot {
        flex-shrink: 0;
        padding: 14px 22px;
        border-top: 1px solid var(--border-subtle);
        background: var(--surface);
    }

    /* Summary Card (Section B) */
    .acc-summary-card {
        background: rgba(220, 38, 38, 0.06);
        border: 1px solid rgba(220, 38, 38, 0.2);
        border-radius: 10px;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }
    .acc-summary-item {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .acc-meta-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-3, #94a3b8);
    }
    .acc-summary-divider {
        width: 1px;
        height: 28px;
        background: rgba(255, 255, 255, 0.1);
    }

    /* Modal Cards (Sections C, D, E) */
    .theft-modal-section {
        background: var(--bg-surface-2, rgba(255, 255, 255, 0.03));
        border: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.08));
        border-radius: 10px;
        padding: 14px 16px;
    }
    .theft-modal-section__title {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-3, #94a3b8);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .acc-card-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px 14px;
    }
    .acc-field-group {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }
    .acc-field-group--full {
        grid-column: 1 / -1;
    }
    .acc-field-label {
        font-size: 10.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-3, #94a3b8);
        line-height: 1.2;
    }
    .acc-field-value {
        font-size: 13.5px;
        font-weight: 600;
        color: var(--text-1, #f8fafc);
        word-break: break-word;
        line-height: 1.35;
    }

    /* Mobile adjustments */
    @media (max-width: 575.98px) {
        .acc-summary-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        .acc-summary-divider {
            display: none;
        }
        .acc-card-grid {
            grid-template-columns: 1fr;
        }
        #accidentDetailModal .admin-modal__body {
            padding: 14px 16px;
        }
    }

    /* Spinner animation */
    .spin {
        animation: spin-animation 1s linear infinite;
    }
    @keyframes spin-animation {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>
@endsection

@section('page-header')
    <h1>Accident Reports</h1>
    <p>View and manage safety incidents, impact telemetry, and accident response logs</p>
@endsection

@section('actions')
    <button class="btn-admin btn-admin--secondary" onclick="location.reload()">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </button>
@endsection

@section('content')
<div class="admin-table-wrap">
    <div class="admin-table-toolbar">
        <div class="grow"><i class="bi bi-search"></i><input type="text" data-table-search placeholder="Search accident reports..."></div>
    </div>
    <div class="table-responsive">
    <table class="admin-table">
        <thead>
            <tr>
                <th class="sortable">Bicycle <span class="sort-ind"></span></th>
                <th class="sortable">Type <span class="sort-ind"></span></th>
                <th class="sortable">Severity <span class="sort-ind"></span></th>
                <th class="sortable">Rider <span class="sort-ind"></span></th>
                <th class="sortable">Rental <span class="sort-ind"></span></th>
                <th class="sortable">Description <span class="sort-ind"></span></th>
                <th class="sortable">Location <span class="sort-ind"></span></th>
                <th class="sortable">Timestamp <span class="sort-ind"></span></th>
                <th class="sortable">Acknowledged <span class="sort-ind"></span></th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($incidents as $accident)
            @php
                $hasGps = !empty($accident->gpsLocation['lat']) && !empty($accident->gpsLocation['lng']);
                $mapParams = ['bike' => $accident->bicycleId];
                if ($hasGps) {
                    $mapParams['lat'] = $accident->gpsLocation['lat'];
                    $mapParams['lng'] = $accident->gpsLocation['lng'];
                }
                $rentalRef = $accident->rentalId ? ('#' . $accident->rentalId) : ($accident->bicycle?->currentRental ? ('#' . $accident->bicycle->currentRental->id) : null);
            @endphp
            <tr data-accident-row="{{ $accident->id }}">
                <td data-label="Bicycle" class="cell-title">
                    <a href="javascript:void(0)"
                       role="button"
                       class="accident-bike-link"
                       data-view-accident="{{ $accident->id }}"
                       title="View accident #{{ $accident->id }} details">
                        <span class="fw-semibold">{{ $accident->bicycle->name ?? ('Bicycle #' . $accident->bicycleId) }}</span>
                        @if($accident->bicycle?->serialNumber)
                            <span class="text-muted small d-block font-monospace">{{ $accident->bicycle->serialNumber }}</span>
                        @endif
                    </a>
                </td>
                <td data-label="Type">{{ ucfirst(str_replace('_', ' ', $accident->type)) }}</td>
                <td data-label="Severity">
                    @switch($accident->severity)
                        @case('minor')
                            <span class="theft-badge theft-badge--info">Minor</span>
                            @break
                        @case('moderate')
                            <span class="theft-badge theft-badge--warning">Moderate</span>
                            @break
                        @case('major')
                            <span class="theft-badge theft-badge--critical">Major</span>
                            @break
                        @case('critical')
                            <span class="theft-badge theft-badge--critical">Critical</span>
                            @break
                        @default
                            <span class="theft-badge theft-badge--neutral">{{ ucfirst($accident->severity) }}</span>
                    @endswitch
                </td>
                <td data-label="Rider" class="text-truncate" style="max-width: 170px;" title="{{ $accident->riderDisplayName }}">
                    <span class="{{ $accident->riderDisplayName === 'No Active Rental' ? 'text-muted' : 'text-primary fw-medium' }}">
                        {{ $accident->riderDisplayName }}
                    </span>
                </td>
                <td data-label="Rental">
                    @if($rentalRef)
                        <span class="badge-admin badge-admin--neutral badge-admin--plain">{{ $rentalRef }}</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td data-label="Description" class="text-truncate" style="max-width: 200px;" title="{{ $accident->description }}">
                    {{ $accident->description }}
                </td>
                <td data-label="Location" class="text-truncate font-monospace" style="max-width: 150px;" title="{{ $hasGps ? $accident->gpsLocation['lat'] . ', ' . $accident->gpsLocation['lng'] : 'No GPS' }}">
                    @if($hasGps)
                        {{ $accident->gpsLocation['lat'] }}, {{ $accident->gpsLocation['lng'] }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td data-label="Timestamp"><small>{{ $accident->created_at->format('M d, Y H:i') }}</small></td>
                <td data-label="Acknowledged" data-accident-ack-cell="{{ $accident->id }}">
                    @if($accident->acknowledged)
                        <span class="theft-badge theft-badge--success" title="{{ $accident->acknowledger ? 'Acknowledged by ' . $accident->acknowledger->name : 'Acknowledged' }}">
                            <i class="bi bi-check-circle-fill"></i> Acknowledged
                        </span>
                    @else
                        <span class="theft-badge theft-badge--warning">
                            <i class="bi bi-clock-history"></i> Pending
                        </span>
                    @endif
                </td>
                <td data-label="Actions" class="col-actions">
                    <div class="actions-row">
                        <a href="{{ route('admin.monitoring.index', $mapParams) }}"
                           class="btn-admin btn-admin--ghost btn-admin--sm"
                           title="View on Live GPS Tracking Map"
                           aria-label="View on Map">
                            <i class="bi bi-geo-alt me-1"></i>Map
                        </a>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="text-center py-4">
                    <x-admin.empty-state icon="bi-activity" title="No accident reports found" />
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    @if($incidents->hasPages())
        <div class="p-3 border-top">
            {{ $incidents->links() }}
        </div>
    @endif
</div>

{{-- Detail Modal: Redesigned with exact required hierarchy A -> B -> C -> D -> E -> F --}}
<div class="admin-modal" id="accidentDetailModal">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog admin-modal__dialog--lg">
        {{-- A. Header --}}
        <div class="admin-modal__head d-flex align-items-center justify-content-between">
            <div>
                <h3 class="m-0" id="modalIncidentTitle"><i class="bi bi-activity text-danger me-2"></i>Accident Report Details</h3>
                <small class="text-muted" id="modalIncidentSubtitle">Incident telemetry, rider verification, and response management</small>
            </div>
            <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        {{-- Scrollable Body --}}
        <div class="admin-modal__body">
            <div id="modalLoadingState" class="text-center py-4" style="display: none;">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted mt-2">Loading incident details...</p>
            </div>

            <div id="modalContentState">
                {{-- B. Incident Summary (Single full-width card with horizontal alignment) --}}
                <div class="acc-summary-card mb-3">
                    <div class="acc-summary-item">
                        <span class="acc-meta-label">Incident ID</span>
                        <span class="theft-badge theft-badge--neutral" id="modalIncidentIdBadge">#—</span>
                    </div>
                    <div class="acc-summary-divider"></div>
                    <div class="acc-summary-item">
                        <span class="acc-meta-label">Incident Type</span>
                        <span class="theft-badge theft-badge--neutral" id="modalTypeBadge">Accident</span>
                    </div>
                    <div class="acc-summary-divider"></div>
                    <div class="acc-summary-item">
                        <span class="acc-meta-label">Severity</span>
                        <span id="modalSeverityBadge"><span class="theft-badge theft-badge--critical">Critical</span></span>
                    </div>
                    <div class="acc-summary-divider"></div>
                    <div class="acc-summary-item">
                        <span class="acc-meta-label">Incident Status</span>
                        <span id="modalStatusBadge"><span class="theft-badge theft-badge--neutral">Open</span></span>
                    </div>
                    <div class="acc-summary-divider"></div>
                    <div class="acc-summary-item text-md-end ms-md-auto">
                        <span class="acc-meta-label">Event timestamp (server time if unsynced)</span>
                        <span class="fw-semibold text-1" id="modalDetectedTime">—</span>
                    </div>
                </div>

                {{-- C. Information cards – two-column grid --}}
                <div class="row g-3 mb-3">
                    {{-- Left card: Bicycle & Telemetry --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section h-100 mb-0">
                            <div class="theft-modal-section__title"><i class="bi bi-bicycle text-success"></i> Bicycle &amp; Telemetry</div>
                            <div class="acc-card-grid">
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Bicycle Name</span>
                                    <span class="acc-field-value" id="modalBikeName">—</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Serial Number</span>
                                    <span class="acc-field-value font-monospace" id="modalBikeSerial">—</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Impact reading</span>
                                    <span class="acc-field-value text-danger fw-bold" id="modalImpactForce">—</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Hall Speed at Event</span>
                                    <span class="acc-field-value" id="modalHallSpeed">Not reported</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">MPU / Hall sensor status</span>
                                    <span class="acc-field-value text-muted" id="modalSensorStatus">IoT Telemetry</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Right card: Rider & Rental Association --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section h-100 mb-0">
                            <div class="theft-modal-section__title"><i class="bi bi-person-badge text-success"></i> Rider &amp; Rental Association</div>
                            <div class="acc-card-grid">
                                <div class="acc-field-group acc-field-group--full">
                                    <span class="acc-field-label">Actual Registered Rider</span>
                                    <span class="acc-field-value text-primary fw-semibold" id="modalRiderName">—</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Associated Rental ID</span>
                                    <span class="acc-field-value" id="modalRentalId">—</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Rental Status</span>
                                    <span class="acc-field-value" id="modalRentalStatus">Active</span>
                                </div>
                                <div class="acc-field-group acc-field-group--full">
                                    <span class="acc-field-label">Account Association</span>
                                    <span class="acc-field-value text-muted" id="modalAccountType">Registered Account</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="theft-modal-section mb-3">
                    <div class="theft-modal-section__title"><i class="bi bi-activity text-success"></i> Sensor context at event</div>
                    <div class="acc-card-grid">
                        <div class="acc-field-group">
                            <span class="acc-field-label">Classification / trigger / reason</span>
                            <span class="acc-field-value" id="modalDetectionReason">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">Hall movement / configuration</span>
                            <span class="acc-field-value" id="modalHallStatus">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">Hall speed before event</span>
                            <span class="acc-field-value" id="modalHallSpeedBefore">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">GPS fix status / speed / age</span>
                            <span class="acc-field-value" id="modalGpsSpeed">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">GPS location timestamp</span>
                            <span class="acc-field-value" id="modalGpsTimestamp">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">Wheel circumference / pulse count</span>
                            <span class="acc-field-value" id="modalHallConfiguration">Not reported</span>
                        </div>
                        <div class="acc-field-group">
                            <span class="acc-field-label">Rotation / tilt</span>
                            <span class="acc-field-value" id="modalRotationTilt">Not reported</span>
                        </div>
                        <div class="acc-field-group acc-field-group--full">
                            <span class="acc-field-label">Recent pre-impact sensor samples</span>
                            <span class="acc-field-value fw-normal" id="modalPreImpactSamples">No sample history recorded.</span>
                        </div>
                    </div>
                </div>

                {{-- D. Location & Telemetry – two-column grid --}}
                <div class="row g-3 mb-3">
                    {{-- Left card: Location & Recorded GPS --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section h-100 mb-0">
                            <div class="theft-modal-section__title"><i class="bi bi-geo-alt text-success"></i> Location &amp; Recorded GPS</div>
                            <div class="d-flex flex-column justify-content-between" style="min-height: 80px;">
                                <div class="acc-field-group mb-2">
                                    <span class="acc-field-label">Coordinates (Latitude, Longitude)</span>
                                    <span class="acc-field-value font-monospace" id="modalGpsCoords">—</span>
                                </div>
                                <div>
                                    <a href="#" id="modalBtnMap" class="btn-admin btn-admin--soft btn-admin--sm text-info" target="_blank" rel="noopener">
                                        <i class="bi bi-geo-alt-fill me-1"></i>View on Map
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Right card: Telemetry Status --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section h-100 mb-0">
                            <div class="theft-modal-section__title"><i class="bi bi-broadcast text-success"></i> Telemetry Status</div>
                            <div class="acc-card-grid">
                                <div class="acc-field-group">
                                    <span class="acc-field-label">GPS Recording</span>
                                    <span class="acc-field-value text-success" id="modalLocationStatus">Logged &amp; Fixed</span>
                                </div>
                                <div class="acc-field-group">
                                    <span class="acc-field-label">Impact Sensor</span>
                                    <span class="acc-field-value text-1" id="modalTelemetryImpact">Tri-Axis Accelerometer</span>
                                </div>
                                <div class="acc-field-group acc-field-group--full">
                                    <span class="acc-field-label">Telemetry Ingest</span>
                                    <span class="acc-field-value text-muted" id="modalTelemetryIngest">Device Incident Record</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- E. Incident Description (Full-width card) --}}
                <div class="theft-modal-section mb-3">
                    <div class="theft-modal-section__title"><i class="bi bi-card-text text-success"></i> Incident Description</div>
                    <div class="acc-field-value fw-normal" id="modalDescription" style="line-height: 1.6; white-space: pre-wrap;">—</div>
                </div>

                {{-- F. Acknowledgment (Full-width card) --}}
                <div class="theft-modal-ack-card p-3 rounded" id="modalAckBanner">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div id="modalAckStatusIcon" class="acc-ack-icon">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 1.6rem;"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-1" id="modalAckHeadline" style="font-size: 13.5px;">Accident Report Acknowledged</strong>
                                    <span id="modalAckBadge" class="theft-badge theft-badge--success">Acknowledged</span>
                                </div>
                                <div class="small text-muted mt-1" id="modalAckSubline" data-ack-meta>—</div>
                            </div>
                        </div>
                        <div id="modalAckActionWrap">
                            <button type="button" class="btn-admin btn-admin--primary" id="modalBtnAcknowledge" aria-label="Acknowledge">
                                <i class="bi bi-check-circle me-1"></i>Acknowledge Accident
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Footer (Always visible, containing only Close button aligned to bottom-right) --}}
        <div class="admin-modal__foot d-flex justify-content-end align-items-center">
            <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Close</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@php
    $controller = app(\App\Http\Controllers\Admin\AccidentController::class);
    $initialAccidents = $incidents->getCollection()->mapWithKeys(function ($accident) use ($controller) {
        return [(string) $accident->id => $controller->formatIncidentPayload($accident)];
    });
    if ($selectedIncident && !$initialAccidents->has((string) $selectedIncident->id)) {
        $initialAccidents->put((string) $selectedIncident->id, $controller->formatIncidentPayload($selectedIncident));
    }
@endphp
<script>
(function () {
    var accidentsData = @json($initialAccidents);
    var currentAccident = null;
    var isAcknowledging = false;
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var modalEl = document.getElementById('accidentDetailModal');

    function openModal() {
        if (modalEl) {
            modalEl.classList.add('open');
        }
        if (window.PedalyaModal && typeof window.PedalyaModal.open === 'function') {
            try {
                window.PedalyaModal.open('accidentDetailModal');
            } catch (err) {
                console.warn('PedalyaModal.open warning:', err);
            }
        }
    }

    function closeModal() {
        if (modalEl) {
            modalEl.classList.remove('open');
        }
        if (window.PedalyaModal && typeof window.PedalyaModal.close === 'function') {
            try {
                window.PedalyaModal.close('accidentDetailModal');
            } catch (err) {
                console.warn('PedalyaModal.close warning:', err);
            }
        }
    }

    function formatSeverityBadge(severity) {
        var map = {
            'minor': '<span class="theft-badge theft-badge--info">Minor</span>',
            'moderate': '<span class="theft-badge theft-badge--warning">Moderate</span>',
            'major': '<span class="theft-badge theft-badge--critical">Major</span>',
            'critical': '<span class="theft-badge theft-badge--critical">Critical</span>'
        };
        return map[severity] || '<span class="theft-badge theft-badge--neutral">' + (severity || 'Unknown') + '</span>';
    }

    function updateAckState(acc) {
        var banner = document.getElementById('modalAckBanner');
        var icon = document.getElementById('modalAckStatusIcon');
        var headline = document.getElementById('modalAckHeadline');
        var subline = document.getElementById('modalAckSubline') || document.getElementById('modalAckMeta');
        var badge = document.getElementById('modalAckBadge');
        var btnAck = document.getElementById('modalBtnAcknowledge');

        if (acc.acknowledged) {
            if (banner) banner.className = 'theft-modal-ack-card p-3 rounded';
            if (icon) icon.innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size: 1.6rem;"></i>';
            if (headline) {
                headline.textContent = 'Accident Report Acknowledged';
                headline.className = 'text-1 fw-bold';
            }
            if (subline) {
                subline.textContent = 'Acknowledged by ' + (acc.acknowledgedBy || 'Administrator') + (acc.acknowledgedAt ? ' on ' + acc.acknowledgedAt : '');
            }
            if (badge) {
                badge.className = 'theft-badge theft-badge--success';
                badge.innerHTML = 'Acknowledged';
            }
            if (btnAck) btnAck.style.display = 'none';
        } else {
            if (banner) banner.className = 'theft-modal-ack-card is-unacknowledged p-3 rounded';
            if (icon) icon.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 1.6rem;"></i>';
            if (headline) {
                headline.textContent = 'Pending Administrator Acknowledgment';
                headline.className = 'text-1 fw-bold';
            }
            if (subline) {
                subline.textContent = 'This safety incident is pending review and requires administrator acknowledgment.';
            }
            if (badge) {
                badge.className = 'theft-badge theft-badge--warning';
                badge.innerHTML = 'Pending Review';
            }
            if (btnAck) {
                btnAck.style.display = 'inline-flex';
                btnAck.disabled = false;
                btnAck.innerHTML = '<i class="bi bi-check-circle me-1"></i>Acknowledge Accident';
            }
        }
    }

    function renderModalContent(acc) {
        currentAccident = acc;

        var elId = document.getElementById('modalIncidentIdBadge');
        if (elId) elId.textContent = '#' + acc.id;

        var elType = document.getElementById('modalTypeBadge');
        if (elType) elType.textContent = acc.typeLabel || acc.type || 'Accident';

        var elSev = document.getElementById('modalSeverityBadge');
        if (elSev) elSev.innerHTML = formatSeverityBadge(acc.severity);

        var elTime = document.getElementById('modalDetectedTime');
        if (elTime) {
            elTime.textContent = acc.eventTimestamp
                ? new Date(acc.eventTimestamp).toLocaleString()
                : (acc.timestampFull || acc.timestamp || '—');
        }

        var elStatus = document.getElementById('modalStatusBadge');
        if (elStatus) {
            elStatus.innerHTML = '<span class="theft-badge theft-badge--neutral">' + (acc.statusLabel || 'Open') + '</span>';
        }

        // C. Bicycle & Telemetry
        var elBike = document.getElementById('modalBikeName');
        if (elBike) elBike.textContent = acc.bicycleName || ('Bicycle #' + acc.bicycleId);

        var elSerial = document.getElementById('modalBikeSerial');
        if (elSerial) elSerial.textContent = acc.serialNumber || '—';

        var elImpact = document.getElementById('modalImpactForce');
        if (elImpact) {
            elImpact.textContent = acc.impactDetected
                ? (acc.impactForce || 'Detected; magnitude unavailable')
                : 'No impact detected';
        }

        var elHallSpeed = document.getElementById('modalHallSpeed');
        if (elHallSpeed) {
            elHallSpeed.textContent = acc.speedKmh !== null
                && acc.speedKmh !== undefined
                && Number.isFinite(Number(acc.speedKmh))
                ? Number(acc.speedKmh).toFixed(1) + ' km/h'
                : 'Not reported';
        }

        var elSensor = document.getElementById('modalSensorStatus');
        if (elSensor) {
            elSensor.textContent = (acc.mpuStatus || 'MPU unavailable')
                + ' · Hall ' + (acc.sensorStatus || 'unavailable')
                + (acc.mpuSampleAgeMs !== null && acc.mpuSampleAgeMs !== undefined
                    ? ' · MPU sample ' + acc.mpuSampleAgeMs + ' ms old'
                    : '');
        }

        var sensorContext = acc.sensorContext || {};
        var hall = sensorContext.hall || {};
        var gps = sensorContext.gps || {};
        var detection = [
            acc.classification,
            acc.detectionSource,
            acc.detectionTrigger,
            acc.detectionReason,
            acc.impactDetected ? 'impact detected' : 'no impact detected'
        ].filter(Boolean).join(' · ');
        var elDetection = document.getElementById('modalDetectionReason');
        if (elDetection) elDetection.textContent = detection || 'Not reported';

        var elHallStatus = document.getElementById('modalHallStatus');
        if (elHallStatus) {
            elHallStatus.textContent = [
                acc.movementStatus || hall.movementStatus || 'Unknown',
                acc.sensorStatus || hall.status || 'Unavailable',
                acc.hallTelemetryFresh === true || hall.telemetryFresh === true
                    ? 'current snapshot'
                    : 'freshness unavailable'
            ].join(' · ');
        }

        var hallBefore = acc.hallSpeedBeforeKmh !== null && acc.hallSpeedBeforeKmh !== undefined
            ? Number(acc.hallSpeedBeforeKmh).toFixed(1) + ' km/h'
            : 'Not reported';
        var elHallBefore = document.getElementById('modalHallSpeedBefore');
        if (elHallBefore) elHallBefore.textContent = hallBefore;

        var gpsSpeed = acc.gpsSpeedKmh !== null && acc.gpsSpeedKmh !== undefined
            && Number.isFinite(Number(acc.gpsSpeedKmh))
            ? Number(acc.gpsSpeedKmh).toFixed(1) + ' km/h'
            : 'Unavailable';
        var gpsAge = acc.gpsFixAgeMs !== null && acc.gpsFixAgeMs !== undefined
            && Number.isFinite(Number(acc.gpsFixAgeMs))
            ? (Number(acc.gpsFixAgeMs) / 1000).toFixed(1) + 's old'
            : 'age unknown';
        var elGpsSpeed = document.getElementById('modalGpsSpeed');
        if (elGpsSpeed) {
            elGpsSpeed.textContent = (acc.gpsFixStatus || gps.fixStatus || 'unavailable')
                + ' · ' + gpsSpeed + ' · ' + gpsAge;
        }
        var elGpsTimestamp = document.getElementById('modalGpsTimestamp');
        if (elGpsTimestamp) {
            elGpsTimestamp.textContent = acc.gpsLocationTimestamp || 'Not reported';
        }

        var circumference = acc.wheelCircumferenceM || hall.wheelCircumferenceM;
        var pulseCount = acc.hallPulseCount !== null && acc.hallPulseCount !== undefined
            ? acc.hallPulseCount
            : hall.pulseCount;
        var pulseAge = acc.hallPulseAgeMs !== null && acc.hallPulseAgeMs !== undefined
            ? acc.hallPulseAgeMs
            : hall.lastPulseAgeMs;
        var elHallConfiguration = document.getElementById('modalHallConfiguration');
        if (elHallConfiguration) {
            elHallConfiguration.textContent = [
                Number.isFinite(Number(circumference)) && Number(circumference) > 0
                    ? Number(circumference).toFixed(3) + ' m'
                    : 'Circumference unavailable',
                pulseCount !== null && pulseCount !== undefined
                    ? Number(pulseCount).toLocaleString() + ' pulses'
                    : 'Pulse count unavailable',
                pulseAge !== null && pulseAge !== undefined
                    ? (Number(pulseAge) / 1000).toFixed(1) + 's since pulse'
                    : null,
                acc.hallLastPulseAt
                    ? 'Last pulse ' + new Date(acc.hallLastPulseAt).toLocaleString()
                    : null
            ].filter(Boolean).join(' · ');
        }

        var rotation = acc.rotationDps !== null && acc.rotationDps !== undefined
            ? Number(acc.rotationDps).toFixed(1) + ' dps'
            : 'Rotation unavailable';
        var tilt = acc.tiltDegrees !== null && acc.tiltDegrees !== undefined
            ? Number(acc.tiltDegrees).toFixed(1) + '°'
            : 'Tilt unavailable';
        var elRotationTilt = document.getElementById('modalRotationTilt');
        if (elRotationTilt) elRotationTilt.textContent = rotation + ' · ' + tilt;

        var samples = Array.isArray(acc.preImpactSamples) ? acc.preImpactSamples : [];
        var elSamples = document.getElementById('modalPreImpactSamples');
        if (elSamples) {
            elSamples.textContent = samples.length
                ? samples.map(function (sample) {
                    var parts = [];
                    if (sample.ageMs !== undefined) parts.push('-' + sample.ageMs + ' ms');
                    parts.push('Hall ' + (sample.hallSpeedKmh !== null && sample.hallSpeedKmh !== undefined
                        ? Number(sample.hallSpeedKmh).toFixed(1) + ' km/h'
                        : (sample.hallStatus || 'unavailable')));
                    parts.push('GPS ' + (sample.gpsSpeedKmh !== null && sample.gpsSpeedKmh !== undefined
                        ? Number(sample.gpsSpeedKmh).toFixed(1) + ' km/h'
                        : 'unavailable'));
                    parts.push('MPU ' + (sample.magnitudeG !== undefined
                        ? Number(sample.magnitudeG).toFixed(2) + ' g'
                        : 'unavailable'));
                    return parts.join(' · ');
                }).join(' | ')
                : 'No sample history recorded.';
        }

        // C. Rider & Rental Information
        var elRider = document.getElementById('modalRiderName');
        if (elRider) elRider.textContent = acc.riderName || 'No Active Rental';

        var elRental = document.getElementById('modalRentalId');
        if (elRental) elRental.textContent = acc.rentalId || 'No Active Rental';

        var elRentalStatus = document.getElementById('modalRentalStatus');
        if (elRentalStatus) elRentalStatus.textContent = acc.rentalStatus || 'No Active Rental';

        var elAccountType = document.getElementById('modalAccountType');
        if (elAccountType) elAccountType.textContent = acc.accountType || 'Registered Account';

        // D. Location & Recorded GPS
        var elCoords = document.getElementById('modalGpsCoords');
        if (elCoords) elCoords.textContent = acc.locationText || 'No GPS Coordinates';

        var btnMap = document.getElementById('modalBtnMap');
        if (btnMap) {
            btnMap.href = acc.mapUrl || '#';
        }

        // E. Incident Description
        var elDesc = document.getElementById('modalDescription');
        if (elDesc) elDesc.textContent = acc.description || 'No description provided.';

        // F. Acknowledgment Section
        updateAckState(acc);

        // Switch to content
        var loadingEl = document.getElementById('modalLoadingState');
        if (loadingEl) loadingEl.style.display = 'none';

        var contentEl = document.getElementById('modalContentState');
        if (contentEl) contentEl.style.display = 'block';
    }

    function openAccidentDetailsModal(id) {
        if (!id) return;
        var strId = String(id);

        // Always show the modal immediately
        openModal();

        if (accidentsData && accidentsData[strId]) {
            renderModalContent(accidentsData[strId]);
            return;
        }

        var loadingEl = document.getElementById('modalLoadingState');
        if (loadingEl) {
            loadingEl.style.display = 'block';
            loadingEl.innerHTML = '<div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Loading incident details...</p>';
        }
        var contentEl = document.getElementById('modalContentState');
        if (contentEl) contentEl.style.display = 'none';

        fetch('/admin/accidents/' + encodeURIComponent(id) + '/details', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (data && data.success && data.incident) {
                if (!accidentsData) accidentsData = {};
                accidentsData[strId] = data.incident;
                renderModalContent(data.incident);
            } else {
                throw new Error(data && data.message ? data.message : 'Incident not found');
            }
        })
        .catch(function (err) {
            console.error('Failed to load incident details:', err);
            if (loadingEl) {
                loadingEl.innerHTML = '<div class="text-danger py-4"><i class="bi bi-exclamation-triangle me-2"></i>Failed to load incident details.</div>';
            }
        });
    }

    // Bind close triggers on modal
    if (modalEl) {
        var closeButtons = modalEl.querySelectorAll('[data-modal-close]');
        closeButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        });
    }

    // Dismiss with Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalEl && modalEl.classList.contains('open')) {
            closeModal();
        }
    });

    // Event delegation: Click handler for bicycle links
    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-view-accident]');
        if (link) {
            e.preventDefault();
            e.stopPropagation();
            var incidentId = link.getAttribute('data-view-accident');
            if (incidentId) {
                openAccidentDetailsModal(incidentId);
            }
        }
    });

    // Acknowledge Accident button handler
    var btnAck = document.getElementById('modalBtnAcknowledge');
    if (btnAck) {
        btnAck.addEventListener('click', function () {
            if (!currentAccident || currentAccident.acknowledged || isAcknowledging) return;

            var incidentId = currentAccident.id;
            var url = currentAccident.acknowledgeUrl || ('/admin/accidents/' + incidentId + '/acknowledge');

            isAcknowledging = true;
            btnAck.disabled = true;
            btnAck.innerHTML = '<i class="bi bi-hourglass-split me-1 spin"></i>Acknowledging...';

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                isAcknowledging = false;
                if (data && data.success) {
                    currentAccident.acknowledged = true;
                    currentAccident.acknowledgedBy = data.acknowledgedBy;
                    currentAccident.acknowledgedAt = data.acknowledgedAt;
                    if (!accidentsData) accidentsData = {};
                    accidentsData[String(incidentId)] = currentAccident;

                    updateAckState(currentAccident);

                    // Update row in table immediately
                    var rowCell = document.querySelector('[data-accident-ack-cell="' + incidentId + '"]');
                    if (rowCell) {
                        rowCell.innerHTML = '<span class="theft-badge theft-badge--success" title="Acknowledged by ' + (data.acknowledgedBy || 'Admin') + '"><i class="bi bi-check-circle-fill"></i> Acknowledged</span>';
                    }

                    // Decrement topbar notification badge if present
                    var notifBadge = document.getElementById('topbarNotifBadge');
                    if (notifBadge) {
                        var count = parseInt(notifBadge.textContent, 10);
                        if (!isNaN(count) && count > 0) {
                            var newCount = count - 1;
                            notifBadge.textContent = newCount > 99 ? '99+' : newCount;
                            if (newCount === 0) notifBadge.style.display = 'none';
                        }
                    }

                    // Trigger toast notification
                    if (window.PedalyaToast && typeof window.PedalyaToast.success === 'function') {
                        window.PedalyaToast.success(data.message || 'Accident report acknowledged.');
                    }
                }
            })
            .catch(function (err) {
                isAcknowledging = false;
                btnAck.disabled = false;
                btnAck.innerHTML = '<i class="bi bi-check-circle me-1"></i>Acknowledge Accident';
                if (window.PedalyaToast && typeof window.PedalyaToast.error === 'function') {
                    window.PedalyaToast.error('Failed to acknowledge accident report. Please try again.');
                }
            });
        });
    }

    // Auto-open incident if query param ?incident=ID is present
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('incident')) {
        var requestedId = urlParams.get('incident');
        if (requestedId) {
            openAccidentDetailsModal(requestedId);
        }
    }
})();
</script>
@endsection
