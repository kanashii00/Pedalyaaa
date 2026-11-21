@extends('layouts.admin')

@section('title', 'Theft Detection & Security Incidents')

@section('styles')
<style>
    #theftMap {
        width: 100%;
        height: 380px;
        border-radius: 14px;
        overflow: hidden;
    }

    .map-legend {
        position: absolute;
        bottom: 12px;
        left: 12px;
        z-index: 2;
        background: rgba(255,255,255,0.98);
        border-radius: 10px;
        padding: 10px 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        font-size: 0.82rem;
        color: #1a1a1a;
        border: 1px solid rgba(0,0,0,0.08);
    }
    .map-legend div {
        display: flex;
        align-items: center;
        margin-bottom: 4px;
    }
    .map-legend div:last-child { margin-bottom: 0; }
    .map-legend .dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
        flex-shrink: 0;
        border: 2px solid rgba(255,255,255,0.8);
        box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }
    .map-legend .legend-count {
        display: inline-block;
        min-width: 18px;
        text-align: center;
        margin-left: 6px;
        padding: 0 5px;
        border-radius: 9px;
        background: rgba(0,0,0,0.07);
        color: #555;
        font-weight: 700;
        font-size: 0.72rem;
    }
    .map-legend .legend-group {
        display: block;
        margin: 0 0 5px;
        font-size: 0.64rem;
        font-weight: 700;
        letter-spacing: 0.07em;
        text-transform: uppercase;
        color: #6b7280;
    }

    /* ---- Card & Filter Toolbar ---- */
    .theft-table-card {
        background: var(--surface, #0f172a);
        border: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.08));
        border-radius: var(--radius, 12px);
        box-shadow: var(--shadow-card, 0 4px 12px rgba(0, 0, 0, 0.15));
        overflow: hidden;
        margin-bottom: 24px;
    }
    .theft-filter-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: var(--surface-2, #1e293b);
        border-bottom: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.08));
    }
    .theft-filter-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }
    .theft-pill {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-2, #94a3b8);
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.12));
        text-decoration: none;
        transition: all 0.18s ease;
    }
    .theft-pill:hover {
        color: var(--text-1, #f8fafc);
        background: rgba(255, 255, 255, 0.08);
    }
    .theft-pill.active {
        color: #ffffff;
        background: var(--brand, #059669);
        border-color: var(--brand, #059669);
    }
    .theft-pill .count {
        margin-left: 6px;
        padding: 1px 7px;
        border-radius: 10px;
        background: rgba(0, 0, 0, 0.25);
        font-size: 11px;
    }

    .theft-search-form {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
    }
    .theft-search-form .form-select-sm,
    .theft-search-form .form-control {
        height: 34px;
        font-size: 12.5px;
        border-radius: 8px;
        background: var(--surface, #0f172a);
        border: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.12));
        color: var(--text-1, #f8fafc);
    }
    .theft-search-form .form-select-sm:focus,
    .theft-search-form .form-control:focus {
        border-color: var(--brand, #059669);
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.2);
    }

    /* ---- Responsive Horizontal Scroll Container ---- */
    .theft-table-scroll {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.4) transparent;
    }
    .theft-table-scroll::-webkit-scrollbar {
        height: 8px;
    }
    .theft-table-scroll::-webkit-scrollbar-track {
        background: transparent;
    }
    .theft-table-scroll::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.35);
        border-radius: 6px;
    }
    .theft-table-scroll::-webkit-scrollbar-thumb:hover {
        background: rgba(148, 163, 184, 0.6);
    }

    /* ---- Precision Styled Table ---- */
    .theft-table {
        width: 100%;
        min-width: 1560px;
        border-collapse: collapse;
        font-size: 13px;
        table-layout: fixed;
    }
    .theft-table thead th {
        background: var(--surface-2, #1e293b);
        color: var(--text-3, #94a3b8);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 12px 14px;
        border-bottom: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.08));
        vertical-align: middle;
        white-space: nowrap;
        user-select: none;
    }
    .theft-table tbody td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--border-subtle, rgba(255, 255, 255, 0.08));
        color: var(--text-2, #cbd5e1);
        vertical-align: middle;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 12.5px;
    }
    .theft-table tbody tr {
        transition: background 0.15s ease;
    }
    .theft-table tbody tr:hover {
        background: var(--surface-2, rgba(255, 255, 255, 0.04));
    }
    .theft-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Column specific alignments */
    .theft-col-id { width: 75px; text-align: left; }
    .theft-col-bike { width: 220px; text-align: left; }
    .theft-col-sev { width: 115px; text-align: center; }
    .theft-col-rider { width: 170px; text-align: left; }
    .theft-col-rental { width: 90px; text-align: center; }
    .theft-col-loc { width: 155px; text-align: left; }
    .theft-col-dist { width: 135px; text-align: right; }
    .theft-col-lock { width: 105px; text-align: center; }
    .theft-col-status { width: 135px; text-align: center; }
    .theft-col-ack { width: 135px; text-align: center; }
    .theft-col-time { width: 165px; text-align: left; }
    .theft-col-actions { width: 85px; text-align: right; }

    /* Clickable bicycle name with subtle hover */
    .theft-bike-link {
        color: var(--text-1, #f8fafc);
        text-decoration: none;
        cursor: pointer;
        display: inline-block;
        transition: color 0.18s ease, text-decoration 0.18s ease;
    }
    .theft-bike-link:hover,
    .theft-bike-link:focus {
        color: var(--brand, #10b981);
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    /* Modal Acknowledgment Banner */
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

    /* ---- Consistent Security Badges ---- */
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

    /* ---- Modal Styling ---- */
    .theft-modal-section {
        background: var(--bg-surface-2, rgba(255,255,255,0.03));
        border: 1px solid var(--border-subtle, rgba(255,255,255,0.08));
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 14px;
    }
    .theft-modal-section__title {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-3, #64748b);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .theft-field-label {
        font-size: 0.75rem;
        color: var(--text-3, #64748b);
        margin-bottom: 2px;
    }
    .theft-field-value {
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--text-1, #f8fafc);
    }

    .action-panel {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    /* Map Alert Overlay for Focused Incident */
    .theft-map-alert-overlay {
        position: absolute;
        top: 12px;
        left: 12px;
        right: 12px;
        z-index: 10;
        background: rgba(15, 23, 42, 0.94);
        border: 1px solid rgba(239, 68, 68, 0.45);
        border-radius: 10px;
        padding: 9px 14px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.45);
        backdrop-filter: blur(8px);
        animation: fadeInDown 0.2s ease;
    }
    .theft-map-alert-overlay strong {
        color: #f8fafc;
    }

    /* Distinct Theft Alert Marker on Map */
    .theft-focused-marker {
        z-index: 1000 !important;
    }
    .theft-marker-halo {
        position: absolute;
        top: 50%;
        left: 50%;
        width: 46px;
        height: 46px;
        margin-top: -23px;
        margin-left: -23px;
        border-radius: 50%;
        background: rgba(239, 68, 68, 0.35);
        border: 2px solid #ef4444;
        pointer-events: none;
        animation: theftHaloPulse 1.6s infinite ease-out;
    }
    .theft-marker-badge {
        position: absolute;
        top: -12px;
        right: -12px;
        background: #dc2626;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 10px;
        border: 2px solid #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.4);
        white-space: nowrap;
        pointer-events: none;
    }
    .theft-incident-map-pin {
        position: relative;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #dc2626;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-size: 15px;
        cursor: pointer;
    }
    @keyframes theftHaloPulse {
        0% { transform: scale(0.6); opacity: 1; }
        100% { transform: scale(2.2); opacity: 0; }
    }

    /* Compact Map Popup Overrides */
    .theft-map-popup {
        width: 280px;
        max-width: 100%;
    }
</style>
@endsection

@section('page-header')
    <h1>Theft Detection & Security Incidents</h1>
    <p>Real-time geofence monitoring, unauthorized movement detection, and incident response</p>
@endsection

@section('content')
<div class="row g-3 mb-4">
    <div class="col-md-3" data-theft-kpi="unresolved">
        <x-admin.kpi title="Unresolved Incidents" value="{{ $unresolvedCount }}"
                     icon="bi-shield-exclamation" color="var(--danger)" />
    </div>
    <div class="col-md-3" data-theft-kpi="critical">
        <x-admin.kpi title="Critical Thefts" value="{{ $criticalCount }}"
                     icon="bi-exclamation-octagon" color="#dc2626" />
    </div>
    <div class="col-md-3" data-theft-kpi="unacknowledged">
        <x-admin.kpi title="Unacknowledged Alerts" value="{{ $unacknowledgedCount }}"
                     icon="bi-bell" color="var(--warning)" />
    </div>
    <div class="col-md-3" data-theft-kpi="atRisk">
        <x-admin.kpi title="At-Risk Bicycles" value="{{ $bicycles->count() }}"
                     icon="bi-bicycle" color="var(--brand)" />
    </div>
</div>

{{-- Shared live location map: geofence + live bicycle markers --}}
<x-admin.card title="Live Security & Geofence Map" :flush="true" bodyClass="p-0 position-relative" id="theftMapCard">
    <x-slot name="tools">
        <small class="text-muted me-3">{{ count($mapBicycles ?? []) }} bicycle(s)</small>
        <small class="text-muted me-3">
            Geofence: <span id="theftGeofenceRadiusText">{{ number_format($geofence['radius'] ?? 0, 0) }}m</span>
            <span id="theftGeofenceAlertBadge">@if(($geofence['alertEnabled'] ?? false))
                <x-admin.badge type="success" label="Alerts ON"/>
            @endif</span>
        </small>
        <button class="btn-admin btn-admin--ghost btn-admin--sm" id="theftCenterMapBtn" title="Center on geofence">
            <i class="bi bi-crosshair"></i>
        </button>
        <button class="btn-admin btn-admin--ghost btn-admin--sm" id="theftRefreshMapBtn" title="Refresh positions">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </x-slot>

    {{-- Active Incident Focus Overlay Banner --}}
    <div id="theftMapActiveAlertBar" class="theft-map-alert-overlay" style="display: none;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 w-100">
            <div class="d-flex align-items-center gap-2">
                <span class="theft-badge theft-badge--critical"><i class="bi bi-shield-slash-fill"></i>Tracking Breach</span>
                <div>
                    <span id="overlayBikeName" class="fw-bold text-light">—</span>
                    <span class="text-muted small ms-2" id="overlayIncidentMeta">—</span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn-admin btn-admin--primary btn-admin--sm" id="overlayBtnReturnDetails">
                    <i class="bi bi-journal-text me-1"></i>Return to Incident Details
                </button>
                <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm" id="overlayBtnFitBounds" title="Fit geofence boundary & bicycle in view">
                    <i class="bi bi-aspect-ratio me-1"></i>Fit Boundary
                </button>
                <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm text-muted" id="overlayBtnClearFocus" title="Clear focus">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="theftMap"></div>
    <div class="map-legend">
        <div class="legend-group">Theft Detection</div>
        <div><span class="dot" style="background:#2ecc71;"></span>Inside Zone <span class="legend-count" data-count="safe">0</span></div>
        <div><span class="dot" style="background:#f39c12;"></span>Near Boundary <span class="legend-count" data-count="near">0</span></div>
        <div><span class="dot" style="background:#e74c3c;"></span>Outside Zone <span class="legend-count" data-count="outside">0</span></div>
        <div><span class="dot" style="background:#e74c3c;"></span>Has theft alert</div>
    </div>
</x-admin.card>

{{-- Alerts Table Card --}}
<div class="mt-4">
    <div class="theft-table-card">
        {{-- Card Head --}}
        <div class="admin-card__head">
            <div>
                <div class="admin-card__title">Theft Alert Log & Security Incidents</div>
                <div class="admin-card__sub">Comprehensive audit record of all boundary breaches and unauthorized relocations</div>
            </div>
            <div class="admin-card__tools d-flex align-items-center gap-2">
                <span id="theftUnreadBadge"><x-admin.badge type="danger" :label="$unacknowledgedCount . ' unacknowledged'" /></span>
                <span><x-admin.badge type="warning" :label="$unresolvedCount . ' unresolved'" /></span>
            </div>
        </div>

        {{-- Filter Toolbar --}}
        <div class="theft-filter-bar">
            <div class="theft-filter-pills">
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'all'])) }}"
                   class="theft-pill {{ ($statusFilter ?? 'all') === 'all' ? 'active' : '' }}">
                   All Incidents
                </a>
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'unresolved'])) }}"
                   class="theft-pill {{ ($statusFilter ?? '') === 'unresolved' ? 'active' : '' }}">
                   Unresolved <span class="count">{{ $unresolvedCount }}</span>
                </a>
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'open'])) }}"
                   class="theft-pill {{ ($statusFilter ?? '') === 'open' ? 'active' : '' }}">
                   Open
                </a>
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'investigating'])) }}"
                   class="theft-pill {{ ($statusFilter ?? '') === 'investigating' ? 'active' : '' }}">
                   Investigating
                </a>
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'resolved'])) }}"
                   class="theft-pill {{ ($statusFilter ?? '') === 'resolved' ? 'active' : '' }}">
                   Resolved
                </a>
                <a href="{{ route('admin.theft-alerts.index', array_merge(request()->query(), ['status' => 'false_alarm'])) }}"
                   class="theft-pill {{ ($statusFilter ?? '') === 'false_alarm' ? 'active' : '' }}">
                   False Alarm
                </a>
            </div>
            <form method="GET" action="{{ route('admin.theft-alerts.index') }}" class="theft-search-form">
                @if(request('status'))
                    <input type="hidden" name="status" value="{{ request('status') }}">
                @endif
                <select name="severity" class="form-select form-select-sm" style="width: 140px;" onchange="this.form.submit()">
                    <option value="all" {{ ($severityFilter ?? 'all') === 'all' ? 'selected' : '' }}>All Severities</option>
                    <option value="critical" {{ ($severityFilter ?? '') === 'critical' ? 'selected' : '' }}>Critical</option>
                    <option value="warning" {{ ($severityFilter ?? '') === 'warning' ? 'selected' : '' }}>Warning</option>
                    <option value="informational" {{ ($severityFilter ?? '') === 'informational' ? 'selected' : '' }}>Informational</option>
                </select>
                <div class="input-group input-group-sm" style="width: 220px;">
                    <input type="text" name="search" class="form-control" placeholder="Search alerts..." value="{{ $search ?? '' }}">
                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
                </div>
                @if($search || ($severityFilter && $severityFilter !== 'all') || ($statusFilter && $statusFilter !== 'all'))
                    <a href="{{ route('admin.theft-alerts.index') }}" class="btn-admin btn-admin--ghost btn-admin--sm" title="Clear filters">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </form>
        </div>

        {{-- Table Container with Horizontal Scroll --}}
        <div class="theft-table-scroll">
            <table class="theft-table">
                <colgroup>
                    <col class="theft-col-id">
                    <col class="theft-col-bike">
                    <col class="theft-col-sev">
                    <col class="theft-col-rider">
                    <col class="theft-col-rental">
                    <col class="theft-col-loc">
                    <col class="theft-col-dist">
                    <col class="theft-col-lock">
                    <col class="theft-col-status">
                    <col class="theft-col-ack">
                    <col class="theft-col-time">
                    <col class="theft-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="theft-col-id">Incident</th>
                        <th class="theft-col-bike">Bicycle</th>
                        <th class="theft-col-sev">Severity</th>
                        <th class="theft-col-rider">Rider</th>
                        <th class="theft-col-rental">Rental</th>
                        <th class="theft-col-loc">Location</th>
                        <th class="theft-col-dist">Distance Outside</th>
                        <th class="theft-col-lock">Lock Status</th>
                        <th class="theft-col-status">Status</th>
                        <th class="theft-col-ack">Acknowledgment</th>
                        <th class="theft-col-time">Detected At</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="theftTableBody">
                    @forelse($alerts as $alert)
                        @php
                            $loc = is_array($alert->gpsLocation) ? $alert->gpsLocation : [];
                            $lat = $loc['lat'] ?? null;
                            $lng = $loc['lng'] ?? null;
                            $riderName = $alert->riderDisplayName;
                            $severity = $alert->severity ?? 'critical';
                        @endphp
                        <tr data-incident-row="{{ $alert->id }}">
                            <td class="theft-col-id fw-bold">
                                #{{ $alert->id }}
                            </td>
                            <td class="theft-col-bike">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-bicycle me-2 text-muted" style="font-size: 15px;"></i>
                                    <div style="min-width: 0;">
                                        <a href="javascript:void(0)" class="theft-bike-link fw-semibold text-truncate d-block"
                                           data-view-incident="{{ $alert->id }}"
                                           title="Click to view Incident #{{ $alert->id }} details">
                                            {{ $alert->bicycle->name ?? 'Unknown' }}
                                        </a>
                                        <small class="text-muted d-block text-truncate">#{{ $alert->bicycleId }} &bull; {{ $alert->bicycle->serialNumber ?? 'N/A' }}</small>
                                    </div>
                                </div>
                            </td>
                            <td class="theft-col-sev">
                                @if($severity === 'critical')
                                    <span class="theft-badge theft-badge--critical"><i class="bi bi-shield-slash-fill"></i>Critical</span>
                                @elseif($severity === 'warning')
                                    <span class="theft-badge theft-badge--warning"><i class="bi bi-exclamation-triangle-fill"></i>Warning</span>
                                @else
                                    <span class="theft-badge theft-badge--info"><i class="bi bi-info-circle-fill"></i>Info</span>
                                @endif
                            </td>
                            <td class="theft-col-rider">
                                @if($riderName !== 'No Active Rental')
                                    <span class="fw-semibold text-truncate d-block" title="{{ $riderName }}">{{ $riderName }}</span>
                                @else
                                    <span class="theft-badge theft-badge--neutral">No Active Rental</span>
                                @endif
                            </td>
                            <td class="theft-col-rental">
                                @if($alert->rentalId)
                                    <code class="px-2 py-1 rounded" style="background: rgba(255,255,255,0.06); color: var(--brand); font-size: 11px;">#{{ $alert->rentalId }}</code>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="theft-col-loc">
                                @if($lat && $lng)
                                    <span class="font-monospace" style="font-size: 11.5px;">{{ number_format($lat, 5) }}, {{ number_format($lng, 5) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="theft-col-dist">
                                @php $dist = $alert->breachDistance ?? $alert->distanceFromBoundary; @endphp
                                @if($dist !== null)
                                    <span class="fw-semibold {{ $dist > 25 ? 'text-danger' : 'text-warning' }}">
                                        {{ number_format($dist, 1) }}m outside
                                    </span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="theft-col-lock">
                                @if($alert->bicycle?->lockStatus === 'locked')
                                    <span class="theft-badge theft-badge--critical"><i class="bi bi-lock-fill"></i>Locked</span>
                                @else
                                    <span class="theft-badge theft-badge--success"><i class="bi bi-unlock-fill"></i>Unlocked</span>
                                @endif
                            </td>
                            <td class="theft-col-status">
                                @if($alert->status === 'open')
                                    <span class="theft-badge theft-badge--critical">Open</span>
                                @elseif($alert->status === 'investigating')
                                    <span class="theft-badge theft-badge--warning"><i class="bi bi-search"></i>Investigating</span>
                                @elseif($alert->status === 'returned')
                                    <span class="theft-badge theft-badge--success">Returned</span>
                                @elseif($alert->status === 'resolved')
                                    <span class="theft-badge theft-badge--success">Resolved</span>
                                @elseif($alert->status === 'false_alarm')
                                    <span class="theft-badge theft-badge--neutral">False Alarm</span>
                                @else
                                    <span class="theft-badge theft-badge--neutral">{{ ucfirst($alert->status ?? 'Unknown') }}</span>
                                @endif
                            </td>
                            <td class="theft-col-ack">
                                @if($alert->acknowledged)
                                    <span class="theft-badge theft-badge--success"><i class="bi bi-check-circle"></i>Acknowledged</span>
                                @else
                                    <span class="theft-badge theft-badge--warning"><i class="bi bi-clock"></i>Pending</span>
                                @endif
                            </td>
                            <td class="theft-col-time">
                                <div class="fw-medium">{{ $alert->created_at->format('M d, Y h:i A') }}</div>
                                <small class="text-muted">{{ $alert->created_at->diffForHumans() }}</small>
                            </td>
                            <td class="col-actions theft-col-actions">
                                <div class="d-flex align-items-center justify-content-end">
                                    <a href="#theftMapCard"
                                       class="btn-admin btn-admin--ghost btn-admin--sm"
                                       data-focus-map-incident="{{ $alert->id }}"
                                       title="Focus on Security & Geofence Map"
                                       aria-label="View Live Location">
                                        <i class="bi bi-geo-alt me-1"></i>Live
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center py-4">
                                <x-admin.empty-state icon="bi-shield-check" title="No theft alerts found" message="All monitored bicycles are safely within bounds." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($alerts, 'links'))
            <div class="admin-table-foot">
                {{ $alerts->withQueryString()->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Incident Details & Management Modal --}}
<div class="admin-modal" id="incidentModal">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog admin-modal__dialog--lg">
        <div class="admin-modal__head">
            <div>
                <h3 id="modalIncidentTitle"><i class="bi bi-shield-exclamation me-2"></i>Theft Incident Details</h3>
                <small class="text-muted" id="modalIncidentSubtitle">Security incident telemetry and response</small>
            </div>
            <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-modal__body">
            <div id="modalLoadingState" class="text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="text-muted mt-2">Loading incident details...</p>
            </div>
            <div id="modalContentState" style="display:none;">
                {{-- Incident Overview Banner --}}
                <div class="d-flex align-items-center justify-content-between p-3 rounded mb-3" style="background: rgba(220, 38, 38, 0.1); border: 1px solid rgba(220, 38, 38, 0.2);">
                    <div>
                        <span id="modalSeverityBadge"></span>
                        <span id="modalStatusBadge" class="ms-2"></span>
                        <span id="modalAckBadge" class="ms-2"></span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted d-block">Detected</small>
                        <span class="fw-semibold" id="modalDetectedTime">—</span>
                    </div>
                </div>

                <div class="row g-3">
                    {{-- Bicycle & Hardware Telemetry --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section">
                            <div class="theft-modal-section__title"><i class="bi bi-bicycle"></i> Bicycle & Lock State</div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="theft-field-label">Bicycle Name</div>
                                    <div class="theft-field-value" id="modalBikeName">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Serial Number</div>
                                    <div class="theft-field-value" id="modalBikeSerial">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Physical Lock Status</div>
                                    <div class="theft-field-value" id="modalBikeLock">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Battery Level</div>
                                    <div class="theft-field-value" id="modalBikeBattery">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Condition</div>
                                    <div class="theft-field-value" id="modalBikeCondition">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Last Heartbeat</div>
                                    <div class="theft-field-value" id="modalBikeHeartbeat">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Dynamic Rider & Rental Identification --}}
                    <div class="col-md-6">
                        <div class="theft-modal-section">
                            <div class="theft-modal-section__title"><i class="bi bi-person-badge"></i> Rider & Rental Association</div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="theft-field-label">Current / Historical Rider</div>
                                    <div class="theft-field-value text-primary" id="modalRiderName">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Rental Record</div>
                                    <div class="theft-field-value" id="modalRentalId">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Rental Status</div>
                                    <div class="theft-field-value" id="modalRentalStatus">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Rider Email</div>
                                    <div class="theft-field-value" id="modalRiderEmail">—</div>
                                </div>
                                <div class="col-6">
                                    <div class="theft-field-label">Rider Phone</div>
                                    <div class="theft-field-value" id="modalRiderPhone">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Location & Boundary Breach --}}
                    <div class="col-12">
                        <div class="theft-modal-section">
                            <div class="theft-modal-section__title"><i class="bi bi-geo-alt"></i> Location & Boundary Telemetry</div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="theft-field-label">Last Known Coordinates</div>
                                    <div class="theft-field-value" id="modalGpsCoords">—</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="theft-field-label">Distance Outside Boundary</div>
                                    <div class="theft-field-value text-danger" id="modalBreachDistance">—</div>
                                </div>
                                <div class="col-md-4">
                                    <div class="theft-field-label">Last GPS Telemetry Update</div>
                                    <div class="theft-field-value" id="modalLastGpsUpdate">—</div>
                                </div>
                                <div class="col-12 mt-2">
                                    <div class="theft-field-label">Description & Alarm Summary</div>
                                    <div class="theft-field-value fw-normal" id="modalDescription">—</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Investigation & Resolution History --}}
                    <div class="col-12">
                        <div class="theft-modal-section">
                            <div class="theft-modal-section__title"><i class="bi bi-journal-text"></i> Audit Trail & Action History</div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="theft-field-label">Actions Taken</div>
                                    <div class="theft-field-value fw-normal" id="modalActionTaken">—</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="theft-field-label">Resolution Notes</div>
                                    <div class="theft-field-value fw-normal" id="modalResolutionNotes">—</div>
                                </div>
                                <div class="col-md-6" id="modalResolvedByRow" style="display:none;">
                                    <div class="theft-field-label">Resolved By</div>
                                    <div class="theft-field-value" id="modalResolvedBy">—</div>
                                </div>
                                <div class="col-md-6" id="modalResolvedAtRow" style="display:none;">
                                    <div class="theft-field-label">Resolved At</div>
                                    <div class="theft-field-value" id="modalResolvedAt">—</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Prominent Acknowledgment Box near bottom of modal --}}
                <div class="theft-modal-ack-card p-3 rounded mb-3" id="modalAckBanner">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-2">
                            <span id="modalAckStatusIcon" style="font-size: 1.35rem;"></span>
                            <div>
                                <div class="fw-bold" id="modalAckHeadline">Acknowledgment Status</div>
                                <div class="small text-muted" id="modalAckSubline">—</div>
                            </div>
                        </div>
                        <div id="modalAckActionWrap">
                            <button type="button" class="btn-admin btn-admin--primary" id="btnAckModal" aria-label="Acknowledge">
                                <i class="bi bi-check-circle me-1"></i>Acknowledge Alert
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Action Panel --}}
                <div class="theft-modal-section mt-3">
                    <div class="theft-modal-section__title"><i class="bi bi-shield-check"></i> Administrator Security Actions</div>
                    <div class="action-panel d-flex align-items-center flex-wrap gap-2" id="modalActionsToolbar">
                        <button type="button" class="btn-admin btn-admin--soft btn-admin--sm text-danger" id="btnLiveLocationModal" title="Focus Live Location on Security & Geofence Map">
                            <i class="bi bi-geo-alt-fill me-1"></i>View Live Location
                        </button>
                        <button type="button" class="btn-admin btn-admin--soft btn-admin--sm text-secondary" id="btnFalseAlarmModal">
                            <i class="bi bi-slash-circle me-1"></i>Mark as False Alarm
                        </button>
                        <button type="button" class="btn-admin btn-admin--soft btn-admin--sm text-success" id="btnResolveModal">
                            <i class="bi bi-shield-fill-check me-1"></i>Resolve Incident
                        </button>
                        <button type="button" class="btn-admin btn-admin--soft btn-admin--sm text-warning ms-auto" id="btnInvestigateModal">
                            <i class="bi bi-search me-1"></i>Investigate
                        </button>
                    </div>

                    {{-- Inline Confirmation & Resolution Note Form --}}
                    <div id="resolutionNoteContainer" class="mt-3 p-3 rounded" style="display:none; background: rgba(0,0,0,0.25); border: 1px solid var(--border-subtle);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label fw-bold mb-0" id="noteFormLabel">Resolution Note</label>
                            <span class="theft-badge theft-badge--warning" id="confirmationBadge"><i class="bi bi-exclamation-triangle"></i>Confirmation Required</span>
                        </div>
                        <p class="text-muted small mb-2" id="noteFormHelp">Please document the findings and actions taken. This will be recorded in the security audit trail.</p>
                        <textarea class="form-control mb-2" id="resolutionNoteInput" rows="3" placeholder="Enter findings or resolution details (minimum 3 characters)..."></textarea>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm" id="btnCancelNote">Cancel</button>
                            <button type="button" class="btn-admin btn-admin--primary btn-admin--sm" id="btnSubmitNote">Confirm &amp; Submit</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Close</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('js/geolibre-map.js') }}"></script>
<script>
(function () {
    var geofence = {!! json_encode($geofence) !!};
    var bicycles = {!! $mapBicycles->map(fn($b) => [
        'id' => $b->id,
        'name' => $b->name,
        'serialNumber' => $b->serialNumber,
        'rider' => $b->currentRiderUser?->name,
        'rental' => $b->currentRental ? ('#' . ($b->currentRental->rentalId ?? $b->currentRental->id)) : null,
        'lat' => $b->currentLat !== null ? (float) $b->currentLat : null,
        'lng' => $b->currentLng !== null ? (float) $b->currentLng : null,
        'gps_status' => $b->latestGpsLog ? ($b->latestGpsLog->latitude && $b->latestGpsLog->longitude ? 'fix' : 'last_known') : 'unavailable',
        'last_gps_update' => $b->lastGpsUpdate?->toISOString() ?? $b->latestGpsLog?->recordedAt?->toISOString(),
        'status' => $b->status,
        'battery' => $b->batteryLevel,
        'locked' => $b->lockStatus === 'locked',
        'heartbeat' => $b->lastHeartbeat?->toISOString(),
        'zone' => $b->zone['level'] ?? 'unknown',
        'distance' => $b->zone['distance'] ?? null,
    ])->values()->toJson() !!};
    var alertBicycles = {!! json_encode($openTheftAlerts) !!};
    var liveUrl = {!! json_encode(route('admin.theft-alerts.live')) !!};
    var POLL_MS = 30000;
    var currentModalIncidentId = null;
    var pendingActionType = null;

    var csrfToken = document.querySelector('meta[name="csrf-token"]')
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        : '';

    function severityBadge(sev) {
        if (sev === 'critical') return '<span class="theft-badge theft-badge--critical"><i class="bi bi-shield-slash-fill"></i>Critical</span>';
        if (sev === 'warning') return '<span class="theft-badge theft-badge--warning"><i class="bi bi-exclamation-triangle-fill"></i>Warning</span>';
        return '<span class="theft-badge theft-badge--info"><i class="bi bi-info-circle-fill"></i>Info</span>';
    }

    function statusBadge(status) {
        var map = {
            open: '<span class="theft-badge theft-badge--critical">Open</span>',
            investigating: '<span class="theft-badge theft-badge--warning"><i class="bi bi-search"></i>Investigating</span>',
            returned: '<span class="theft-badge theft-badge--success">Returned</span>',
            resolved: '<span class="theft-badge theft-badge--success">Resolved</span>',
            false_alarm: '<span class="theft-badge theft-badge--neutral">False Alarm</span>'
        };
        return map[status] || ('<span class="theft-badge theft-badge--neutral">' + (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown') + '</span>');
    }

    function lockBadge(locked) {
        return locked
            ? '<span class="theft-badge theft-badge--critical"><i class="bi bi-lock-fill"></i>Locked</span>'
            : '<span class="theft-badge theft-badge--success"><i class="bi bi-unlock-fill"></i>Unlocked</span>';
    }

    function ackBadge(ack) {
        return ack
            ? '<span class="theft-badge theft-badge--success"><i class="bi bi-check-circle"></i>Acknowledged</span>'
            : '<span class="theft-badge theft-badge--warning"><i class="bi bi-clock"></i>Pending</span>';
    }

    function fmtDist(d) {
        if (d === null || d === undefined) return '<span class="text-muted">&mdash;</span>';
        var n = Number(d);
        var cls = n > 25 ? 'text-danger' : 'text-warning';
        return '<span class="fw-semibold ' + cls + '">' + n.toFixed(1) + 'm outside</span>';
    }

    function fmtLatLng(lat, lng) {
        if (lat === null || lat === undefined || lng === null || lng === undefined) return '<span class="text-muted">&mdash;</span>';
        return '<span class="font-monospace" style="font-size: 11.5px;">' + Number(lat).toFixed(5) + ', ' + Number(lng).toFixed(5) + '</span>';
    }

    function fmtTime(iso) {
        if (!iso) return '<span class="text-muted">&mdash;</span>';
        var d = new Date(iso);
        var dateStr = d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        var timeStr = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        return '<div class="fw-medium">' + dateStr + ' ' + timeStr + '</div>';
    }

    function rowHtml(a) {
        var riderLabel = a.rider && a.rider !== 'No Active Rental'
            ? '<span class="fw-semibold text-truncate d-block" title="' + a.rider + '">' + a.rider + '</span>'
            : '<span class="theft-badge theft-badge--neutral">No Active Rental</span>';

        var rentalChip = a.rentalId
            ? '<code class="px-2 py-1 rounded" style="background: rgba(255,255,255,0.06); color: var(--brand); font-size: 11px;">#' + a.rentalId + '</code>'
            : '<span class="text-muted">&mdash;</span>';

        return '<tr data-incident-row="' + a.id + '">' +
            '<td class="theft-col-id fw-bold">#' + a.id + '</td>' +
            '<td class="theft-col-bike"><div class="d-flex align-items-center"><i class="bi bi-bicycle me-2 text-muted" style="font-size: 15px;"></i>' +
                '<div style="min-width: 0;">' +
                '<a href="javascript:void(0)" class="theft-bike-link fw-semibold text-truncate d-block" data-view-incident="' + a.id + '" title="Click to view Incident #' + a.id + ' details">' + (a.bicycle || 'Unknown') + '</a>' +
                '<small class="text-muted d-block text-truncate">#' + (a.bicycleId || '') + ' &bull; ' + (a.serialNumber || 'N/A') + '</small></div></div></td>' +
            '<td class="theft-col-sev">' + severityBadge(a.severity) + '</td>' +
            '<td class="theft-col-rider">' + riderLabel + '</td>' +
            '<td class="theft-col-rental">' + rentalChip + '</td>' +
            '<td class="theft-col-loc">' + fmtLatLng(a.lat, a.lng) + '</td>' +
            '<td class="theft-col-dist">' + fmtDist(a.distance) + '</td>' +
            '<td class="theft-col-lock">' + (a.lock_status === 'locked' ? lockBadge(true) : lockBadge(false)) + '</td>' +
            '<td class="theft-col-status">' + statusBadge(a.status) + '</td>' +
            '<td class="theft-col-ack">' + ackBadge(a.acknowledged) + '</td>' +
            '<td class="theft-col-time">' + fmtTime(a.timestamp) + '</td>' +
            '<td class="col-actions theft-col-actions"><div class="d-flex align-items-center justify-content-end">' +
                '<a href="/admin/monitoring?section=gps&bicycle=' + a.bicycleId + '" class="btn-admin btn-admin--ghost btn-admin--sm" title="View Live Location in GPS Tracking" aria-label="View Live Location"><i class="bi bi-geo-alt me-1"></i>Live</a>' +
            '</div></td>' +
            '</tr>';
    }

    function setKpi(key, value) {
        var n = document.querySelector('[data-theft-kpi="' + key + '"] .kpi__value');
        if (n && typeof value !== 'undefined') n.textContent = value;
    }

    function renderList(data) {
        if (!data) return;
        setKpi('openBreaches', data.openBreaches);
        setKpi('unresolved', data.unresolved);
        setKpi('critical', data.criticalCount);
        setKpi('unacknowledged', data.unacknowledged);
        setKpi('atRisk', data.atRisk);

        var unackBadge = document.getElementById('theftUnreadBadge');
        if (unackBadge && typeof data.unacknowledged !== 'undefined') {
            unackBadge.innerHTML = '<span class="badge-admin badge-admin--danger">' + data.unacknowledged + ' unacknowledged</span>';
        }

        var tbody = document.getElementById('theftTableBody');
        if (tbody && data.alerts) {
            tbody.innerHTML = data.alerts.length
                ? data.alerts.map(rowHtml).join('')
                : '<tr><td colspan="12" class="text-center py-4"><div class="admin-empty"><i class="bi bi-shield-check"></i><h4>No theft alerts found</h4><p class="text-muted">All monitored bicycles are safely within bounds.</p></div></td></tr>';
        }
    }

    function toast(msg, variant, title) {
        if (window.PedalyaToast && window.PedalyaToast[variant]) {
            window.PedalyaToast[variant](msg, title || 'Security Notification');
        } else if (window.PedalyaNotifications && window.PedalyaNotifications.toast) {
            window.PedalyaNotifications.toast(msg, variant, title);
        } else {
            alert(msg);
        }
    }

    function decrementNotificationBadges() {
        ['topbarNotifBadge', 'sidebarNotifBadge'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                var count = parseInt(el.textContent.replace(/\D/g, ''), 10) || 0;
                if (count > 1) {
                    el.textContent = count - 1;
                } else {
                    el.textContent = '0';
                    el.style.display = 'none';
                }
            }
        });
        var unreadLabel = document.getElementById('notifUnreadLabel');
        if (unreadLabel) {
            var count = parseInt(unreadLabel.textContent.replace(/\D/g, ''), 10) || 0;
            unreadLabel.textContent = Math.max(0, count - 1) + ' unread';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* Modal management */
    var modalEl = document.getElementById('incidentModal');
    function openModal() {
        if (modalEl) modalEl.classList.add('open');
    }
    function closeModal() {
        if (modalEl) modalEl.classList.remove('open');
        document.getElementById('resolutionNoteContainer').style.display = 'none';
        document.getElementById('resolutionNoteInput').value = '';
        pendingActionType = null;
    }

    Array.prototype.slice.call(document.querySelectorAll('[data-modal-close]')).forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalEl && modalEl.classList.contains('open')) {
            closeModal();
        }
    });

    function updateModalAckBanner(incident) {
        var banner = document.getElementById('modalAckBanner');
        var icon = document.getElementById('modalAckStatusIcon');
        var headline = document.getElementById('modalAckHeadline');
        var subline = document.getElementById('modalAckSubline');
        var wrap = document.getElementById('modalAckActionWrap');

        if (!banner || !icon || !headline || !subline || !wrap) return;

        if (incident.acknowledged) {
            banner.classList.remove('is-unacknowledged');
            icon.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
            headline.className = 'fw-bold text-success';
            headline.textContent = 'Incident Acknowledged';
            var by = incident.acknowledgedBy || 'Administrator';
            var at = incident.acknowledgedAt || incident.detectedAt || 'Recently';
            subline.innerHTML = 'Acknowledged by <strong class="text-light">' + escapeHtml(by) + '</strong> on <span>' + escapeHtml(at) + '</span>';
            wrap.innerHTML = '<span class="theft-badge theft-badge--success"><i class="bi bi-shield-check"></i>Acknowledged</span>';
        } else {
            banner.classList.add('is-unacknowledged');
            icon.innerHTML = '<i class="bi bi-bell-fill text-warning"></i>';
            headline.className = 'fw-bold text-warning';
            headline.textContent = 'Action Required: Alert Unacknowledged';
            subline.textContent = 'Acknowledge this security breach to record administrative review and silence escalations.';
            wrap.innerHTML = '<button type="button" class="btn-admin btn-admin--primary" id="btnAckModal" aria-label="Acknowledge"><i class="bi bi-check-circle me-1"></i>Acknowledge Alert</button>';
            var ackBtn = document.getElementById('btnAckModal');
            if (ackBtn) {
                ackBtn.addEventListener('click', function () {
                    ackBtn.disabled = true;
                    ackBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Acknowledging...';
                    executeIncidentAction('acknowledge', {});
                });
            }
        }
    }

    function loadIncidentDetails(id) {
        currentModalIncidentId = id;
        document.getElementById('modalLoadingState').style.display = 'block';
        document.getElementById('modalContentState').style.display = 'none';
        document.getElementById('resolutionNoteContainer').style.display = 'none';
        openModal();

        fetch('/admin/theft/' + encodeURIComponent(id) + '/details', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            document.getElementById('modalLoadingState').style.display = 'none';
            document.getElementById('modalContentState').style.display = 'block';

            document.getElementById('modalIncidentTitle').innerHTML = '<i class="bi bi-shield-exclamation me-2"></i>Theft Incident #' + data.id + ' &mdash; ' + (data.bicycle.name || 'Bicycle');
            document.getElementById('modalIncidentSubtitle').textContent = 'Incident ID: #' + data.id + ' • Detected on ' + data.incident.detectedAt + ' (' + data.incident.detectedAtAgo + ')';

            document.getElementById('modalSeverityBadge').innerHTML = severityBadge(data.incident.severity);
            document.getElementById('modalStatusBadge').innerHTML = statusBadge(data.incident.status);
            document.getElementById('modalAckBadge').innerHTML = ackBadge(data.incident.acknowledged);
            document.getElementById('modalDetectedTime').textContent = data.incident.detectedAt;

            // Bicycle
            document.getElementById('modalBikeName').textContent = data.bicycle.name;
            document.getElementById('modalBikeSerial').textContent = data.bicycle.serialNumber;
            document.getElementById('modalBikeLock').innerHTML = lockBadge(data.bicycle.lockStatus === 'locked');
            document.getElementById('modalBikeBattery').textContent = data.bicycle.batteryLevel !== null ? (data.bicycle.batteryLevel + '%') : 'N/A';
            document.getElementById('modalBikeCondition').textContent = (data.bicycle.condition || 'N/A').toUpperCase();
            document.getElementById('modalBikeHeartbeat').textContent = data.bicycle.lastHeartbeat;

            // Rider & Rental
            var riderNameEl = document.getElementById('modalRiderName');
            if (data.rider.displayName === 'No Active Rental') {
                riderNameEl.innerHTML = '<span class="theft-badge theft-badge--neutral">No Active Rental</span>';
            } else {
                riderNameEl.textContent = data.rider.displayName;
            }
            document.getElementById('modalRentalId').textContent = data.rental ? ('#' + data.rental.id) : 'None';
            document.getElementById('modalRentalStatus').textContent = data.rental ? (data.rental.status.toUpperCase()) : 'N/A';
            document.getElementById('modalRiderEmail').textContent = data.rider.email || '—';
            document.getElementById('modalRiderPhone').textContent = data.rider.phoneNumber || '—';

            // Location
            document.getElementById('modalGpsCoords').innerHTML = fmtLatLng(data.incident.lat, data.incident.lng);
            document.getElementById('modalBreachDistance').innerHTML = fmtDist(data.incident.distance);
            document.getElementById('modalLastGpsUpdate').textContent = data.incident.lastUpdate + ' (' + data.incident.lastUpdateAgo + ')';
            document.getElementById('modalDescription').textContent = data.incident.description || 'No description logged.';

            // Audit & Actions
            document.getElementById('modalActionTaken').textContent = data.incident.actionTaken || 'None';
            document.getElementById('modalResolutionNotes').textContent = data.incident.resolutionNotes || 'None';

            var resByRow = document.getElementById('modalResolvedByRow');
            var resAtRow = document.getElementById('modalResolvedAtRow');
            if (data.incident.resolvedBy) {
                resByRow.style.display = 'block';
                document.getElementById('modalResolvedBy').textContent = data.incident.resolvedBy;
            } else {
                resByRow.style.display = 'none';
            }
            if (data.incident.resolvedAt) {
                resAtRow.style.display = 'block';
                document.getElementById('modalResolvedAt').textContent = data.incident.resolvedAt;
            } else {
                resAtRow.style.display = 'none';
            }

            // Live location on security map
            var liveBtn = document.getElementById('btnLiveLocationModal');
            if (liveBtn) {
                liveBtn.onclick = function (e) {
                    e.preventDefault();
                    focusMapOnIncident(data.id);
                };
            }

            // Prominent Acknowledgment Box
            updateModalAckBanner(data.incident);

            // Update action button states
            var isResolved = data.incident.status === 'resolved' || data.incident.status === 'false_alarm';
            document.getElementById('btnInvestigateModal').disabled = isResolved || data.incident.status === 'investigating';
            document.getElementById('btnFalseAlarmModal').disabled = isResolved;
            document.getElementById('btnResolveModal').disabled = isResolved;
        })
        .catch(function () {
            toast('Failed to load incident details.', 'error', 'Error');
            closeModal();
        });
    }

    function executeIncidentAction(action, payload) {
        if (!currentModalIncidentId) return;

        var url = '/admin/theft/' + encodeURIComponent(currentModalIncidentId) + '/' + action;
        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload || {})
        })
        .then(function (r) {
            return r.json().then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
            if (res.status >= 200 && res.status < 300) {
                toast(res.data.message || 'Action executed successfully.', 'success', 'Security Action');

                if (action === 'acknowledge') {
                    // Update prominent acknowledgment banner immediately
                    updateModalAckBanner({
                        acknowledged: true,
                        acknowledgedBy: res.data.acknowledgedBy || 'Administrator',
                        acknowledgedAt: res.data.acknowledgedAt || new Date().toLocaleString()
                    });

                    // Update top banner in modal
                    var modalAckBadge = document.getElementById('modalAckBadge');
                    if (modalAckBadge) modalAckBadge.innerHTML = ackBadge(true);

                    // Update table row in DOM immediately
                    var row = document.querySelector('[data-incident-row="' + currentModalIncidentId + '"]');
                    if (row) {
                        var ackCell = row.querySelector('.theft-col-ack');
                        if (ackCell) ackCell.innerHTML = ackBadge(true);
                    }

                    // Decrement unacknowledged KPI and notification badges
                    var unackKpi = document.querySelector('[data-theft-kpi="unacknowledged"] .kpi__value');
                    if (unackKpi) {
                        var curVal = parseInt(unackKpi.textContent, 10) || 0;
                        if (curVal > 0) unackKpi.textContent = curVal - 1;
                    }
                    decrementNotificationBadges();
                }

                document.getElementById('resolutionNoteContainer').style.display = 'none';
                document.getElementById('resolutionNoteInput').value = '';
                pendingActionType = null;

                loadIncidentDetails(currentModalIncidentId);
                fetch(liveUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(renderList);
            } else {
                var errMsg = res.data.message || 'Action failed.';
                if (res.data.errors) {
                    var first = Object.values(res.data.errors)[0];
                    if (first) errMsg = first[0] || errMsg;
                }
                toast(errMsg, 'error', 'Action Failed');
                var ackBtn = document.getElementById('btnAckModal');
                if (ackBtn) ackBtn.disabled = false;
            }
        })
        .catch(function () {
            toast('A network error occurred while executing the action.', 'error', 'Network Error');
            var ackBtn = document.getElementById('btnAckModal');
            if (ackBtn) ackBtn.disabled = false;
        });
    }

    // Modal action button triggers with confirmation
    document.getElementById('btnInvestigateModal').addEventListener('click', function () {
        pendingActionType = 'investigate';
        document.getElementById('noteFormLabel').textContent = 'Confirm Action: Initiate Investigation';
        document.getElementById('noteFormHelp').textContent = 'Document the reason or instructions for initiating this security investigation (e.g. security team dispatched).';
        document.getElementById('confirmationBadge').style.display = 'inline-flex';
        document.getElementById('resolutionNoteContainer').style.display = 'block';
        document.getElementById('resolutionNoteInput').focus();
    });

    document.getElementById('btnFalseAlarmModal').addEventListener('click', function () {
        pendingActionType = 'false-alarm';
        document.getElementById('noteFormLabel').textContent = 'Confirm Action: Mark as False Alarm (Required)';
        document.getElementById('noteFormHelp').textContent = 'Please confirm this is a false alarm and provide justification (e.g. authorized campus transport, maintenance test).';
        document.getElementById('confirmationBadge').style.display = 'inline-flex';
        document.getElementById('resolutionNoteContainer').style.display = 'block';
        document.getElementById('resolutionNoteInput').focus();
    });

    document.getElementById('btnResolveModal').addEventListener('click', function () {
        pendingActionType = 'resolve';
        document.getElementById('noteFormLabel').textContent = 'Confirm Action: Resolve Security Incident (Required)';
        document.getElementById('noteFormHelp').textContent = 'Please confirm the bicycle has been recovered or secured and document the resolution steps before closing.';
        document.getElementById('confirmationBadge').style.display = 'inline-flex';
        document.getElementById('resolutionNoteContainer').style.display = 'block';
        document.getElementById('resolutionNoteInput').focus();
    });

    document.getElementById('btnCancelNote').addEventListener('click', function () {
        document.getElementById('resolutionNoteContainer').style.display = 'none';
        document.getElementById('resolutionNoteInput').value = '';
        pendingActionType = null;
    });

    document.getElementById('btnSubmitNote').addEventListener('click', function () {
        var note = document.getElementById('resolutionNoteInput').value.trim();
        if ((pendingActionType === 'false-alarm' || pendingActionType === 'resolve') && note.length < 3) {
            toast('A resolution note (minimum 3 characters) is required to confirm this action.', 'error', 'Confirmation Note Required');
            document.getElementById('resolutionNoteInput').focus();
            return;
        }

        if (pendingActionType === 'investigate') {
            executeIncidentAction('investigate', { notes: note });
        } else if (pendingActionType === 'false-alarm') {
            executeIncidentAction('false-alarm', { resolution_notes: note });
        } else if (pendingActionType === 'resolve') {
            executeIncidentAction('resolve', { resolution_notes: note });
        }
    });

    // Map focus tracking state
    var focusedIncidentMarker = null;
    var customIncidentMarker = null;
    var focusedIncidentId = null;
    var focusedIncidentData = null;

    function clearMapFocus() {
        if (focusedIncidentMarker) {
            var markerEl = focusedIncidentMarker.getElement();
            if (markerEl) {
                markerEl.classList.remove('theft-focused-marker');
                var halo = markerEl.querySelector('.theft-marker-halo');
                if (halo) halo.remove();
                var badge = markerEl.querySelector('.theft-marker-badge');
                if (badge) badge.remove();
            }
        }
        if (customIncidentMarker) {
            customIncidentMarker.remove();
            customIncidentMarker = null;
        }
        focusedIncidentMarker = null;
        var overlay = document.getElementById('theftMapActiveAlertBar');
        if (overlay) overlay.style.display = 'none';
        focusedIncidentId = null;
        focusedIncidentData = null;
    }

    function createTheftPopup(html) {
        var p = new maplibregl.Popup({
            offset: {
                'top': [0, 8],
                'top-left': [0, 8],
                'top-right': [0, 8],
                'bottom': [0, -32],
                'bottom-left': [0, -32],
                'bottom-right': [0, -32],
                'left': [16, -14],
                'right': [-16, -14]
            },
            closeButton: true,
            closeOnClick: false,
            maxWidth: '310px',
            className: 'pedalya-map-popup-wrap'
        }).setHTML(html);

        p.on('open', function () {
            if (mapCtx && mapCtx.activePopup && mapCtx.activePopup !== p) {
                mapCtx.activePopup.remove();
            }
            if (mapCtx) mapCtx.activePopup = p;
        });
        p.on('close', function () {
            if (mapCtx && mapCtx.activePopup === p) {
                mapCtx.activePopup = null;
            }
        });

        return p;
    }

    function buildTheftPopupHtml(data, isLive, lat, lng, gpsUpdateText) {
        var distanceFormatted = fmtDist(data.incident.distance);
        var isBreach = data.incident.distance !== null && data.incident.distance > 0;
        var distClass = isBreach ? 'text-danger' : 'text-success';

        var riderDisplay = data.rider.displayName === 'No Active Rental'
            ? '<span class="theft-badge theft-badge--neutral">No Active Rental</span>'
            : '<strong class="text-light">' + escapeHtml(data.rider.displayName) + '</strong>';

        var rentalPart = data.rental
            ? '<span class="text-muted ms-auto font-monospace"><code style="color:var(--brand);">#' + escapeHtml(data.rental.id) + '</code></span>'
            : '';

        var riderRow = (data.rider.displayName && data.rider.displayName !== 'No Active Rental')
            ? '<div class="pedalya-map-popup__rider">' +
                '<span><i class="bi bi-person-fill text-muted me-1"></i>' + riderDisplay + '</span>' +
                rentalPart +
              '</div>'
            : '';

        var gpsClass = isLive ? 'text-success' : 'text-warning';
        var gpsIcon = isLive ? 'bi-broadcast' : 'bi-clock-history';
        var gpsStatusTitle = isLive ? 'Live GPS Fix' : 'Last Known Location';

        var lockBadgeHtml = (data.bicycle.lockStatus === 'locked')
            ? '<span class="theft-badge theft-badge--critical"><i class="bi bi-lock-fill"></i>Locked</span>'
            : '<span class="theft-badge theft-badge--success"><i class="bi bi-unlock-fill"></i>Unlocked</span>';

        var statusBadgeHtml = statusBadge(data.incident.status);

        return '<div class="pedalya-map-popup">' +
            '<div class="pedalya-map-popup__header">' +
                '<div class="pedalya-map-popup__badges">' +
                    '<span class="theft-badge theft-badge--critical"><i class="bi bi-shield-slash-fill"></i>Incident #' + data.id + '</span>' +
                    severityBadge(data.incident.severity) +
                    statusBadgeHtml +
                '</div>' +
            '</div>' +
            '<div class="mb-2">' +
                '<div class="pedalya-map-popup__title" title="' + escapeHtml(data.bicycle.name) + '">' + escapeHtml(data.bicycle.name) + '</div>' +
                '<div class="pedalya-map-popup__subtitle">' +
                    '<span>#' + data.bicycle.id + '</span>' +
                    '<span>&bull;</span>' +
                    '<span class="font-monospace">' + escapeHtml(data.bicycle.serialNumber || 'N/A') + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="pedalya-map-popup__grid">' +
                '<div class="pedalya-map-popup__grid-item">' +
                    '<span class="pedalya-map-popup__grid-label">Lock</span>' +
                    '<span class="pedalya-map-popup__grid-value">' + lockBadgeHtml + '</span>' +
                '</div>' +
                '<div class="pedalya-map-popup__grid-item">' +
                    '<span class="pedalya-map-popup__grid-label">Boundary</span>' +
                    '<span class="pedalya-map-popup__grid-value ' + distClass + '">' + distanceFormatted + '</span>' +
                '</div>' +
                '<div class="pedalya-map-popup__grid-item">' +
                    '<span class="pedalya-map-popup__grid-label">Status</span>' +
                    '<span class="pedalya-map-popup__grid-value text-capitalize">' + escapeHtml(data.incident.status || 'Open') + '</span>' +
                '</div>' +
            '</div>' +
            riderRow +
            '<div class="pedalya-map-popup__telemetry">' +
                '<div class="pedalya-map-popup__coords">' +
                    '<span><i class="bi bi-geo-alt-fill text-muted me-1"></i>Coordinates</span>' +
                    '<code>' + Number(lat).toFixed(5) + ', ' + Number(lng).toFixed(5) + '</code>' +
                '</div>' +
                '<div class="pedalya-map-popup__gps-status">' +
                    '<span class="' + gpsClass + ' fw-semibold"><i class="bi ' + gpsIcon + ' me-1"></i>' + gpsStatusTitle + '</span>' +
                    '<span class="text-muted">' + escapeHtml(gpsUpdateText) + '</span>' +
                '</div>' +
            '</div>' +
            '<button type="button" class="btn-admin btn-admin--primary btn-admin--sm w-100" data-return-to-details="' + data.id + '">' +
                '<i class="bi bi-journal-text me-1"></i>Return to Incident Details' +
            '</button>' +
        '</div>';
    }

    function applyMapFocus(data) {
        // 1. Close incident details modal if open
        closeModal();

        // 2. Smoothly scroll down to the Live Security & Geofence Map card
        var mapCard = document.getElementById('theftMapCard') || document.getElementById('theftMap');
        if (mapCard) {
            mapCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // 3. Preserve selected incident context in browser URL without full reload
        var url = new URL(window.location.href);
        url.searchParams.set('incident', data.id);
        url.searchParams.set('focus', 'map');
        window.history.pushState({ incidentId: data.id, focus: 'map' }, '', url.toString());

        // 4. Retrieve latest coordinates and GPS status
        var tracked = mapCtx && mapCtx.markers ? mapCtx.markers[data.bicycle.id] : null;
        var lat = null, lng = null;
        var isLive = false;
        var gpsUpdateText = data.incident.lastUpdate || 'Unknown';

        if (tracked && tracked.bike && typeof tracked.bike.lat === 'number' && typeof tracked.bike.lng === 'number'
            && !isNaN(tracked.bike.lat) && !isNaN(tracked.bike.lng)) {
            lat = tracked.bike.lat;
            lng = tracked.bike.lng;
            isLive = (tracked.bike.gps_status === 'fix');
            gpsUpdateText = tracked.bike.last_gps_update
                ? new Date(tracked.bike.last_gps_update).toLocaleString()
                : data.incident.lastUpdate;
        } else if (data.incident.lat !== null && data.incident.lng !== null) {
            lat = Number(data.incident.lat);
            lng = Number(data.incident.lng);
            isLive = false;
            gpsUpdateText = data.incident.lastUpdate + ' (Last Known Location)';
        }

        if (lat === null || lng === null || isNaN(lat) || isNaN(lng)) {
            toast('No GPS coordinates recorded for Incident #' + data.id + ' yet.', 'warning', 'No GPS Telemetry');
            if (geofence && geofence.centerLat && geofence.centerLng) {
                lat = Number(geofence.centerLat);
                lng = Number(geofence.centerLng);
            } else {
                return;
            }
        }

        // 5. Center and fly map to coordinates
        if (mapCtx && mapCtx.map) {
            mapCtx.map.flyTo({
                center: [lng, lat],
                zoom: 16.5,
                pitch: 50,
                bearing: -15,
                duration: 1200
            });
        }

        // 6. Highlight marker on map
        clearMapFocus();
        focusedIncidentId = data.id;
        focusedIncidentData = data;
        focusedIncidentData.focusedLat = lat;
        focusedIncidentData.focusedLng = lng;

        var popupHtml = buildTheftPopupHtml(data, isLive, lat, lng, gpsUpdateText);

        if (tracked && tracked.marker) {
            focusedIncidentMarker = tracked.marker;
            var markerEl = tracked.marker.getElement();
            if (markerEl) {
                markerEl.classList.add('theft-focused-marker');
                var halo = document.createElement('span');
                halo.className = 'theft-marker-halo';
                markerEl.appendChild(halo);
                var badge = document.createElement('span');
                badge.className = 'theft-marker-badge';
                badge.textContent = '#' + data.id;
                markerEl.appendChild(badge);
            }
            var p = tracked.marker.getPopup();
            if (p) {
                p.setHTML(popupHtml);
                if (!p.isOpen()) {
                    p.addTo(mapCtx.map);
                }
            } else {
                p = createTheftPopup(popupHtml);
                tracked.marker.setPopup(p);
                p.addTo(mapCtx.map);
            }
            if (mapCtx) mapCtx.activePopup = p;
        } else if (mapCtx && mapCtx.map) {
            var pinEl = document.createElement('div');
            pinEl.className = 'theft-incident-map-pin theft-focused-marker';
            pinEl.innerHTML = '<span class="theft-marker-halo"></span><i class="bi bi-bicycle"></i><span class="theft-marker-badge">#' + data.id + '</span>';
            var p2 = createTheftPopup(popupHtml);
            customIncidentMarker = new maplibregl.Marker({ element: pinEl })
                .setLngLat([lng, lat])
                .setPopup(p2)
                .addTo(mapCtx.map);
            customIncidentMarker.togglePopup();
            focusedIncidentMarker = customIncidentMarker;
            if (mapCtx) mapCtx.activePopup = p2;
        }

        // 7. Update and display floating top banner over the map
        var alertBar = document.getElementById('theftMapActiveAlertBar');
        var bikeNameNode = document.getElementById('overlayBikeName');
        var metaNode = document.getElementById('overlayIncidentMeta');
        if (alertBar && bikeNameNode && metaNode) {
            bikeNameNode.textContent = data.bicycle.name + ' (' + (data.bicycle.serialNumber || 'N/A') + ')';
            var riderPart = data.rider.displayName === 'No Active Rental'
                ? 'No Active Rental'
                : 'Rider: ' + escapeHtml(data.rider.displayName);
            var lockPart = data.bicycle.lockStatus === 'locked' ? 'Locked' : 'Unlocked';
            var gpsPart = isLive
                ? '<span class="text-success"><i class="bi bi-broadcast me-1"></i>Live Fix</span>'
                : '<span class="text-warning"><i class="bi bi-clock-history me-1"></i>Last Known (' + escapeHtml(data.incident.lastUpdateAgo) + ')</span>';

            metaNode.innerHTML = '<strong>Incident #' + data.id + '</strong> &bull; ' +
                riderPart + ' &bull; ' +
                lockPart + ' &bull; ' +
                fmtDist(data.incident.distance) + ' &bull; ' +
                gpsPart;

            alertBar.style.display = 'block';
        }
    }

    function focusMapOnIncident(incidentId) {
        if (!incidentId) return;

        fetch('/admin/theft/' + encodeURIComponent(incidentId) + '/details', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            applyMapFocus(data);
        })
        .catch(function () {
            toast('Failed to load incident coordinates for map focus.', 'error');
        });
    }

    // Delegation for clicking bicycle names, return to details, and live focus buttons
    document.addEventListener('click', function (e) {
        var incidentTrigger = e.target.closest('[data-view-incident]');
        if (incidentTrigger) {
            e.preventDefault();
            var id = incidentTrigger.getAttribute('data-view-incident');
            if (id) {
                loadIncidentDetails(id);
            }
            return;
        }

        var mapFocusTrigger = e.target.closest('[data-focus-map-incident]');
        if (mapFocusTrigger) {
            e.preventDefault();
            var mapIncId = mapFocusTrigger.getAttribute('data-focus-map-incident');
            if (mapIncId) {
                focusMapOnIncident(mapIncId);
            }
            return;
        }

        var returnDetailsTrigger = e.target.closest('[data-return-to-details]');
        if (returnDetailsTrigger) {
            e.preventDefault();
            var retId = returnDetailsTrigger.getAttribute('data-return-to-details');
            if (retId) {
                loadIncidentDetails(retId);
            }
            return;
        }
    });

    // Floating map overlay banner buttons
    var overlayBtnReturn = document.getElementById('overlayBtnReturnDetails');
    if (overlayBtnReturn) {
        overlayBtnReturn.addEventListener('click', function () {
            if (focusedIncidentId) {
                loadIncidentDetails(focusedIncidentId);
            }
        });
    }

    var overlayBtnFit = document.getElementById('overlayBtnFitBounds');
    if (overlayBtnFit) {
        overlayBtnFit.addEventListener('click', function () {
            if (!mapCtx || !mapCtx.map || !focusedIncidentData) return;
            var bLat = focusedIncidentData.focusedLat;
            var bLng = focusedIncidentData.focusedLng;
            if (typeof bLat !== 'number' || typeof bLng !== 'number') return;

            var gfCenterLat = geofence && geofence.centerLat ? Number(geofence.centerLat) : bLat;
            var gfCenterLng = geofence && geofence.centerLng ? Number(geofence.centerLng) : bLng;

            var minLng = Math.min(gfCenterLng, bLng) - 0.003;
            var maxLng = Math.max(gfCenterLng, bLng) + 0.003;
            var minLat = Math.min(gfCenterLat, bLat) - 0.003;
            var maxLat = Math.max(gfCenterLat, bLat) + 0.003;

            mapCtx.map.fitBounds([[minLng, minLat], [maxLng, maxLat]], {
                padding: 60,
                duration: 1000
            });
        });
    }

    var overlayBtnClear = document.getElementById('overlayBtnClearFocus');
    if (overlayBtnClear) {
        overlayBtnClear.addEventListener('click', function () {
            clearMapFocus();
            var url = new URL(window.location.href);
            url.searchParams.delete('focus');
            window.history.pushState({}, '', url.toString());
        });
    }

    window.addEventListener('popstate', function (e) {
        var urlParams = new URLSearchParams(window.location.search);
        var incId = urlParams.get('incident');
        var focus = urlParams.get('focus');
        if (incId) {
            if (focus === 'map') {
                focusMapOnIncident(incId);
            } else {
                loadIncidentDetails(incId);
            }
        } else {
            closeModal();
            clearMapFocus();
        }
    });

    // Map init
    var mapCtx = window.PedalyaGeoLibre.init({
        container: 'theftMap',
        geofence: geofence,
        bicycles: bicycles,
        alertBicycles: alertBicycles,
        liveUrl: liveUrl,
        pollMs: POLL_MS,
        zoom: 15,
        pitch: 55,
        bearing: -15,
        readout: { radius: 'theftGeofenceRadiusText', alertBadge: 'theftGeofenceAlertBadge' },
        buttons: { center: 'theftCenterMapBtn', refresh: 'theftRefreshMapBtn' },
        legendCounts: true,
        onAlertsChange: renderList
    });

    // Initial fetch
    fetch(liveUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(renderList).catch(function () {});

    // Auto-open or focus if query parameter ?incident=... present
    @if(isset($selectedIncident) && $selectedIncident)
        @if(($focusParam ?? '') === 'map')
            focusMapOnIncident({{ $selectedIncident->id }});
        @else
            loadIncidentDetails({{ $selectedIncident->id }});
        @endif
    @endif
})();
</script>
@endsection
