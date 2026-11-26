@extends('layouts.admin')

@section('title', 'Rental Management')

@section('styles')
<style>
    /* ============================================================
       Active Rentals — Redesigned Module
       ============================================================ */

    /* ---- Filter card ---- */
    .rv-filter-card {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius);
        box-shadow: var(--shadow-card);
        padding: 14px 18px;
        margin-bottom: 16px;
    }
    .rv-filter-head {
        display: flex; align-items: center; gap: 8px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.08em;
        text-transform: uppercase; color: var(--text-3); margin-bottom: 12px;
    }
    .rv-filter-head i { font-size: 13px; color: var(--brand); }
    .rv-filter-grid {
        display: grid; gap: 10px; align-items: end;
        grid-template-columns: minmax(200px, 2fr) minmax(130px, 1fr) minmax(120px, 1fr) minmax(120px, 1fr) minmax(140px, 1fr) minmax(130px, 1fr) auto;
    }
    .rv-filter-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .rv-filter-field > label {
        font-size: 10px; font-weight: 700; letter-spacing: 0.06em;
        text-transform: uppercase; color: var(--text-3); padding-left: 2px; white-space: nowrap;
    }
    .rv-filter-ctrl { position: relative; }
    .rv-filter-ctrl > i {
        position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
        color: var(--text-3); font-size: 13px; pointer-events: none;
    }
    .rv-filter-ctrl input,
    .rv-filter-ctrl select {
        width: 100%; height: 34px; padding: 0 10px; font-size: 12.5px;
        color: var(--text-1); background: var(--surface-2);
        border: 1px solid var(--border-subtle); border-radius: 8px;
        transition: border-color 0.15s, box-shadow 0.15s;
        outline: none; appearance: none;
    }
    .rv-filter-ctrl.has-icon input { padding-left: 32px; }
    .rv-filter-ctrl select { padding-right: 24px; cursor: pointer; }
    .rv-filter-ctrl::after {
        content: '\F282'; font-family: 'bootstrap-icons'; font-size: 10px;
        color: var(--text-3); position: absolute; right: 10px; top: 50%;
        transform: translateY(-50%); pointer-events: none;
    }
    .rv-filter-ctrl.no-caret::after { display: none; }
    .rv-filter-ctrl input:hover,
    .rv-filter-ctrl select:hover { border-color: var(--border-strong); }
    .rv-filter-ctrl input:focus,
    .rv-filter-ctrl select:focus {
        border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); background: var(--surface);
    }
    .rv-filter-actions { display: flex; gap: 8px; align-items: center; height: 34px; }
    @media (max-width: 1400px) {
        .rv-filter-grid { grid-template-columns: repeat(3, 1fr); }
        .rv-filter-field--search { grid-column: span 3; }
    }
    @media (max-width: 900px) {
        .rv-filter-grid { grid-template-columns: repeat(2, 1fr); }
        .rv-filter-field--search { grid-column: span 2; }
    }
    @media (max-width: 767px) {
        .rv-table-scroll {
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
            touch-action: pan-x;
            padding-bottom: 6px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.8) transparent;
        }
        .rv-table-scroll::-webkit-scrollbar {
            height: 8px;
        }
        .rv-table-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.72);
            border-radius: 999px;
        }
        .rv-table {
            min-width: 960px;
            width: max-content;
        }
    }
    @media (max-width: 640px) {
        .rv-filter-grid { grid-template-columns: 1fr; }
        .rv-filter-field--search { grid-column: span 1; }
    }

    /* ---- Table card ---- */
    .rv-table-card {
        background: var(--surface);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius);
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }
    .rv-table-scroll { overflow-x: auto; }
    .rv-table {
        width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1000px;
    }
    .rv-table thead th {
        position: sticky; top: 0; z-index: 2;
        padding: 10px 10px; text-align: left; white-space: nowrap;
        font-size: 10.5px; font-weight: 700; letter-spacing: 0.07em;
        text-transform: uppercase; color: var(--text-3); background: var(--surface-2);
        border-bottom: 1px solid var(--border-subtle); user-select: none;
    }
    .rv-table tbody td {
        padding: 10px 10px; border-bottom: 1px solid var(--border-subtle);
        color: var(--text-2); vertical-align: middle;
    }
    .rv-table tbody tr.rv-row { transition: background 0.12s; }
    .rv-table tbody tr.rv-row:hover { background: var(--surface-2); }
    .rv-table tbody tr.rv-row:last-child td { border-bottom: none; }
    .rv-table tbody tr.rv-row.is-open { background: var(--surface-2); }

    /* Rental ID chip */
    .rv-id {
        display: inline-flex; align-items: baseline; gap: 4px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 12px; font-weight: 600; color: var(--text-1);
        background: var(--surface-3); border: 1px solid var(--border-subtle);
        border-radius: 7px; padding: 3px 9px;
    }
    .rv-id small { font-size: 9.5px; font-weight: 700; letter-spacing: 0.06em; color: var(--text-3); }

    /* Rider cell */
    .rv-rider { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .rv-rider__name { font-weight: 600; color: var(--text-1); font-size: 13px; line-height: 1.25; }
    .rv-rider__email { font-size: 11.5px; color: var(--text-3); line-height: 1.3; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Bicycle cell */
    .rv-bike__name { font-weight: 600; color: var(--text-1); font-size: 13px; }
    .rv-bike__sub { font-size: 11.5px; color: var(--text-3); }

    /* Date/time cell */
    .rv-dt { line-height: 1.35; }
    .rv-dt__date { font-size: 12.5px; color: var(--text-1); font-weight: 500; }
    .rv-dt__time { font-size: 11px; color: var(--text-3); }

    /* Duration chip */
    .rv-chip {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 11.5px; font-weight: 600; color: var(--text-2);
        background: var(--surface-3); border: 1px solid var(--border-subtle);
        border-radius: 999px; padding: 3px 10px; white-space: nowrap;
    }

    /* Fee */
    .rv-fee { font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; font-size: 13.5px; }
    .rv-fee__sub { font-size: 10.5px; color: var(--text-3); font-weight: 500; }

    /* ---- Status pills ---- */
    .rv-status {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 11px; border-radius: 999px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.03em;
        white-space: nowrap; border: 1px solid transparent;
    }
    .rv-status i { font-size: 11px; }
    .rv-status--active { background: var(--success-soft); color: var(--success); border-color: color-mix(in srgb, var(--success) 22%, transparent); }
    .rv-status--completed { background: var(--brand-soft); color: var(--brand-strong); border-color: color-mix(in srgb, var(--brand) 24%, transparent); }
    .rv-status--pending { background: var(--warning-soft); color: var(--warning); border-color: color-mix(in srgb, var(--warning) 24%, transparent); }
    .rv-status--overdue { background: var(--danger-soft); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 24%, transparent); }
    .rv-status--cancelled { background: var(--surface-3); color: var(--text-3); border-color: var(--border-subtle); }
    .rv-status--paid { background: var(--success-soft); color: var(--success); border-color: color-mix(in srgb, var(--success) 22%, transparent); }
    .rv-status--unpaid { background: var(--danger-soft); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 24%, transparent); }
    .rv-status--dot::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
    .rv-status--live::before { animation: rvPulse 1.8s ease-in-out infinite; }
    @keyframes rvPulse {
        0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, currentColor 45%, transparent); opacity: 1; }
        50% { box-shadow: 0 0 0 4px transparent; opacity: 0.65; }
    }

    /* Live tick on the automatic return-grace window the IoT park detection
       opened. Shown under the status badge so the deadline is visible without
       opening the detail row. */
    .rv-grace {
        display: inline-flex; align-items: center; gap: 5px;
        margin-top: 5px; padding: 3px 9px; border-radius: 999px;
        font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
        background: var(--warning-soft); color: var(--warning);
        border: 1px solid color-mix(in srgb, var(--warning) 24%, transparent);
        white-space: nowrap;
    }
    .rv-grace i { font-size: 11px; }
    .rv-grace.is-expired { background: var(--danger-soft); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 24%, transparent); }
    .rv-kv .rv-grace { margin: 0 0 0 6px; vertical-align: middle; }

    /* Payment method */
    .rv-method {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 11.5px; font-weight: 600; padding: 3px 10px;
        border-radius: 999px; white-space: nowrap;
    }
    .rv-method--gcash { background: var(--accent-soft); color: var(--accent); }
    .rv-method--cash { background: var(--success-soft); color: var(--success); }

    /* ---- Action buttons ---- */
    .rv-actions { display: flex; gap: 6px; align-items: center; }
    .rv-btn {
        width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
        border-radius: 8px; border: 1px solid var(--border-subtle); background: transparent;
        color: var(--text-2); font-size: 13.5px; cursor: pointer; padding: 0;
        transition: all 0.15s ease; position: relative;
    }
    .rv-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-card); }
    .rv-btn:active { transform: translateY(0); }
    .rv-btn--view:hover, .rv-btn--view.is-open { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }
    .rv-btn--complete { color: var(--success); border-color: color-mix(in srgb, var(--success) 38%, transparent); }
    .rv-btn--complete:hover { color: #fff; background: var(--success); border-color: var(--success); }
    .rv-btn--cancel { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 34%, transparent); }
    .rv-btn--cancel:hover { color: #fff; background: var(--danger); border-color: var(--danger); }
    .rv-btn--approve { color: var(--brand-strong); border-color: color-mix(in srgb, var(--brand) 40%, transparent); }
    .rv-btn--approve:hover { color: #fff; background: var(--brand); border-color: var(--brand); }
    .rv-btn--paid { color: var(--success); border-color: color-mix(in srgb, var(--success) 38%, transparent); }
    .rv-btn--paid:hover { color: #fff; background: var(--success); border-color: var(--success); }
    .rv-btn[title]::after {
        content: attr(title); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
        background: var(--text-1); color: var(--canvas); padding: 4px 8px; border-radius: 6px;
        font-size: 11px; font-weight: 600; white-space: nowrap; pointer-events: none;
        opacity: 0; transition: opacity 0.15s; z-index: 10;
    }
    .rv-btn:hover[title]::after { opacity: 1; }

    /* Tooltips are always rendered (opacity: 0, not display: none), so the
       centered ::after on last-column buttons overhangs the table's right
       edge (~55px for long labels like "End ride & settle the final fee")
       and forces a permanent horizontal scrollbar. Anchoring last-column
       tooltips to the button's right edge keeps them inside the table. */
    .rv-table td:last-child .rv-btn[title]::after {
        left: auto;
        right: -4px;
        transform: none;
    }

    /* ---- Expandable detail rows ---- */
    tr.rv-detail { display: none; }
    tr.rv-detail.open { display: table-row; }
    .rv-detail__cell { padding: 0 !important; border-bottom: 1px solid var(--border-subtle) !important; }
    .rv-detail__inner {
        display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.32s ease;
    }
    tr.rv-detail.open .rv-detail__inner { grid-template-rows: 1fr; }
    .rv-detail__content { overflow: hidden; min-height: 0; }

    .rv-detail__card {
        margin: 0 14px 14px; border-radius: 12px; overflow: hidden;
        background: var(--surface-2); border: 1px solid var(--border-subtle);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02), var(--shadow-card);
    }
    .rv-detail__head {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
        padding: 13px 16px; border-bottom: 1px solid var(--border-subtle); background: var(--surface-3);
    }
    .rv-detail__title { display: flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 700; color: var(--text-1); }
    .rv-detail__title i { color: var(--brand); font-size: 15px; }
    .rv-detail__meta { font-size: 11.5px; color: var(--text-3); }
    .rv-detail__body { padding: 16px; }
    .rv-detail__grid { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(340px, 1.8fr); gap: 14px; }
    @media (max-width: 1100px) { .rv-detail__grid { grid-template-columns: 1fr; } }

    /* Detail panels */
    .rv-panel {
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;
    }
    .rv-panel__label {
        font-size: 10px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
        color: var(--text-3); display: flex; align-items: center; gap: 7px;
    }
    .rv-panel__label i { color: var(--brand); font-size: 12px; }

    .rv-kv { display: flex; justify-content: space-between; align-items: baseline; gap: 14px; font-size: 12.5px; padding: 5px 0; border-bottom: 1px dashed var(--border-subtle); }
    .rv-kv:last-child { border-bottom: none; }
    .rv-kv dt { color: var(--text-3); flex-shrink: 0; }
    .rv-kv dd { margin: 0; color: var(--text-1); font-weight: 600; text-align: right; }

    /* Participants grid */
    .rv-parties { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    @media (max-width: 640px) { .rv-parties { grid-template-columns: 1fr; } }
    .rv-party {
        display: flex; align-items: center; gap: 10px; padding: 10px 12px;
        background: var(--surface-2); border: 1px solid var(--border-subtle);
        border-radius: 9px; min-width: 0;
    }
    .rv-party i.lead { font-size: 16px; color: var(--brand); flex-shrink: 0; }

    /* Fee tiles */
    .rv-fees { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    @media (max-width: 520px) { .rv-fees { grid-template-columns: 1fr; } }
    .rv-tile {
        background: var(--surface-2); border: 1px solid var(--border-subtle);
        border-radius: 9px; padding: 10px 12px; text-align: center;
    }
    .rv-tile__label { font-size: 9.5px; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--text-3); margin-bottom: 4px; }
    .rv-tile__value { font-size: 14.5px; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; }
    .rv-tile--total { background: var(--brand-soft); border-color: color-mix(in srgb, var(--brand) 30%, transparent); }
    .rv-tile--total .rv-tile__value { color: var(--brand-strong); font-size: 16px; }

    .rv-payline { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .rv-notes {
        margin-top: 2px; padding: 10px 12px; border-radius: 9px; font-size: 12.5px; color: var(--text-2);
        background: var(--warning-soft); border: 1px dashed color-mix(in srgb, var(--warning) 40%, transparent);
    }
    .rv-notes strong { color: var(--warning); font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase; display: block; margin-bottom: 3px; }

    /* ---- Footer ---- */
    .rv-foot {
        display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;
        padding: 12px 18px; border-top: 1px solid var(--border-subtle); background: var(--surface);
        font-size: 12.5px; color: var(--text-3);
    }

    /* ---- Empty state ---- */
    .rv-empty { padding: 48px 20px !important; }

    /* ============================================================
       Mark as Paid confirmation modal
       ============================================================ */
    .rv-paid-modal {
        position: fixed; inset: 0; z-index: 2100;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; visibility: hidden; transition: opacity 0.2s, visibility 0.2s;
    }
    .rv-paid-modal.open { opacity: 1; visibility: visible; }
    .rv-paid-modal__backdrop {
        position: absolute; inset: 0;
        background: rgba(9, 12, 20, 0.6); backdrop-filter: blur(2px);
    }
    .rv-paid-modal__dialog {
        position: relative; z-index: 1; width: 100%; max-width: 420px;
        max-height: 90vh; overflow-y: auto; margin: 16px;
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 14px; box-shadow: var(--shadow-pop);
        transform: translateY(12px) scale(0.97);
        transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .rv-paid-modal.open .rv-paid-modal__dialog { transform: translateY(0) scale(1); }
    .rv-paid-modal__head {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 16px 20px; border-bottom: 1px solid var(--border-subtle);
    }
    .rv-paid-modal__head h3 {
        margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;
    }
    .rv-paid-modal__head h3 i { color: var(--success); font-size: 17px; }
    .rv-paid-modal__close {
        width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
        border-radius: 8px; border: 1px solid var(--border-subtle); background: transparent;
        color: var(--text-2); font-size: 13px; cursor: pointer; transition: all 0.15s;
    }
    .rv-paid-modal__close:hover { background: var(--surface-3); color: var(--text-1); }
    .rv-paid-modal__body { padding: 20px; }
    .rv-paid-modal__list {
        display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    }
    .rv-paid-modal__kv {
        background: var(--surface-2); border: 1px solid var(--border-subtle);
        border-radius: 9px; padding: 10px 12px;
    }
    .rv-paid-modal__kv--wide { grid-column: 1 / -1; }
    .rv-paid-modal__kv dt {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.07em;
        text-transform: uppercase; color: var(--text-3); margin-bottom: 4px;
    }
    .rv-paid-modal__kv dd { margin: 0; font-size: 13px; font-weight: 600; color: var(--text-1); }
    .rv-paid-modal__kv--fee dd { color: var(--success); font-size: 16px; font-weight: 700; }
    .rv-paid-modal__note {
        margin-top: 14px; padding: 10px 12px; border-radius: 9px;
        font-size: 12px; color: var(--text-2);
        background: var(--success-soft); border: 1px dashed color-mix(in srgb, var(--success) 40%, transparent);
    }
    .rv-paid-modal__foot {
        display: flex; align-items: center; justify-content: flex-end; gap: 10px;
        padding: 14px 20px; border-top: 1px solid var(--border-subtle);
    }

</style>
@endsection

@section('page-header')
<div class="admin-pagehead">
    <div class="admin-pagehead__title">
        <h1>Active Rentals</h1>
        <p>Track ongoing rides, payments and settlements across all bicycles</p>
    </div>
</div>
@endsection

@section('content')
<script>
    // Opts this page into the admin live sync (rental-live.js, loaded after
    // this markup): version feed URL + region swaps + row re-binding below.
    window.PedalyaRentalLivePage = {
        page: 'rentals',
        feedUrl: @json(route('admin.rentals.feed')),
    };
</script>
<div style="display:flex;flex-direction:column;gap:0;">

    {{-- ===== Filter bar ===== --}}
    <div class="rv-filter-card">
        <div class="rv-filter-head"><i class="bi bi-funnel"></i>Refine Rentals</div>
        <form method="GET" action="{{ route('admin.rentals.index') }}" class="rv-filter-grid">
            <div class="rv-filter-field rv-filter-field--search">
                <label for="rvSearch">Search</label>
                <div class="rv-filter-ctrl has-icon no-caret">
                    <i class="bi bi-search"></i>
                    <input type="text" id="rvSearch" data-table-search placeholder="Rider, bicycle, ID...">
                </div>
            </div>
            <div class="rv-filter-field">
                <label for="rvStatus">Status</label>
                <div class="rv-filter-ctrl">
                    <select name="status" id="rvStatus">
                        <option value="">All Active</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="overdue" {{ request('status') === 'overdue' ? 'selected' : '' }}>Overdue</option>
                        <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>Expired</option>
                    </select>
                </div>
            </div>
            <div class="rv-filter-field">
                <label for="rvFrom">Start Date</label>
                <div class="rv-filter-ctrl no-caret">
                    <input type="date" name="date_from" id="rvFrom" value="{{ request('date_from') }}">
                </div>
            </div>
            <div class="rv-filter-field">
                <label for="rvTo">End Date</label>
                <div class="rv-filter-ctrl no-caret">
                    <input type="date" name="date_to" id="rvTo" value="{{ request('date_to') }}">
                </div>
            </div>
            <div class="rv-filter-field">
                <label for="rvBike">Bicycle</label>
                <div class="rv-filter-ctrl">
                    <select name="bicycle_id" id="rvBike">
                        <option value="">All Bicycles</option>
                        @foreach($bicyclesList ?? [] as $b)
                            {{-- Keyed by the unique serial number so the same bicycle is
                                 identifiable in both modules. --}}
                            <option value="{{ $b->id }}" {{ request('bicycle_id') == $b->id ? 'selected' : '' }}>{{ $b->serialNumber }} - {{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="rv-filter-field">
                <label for="rvRider">Rider</label>
                <div class="rv-filter-ctrl">
                    <select name="rider_id" id="rvRider">
                        <option value="">All Riders</option>
                        @foreach($ridersList ?? [] as $r)
                            <option value="{{ $r->id }}" {{ request('rider_id') == $r->id ? 'selected' : '' }}>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="rv-filter-actions">
                <button type="submit" class="btn-admin btn-admin--primary btn-admin--sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <a href="{{ route('admin.rentals.index') }}" class="btn-admin btn-admin--ghost btn-admin--sm" title="Clear all filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>

    {{-- ===== Rentals table ===== --}}
    <div class="rv-table-card">
        <div class="rv-table-scroll">
            <table class="rv-table" id="rvTable">
                <thead>
                    <tr>
                        <th>Rental ID</th>
                        <th>Rider</th>
                        <th>Bicycle</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Duration</th>
                        <th>Fee</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rentals as $rental)
                        @php
                            $durH = $rental->durationMinutes ? floor($rental->durationMinutes / 60) : null;
                            $durM = $rental->durationMinutes % 60;
                            $durLabel = $rental->durationMinutes ? ($durH ? $durH . 'h ' . $durM . 'm' : $durM . 'm') : null;
                        @endphp
                        <tr class="rv-row">
                            <td><span class="rv-id"><small>REN</small>#{{ $rental->id }}</span></td>
                            <td>
                                <div class="rv-rider">
                                    <div class="user-avatar" style="width:30px;height:30px;font-size:11px;">
                                        {{ strtoupper(substr($rental->rider->name ?? 'U', 0, 1)) }}
                                    </div>
                                    <div style="min-width:0;">
                                        <div class="rv-rider__name">{{ $rental->rider->name ?? 'Unknown' }}</div>
                                        <div class="rv-rider__email">{{ $rental->rider->email ?? '' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                {{-- Live bicycle data via the bicycleId relation, with the
                                     snapshot columns as the fallback for deleted bicycles.
                                     The link is the serial-keyed jump into Inventory. --}}
                                @if($rental->bicycle)
                                    <a href="{{ route('admin.bicycles.show', $rental->bicycle->id) }}"
                                       class="text-decoration-none" title="View {{ $rental->bicycle->serialNumber }} in Bicycle Inventory">
                                        <div class="rv-bike__name">{{ $rental->bicycle->name }}</div>
                                        @if($rental->bicycle->serialNumber)
                                            <div class="rv-bike__sub">SN &middot; {{ $rental->bicycle->serialNumber }}</div>
                                        @endif
                                    </a>
                                @else
                                    <div class="rv-bike__name">{{ $rental->bicycleName ?? 'Unknown' }}</div>
                                    @if($rental->bicycleSerial)
                                        <div class="rv-bike__sub">SN &middot; {{ $rental->bicycleSerial }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <div class="rv-dt">
                                    <div class="rv-dt__date">{{ $rental->startTime?->format('M d, Y') ?? '—' }}</div>
                                    <div class="rv-dt__time">{{ $rental->startTime?->format('h:i A') ?? 'Not started' }}</div>
                                </div>
                            </td>
                            <td>
                                <div class="rv-dt">
                                    <div class="rv-dt__date">{{ $rental->endTime?->format('M d, Y') ?? '—' }}</div>
                                    <div class="rv-dt__time">{{ $rental->endTime?->format('h:i A') ?? 'In progress' }}</div>
                                </div>
                            </td>
                            <td>
                                @if($durLabel)
                                    <span class="rv-chip"><i class="bi bi-hourglass-split"></i>{{ $durLabel }}</span>
                                @else
                                    <span style="color:var(--text-3);">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                <div class="rv-fee">&#8369;{{ number_format($rental->totalFee ?? 0, 2) }}</div>
                                <div class="rv-fee__sub">&#8369;{{ number_format($rental->ratePerHour ?? 0, 2) }}/hr</div>
                            </td>
                            <td>
                                @if($rental->paymentMethod === 'gcash')
                                    <span class="rv-method rv-method--gcash"><i class="bi bi-phone"></i>GCash</span>
                                @else
                                    <span class="rv-method rv-method--cash"><i class="bi bi-cash-stack"></i>Cash</span>
                                @endif
                            </td>
                            <td>
                                @if($rental->status === 'active')
                                    <span class="rv-status rv-status--active rv-status--dot rv-status--live">Active</span>
                                @elseif($rental->status === 'completed')
                                    <span class="rv-status rv-status--completed rv-status--dot">Completed</span>
                                @elseif($rental->status === 'pending')
                                    <span class="rv-status rv-status--pending rv-status--dot">Pending</span>
                                @elseif($rental->status === 'overdue')
                                    <span class="rv-status rv-status--overdue rv-status--dot rv-status--live">Overdue</span>
                                @elseif($rental->status === 'expired')
                                    <span class="rv-status rv-status--overdue rv-status--dot rv-status--live">Expired</span>
                                @elseif($rental->status === 'cancelled')
                                    <span class="rv-status rv-status--cancelled rv-status--dot">Cancelled</span>
                                @else
                                    <span class="rv-status rv-status--cancelled rv-status--dot">{{ ucfirst($rental->status) }}</span>
                                @endif
                                @if($rental->hasRideExtensions())
                                    <div class="rv-ext"
                                         style="margin-top:3px;font-size:10.5px;font-weight:600;color:#1976D2;"
                                         title="Ride extended {{ $rental->extensionCount }} time(s) &middot; {{ $rental->extensionMinutesLabel() }} extra &middot; extension fee &#8369;{{ number_format((float) $rental->extensionFee, 2) }} &middot; last extended {{ $rental->lastExtendedAt?->format('M d, Y g:i A') ?? '—' }}">
                                        <i class="bi bi-plus-circle"></i>Extended &times;{{ $rental->extensionCount }} ({{ $rental->extensionMinutesLabel() }})
                                    </div>
                                @endif
                                @if($rental->returnDeadline && in_array($rental->status, ['awaiting_return', 'overdue_return'], true))
                                    <div class="rv-grace"
                                         data-return-countdown="{{ $rental->returnDeadline->toIso8601String() }}"
                                         title="Return deadline {{ $rental->returnDeadline->format('M d, Y g:i A') }} — set by {{ $rental->returnDeadlineSetBy ?: 'IoT park detection' }}">
                                        <i class="bi bi-hourglass-split"></i><span data-return-countdown-text>&mdash;</span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($rental->paymentStatus === 'paid')
                                    <span class="rv-status rv-status--paid"><i class="bi bi-check-circle-fill"></i>Paid</span>
                                @elseif($rental->paymentStatus === 'pending')
                                    <span class="rv-status rv-status--pending rv-status--dot">Pending</span>
                                @else
                                    <span class="rv-status rv-status--unpaid rv-status--dot">{{ ucfirst($rental->paymentStatus ?? 'unpaid') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="rv-actions">
                                    @php
                                        $detailPenalty = '₱20 per completed 5-minute block after the return deadline';
                                        $detailData = [
                                            'id'        => $rental->rentalId ?? ('REN-' . str_pad($rental->id, 4, '0', STR_PAD_LEFT)),
                                            'status'    => $rental->status,
                                            'rider'     => $rental->rider->name ?? $rental->riderName ?? 'Unknown',
                                            'email'     => $rental->rider->email ?? $rental->riderEmail ?? '',
                                            'bike'      => $rental->bicycle->name ?? $rental->bicycleName ?? 'Unknown',
                                            'serial'    => $rental->bicycle->serialNumber ?? $rental->bicycleSerial ?? '',
                                            'bikeRate'  => number_format((float) ($rental->bicycle->hourlyRate ?? $rental->ratePerHour ?? 0), 2),
                                            'start'     => $rental->startTime?->format('M d, Y h:i A') ?? '',
                                            'submitted' => $rental->returnRequestedAt?->format('M d, Y h:i A') ?? '',
                                            'deadline'  => $rental->returnDeadline?->format('M d, Y h:i A') ?? '',
                                            'deadlineBy'=> $rental->returnDeadlineSetBy ?? '',
                                            'expected'  => $rental->expectedEndTime?->format('M d, Y h:i A') ?? '',
                                            'expiredAt' => $rental->expiredAt?->format('M d, Y h:i A') ?? '',
                                            'overdueAt' => $rental->overdueReturnAt?->format('M d, Y h:i A') ?? '',
                                            'penalty'   => $detailPenalty,
                                            'penaltyAccrued' => $rental->penaltyAccrued !== null ? number_format((float) $rental->penaltyAccrued, 2) : '',
                                            'duration'  => $durLabel ?? '',
                                            'created'   => $rental->created_at?->format('M d, Y h:i A') ?? '',
                                            'rate'      => number_format((float) ($rental->ratePerHour ?? 0), 2),
                                            'totalFee'  => number_format((float) ($rental->totalFee ?? 0), 2),
                                            'hasExt'    => (bool) $rental->hasRideExtensions(),
                                            'extText'   => $rental->hasRideExtensions()
                                                ? $rental->extensionCount . '× (' . $rental->extensionMinutesLabel() . ' extra) · extension fee ₱' . number_format((float) $rental->extensionFee, 2) : '',
                                            'extLast'   => $rental->lastExtendedAt?->format('M d, Y h:i A') ?? '',
                                            'notes'     => $rental->notes ?? '',
                                            'method'    => $rental->paymentMethod === 'gcash' ? 'GCash' : 'Cash',
                                            'payStatus' => ucfirst($rental->paymentStatus ?? 'unpaid'),
                                        ];
                                    @endphp
                                    <button type="button" class="rv-btn rv-btn--view"
                                            data-rental-detail="{{ json_encode($detailData) }}"
                                            title="View details" aria-label="View rental details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    @if(in_array($rental->status, ['active', 'overdue'])
                                        && $rental->paymentStatus === 'pending'
                                        && $rental->paymentMethod !== 'gcash')
                                        <button type="button"
                                                class="rv-btn rv-btn--paid"
                                                data-pay-action="{{ route('admin.rentals.mark-paid', $rental->id) }}"
                                                data-pay-rental-id="{{ $rental->rentalId }}"
                                                data-pay-rider="{{ $rental->rider->name ?? $rental->riderName ?? 'Unknown' }}"
                                                data-pay-bike="{{ $rental->bicycle->name ?? $rental->bicycleName ?? 'Unknown' }}"
                                                data-pay-fee="{{ number_format((float) ($rental->totalFee ?? 0), 2) }}"
                                                data-pay-method="Cash"
                                                title="Mark as paid">
                                            <i class="bi bi-cash-coin"></i>
                                        </button>
                                    @endif
                                    @if($rental->status === 'pending' && $rental->paymentMethod === 'gcash')
                                        <form action="{{ route('admin.rentals.verify-gcash', $rental->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="rv-btn rv-btn--approve"
                                                    data-confirm="Verify this GCash payment and approve the rental?"
                                                    title="Verify &amp; approve">
                                                <i class="bi bi-patch-check"></i>
                                            </button>
                                        </form>
                                    @elseif($rental->status === 'pending')
                                        <form action="{{ route('admin.rentals.approve', $rental->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="rv-btn rv-btn--approve"
                                                    data-confirm="Approve this pending rental?"
                                                    title="Approve">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    @endif
                                    {{-- End Ride settles in one step: it closes the
                                         rental, records the fees, and releases the
                                         bicycle. The opener carries only the rental
                                         it acts on; the shared dialog further down
                                         collects the inspected condition. --}}
                                    @if(in_array($rental->status, ['active', 'overdue', 'expired'], true))
                                        <button type="button" class="rv-btn rv-btn--complete"
                                                data-end-ride
                                                data-rental-id="{{ $rental->id }}"
                                                data-rental-code="{{ $rental->rentalId }}"
                                                data-bike="{{ $rental->bicycle->serialNumber ?? $rental->bicycleSerial ?? '' }}{{ isset($rental->bicycle->serialNumber) ? ' - ' . ($rental->bicycle->name ?? '') : '' }}"
                                                data-rider="{{ $rental->rider->name ?? $rental->riderName ?? 'Unknown' }}"
                                                title="End ride &amp; settle the final fee">
                                            <i class="bi bi-stop-circle"></i>
                                        </button>
                                    @endif
                                    @if(in_array($rental->status, ['active', 'pending', 'overdue']))
                                        <form action="{{ route('admin.rentals.cancel', $rental->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="reason" value="Cancelled by administrator">
                                            <button type="submit" class="rv-btn rv-btn--cancel"
                                                    data-confirm="Are you sure you want to cancel this rental?"
                                                    title="Cancel">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="11" class="rv-empty">
                                <x-admin.empty-state icon="bi-key" title="No rentals found" message="Adjust your filters or try a different search."/>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rv-foot" id="rvFoot">
            <span>
                {{-- $rentals->total() is the filtered page count; when filters
                     narrow the queue it is qualified by the unfiltered total so
                     this line still reconciles with the sidebar badge. --}}
                <i class="bi bi-list-ul me-1"></i>Showing {{ $rentals->total() }} record{{ $rentals->total() === 1 ? '' : 's' }}@if($rentals->total() !== $activeRentalCount) of {{ $activeRentalCount }} active rental{{ $activeRentalCount === 1 ? '' : 's' }}@endif
            </span>
            @if(method_exists($rentals, 'links'))
                {{ $rentals->withQueryString()->links() }}
            @endif
        </div>
    </div>
</div>

{{-- ===== Rental details modal (floating, mirrors the Customer List review modal) ===== --}}
<div class="admin-modal" id="rentalDetailModal">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog admin-modal__dialog--lg" role="dialog" aria-modal="true" aria-label="Rental details">
        <div class="admin-modal__head vr-review-head">
            <span class="vr-header-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
            <div class="vr-review-head__text">
                <h3 class="vr-review-head__title">Rental Details</h3>
                <p class="vr-review-head__subtitle" id="rdSub">—</p>
            </div>
            <span id="rdStatus"></span>
            <button type="button" class="vr-review-head__close" data-modal-close aria-label="Close" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="admin-modal__body">
            <div class="rv-parties" style="margin-bottom:14px;">
                <div class="rv-party">
                    <div class="user-avatar" id="rdAvatar" style="width:32px;height:32px;font-size:12px;">U</div>
                    <div style="min-width:0;">
                        <div class="rv-rider__name" id="rdRider">—</div>
                        <div class="rv-rider__email" id="rdEmail">—</div>
                    </div>
                </div>
                <div class="rv-party">
                    <i class="bi bi-bicycle lead"></i>
                    <div style="min-width:0;">
                        <div class="rv-rider__name" id="rdBike">—</div>
                        <div class="rv-rider__email" id="rdBikeSub">—</div>
                    </div>
                </div>
            </div>
            <div class="rv-detail__grid">
                <div class="rv-panel">
                    <div class="rv-panel__label"><i class="bi bi-route"></i>Rental Timeline</div>
                    <dl style="margin:0;">
                        <div class="rv-kv"><dt>Rental ID</dt><dd id="rdId">—</dd></div>
                        <div class="rv-kv"><dt>Start Time</dt><dd id="rdStart">—</dd></div>
                        <div class="rv-kv" id="rowRdSubmittedAt"><dt>Return Submitted At</dt><dd id="rdSubmittedAt">—</dd></div>
                        <div class="rv-kv" id="rowRdDeadline"><dt>Return Deadline</dt><dd id="rdDeadline">—</dd></div>
                        <div class="rv-kv" id="rowRdDeadlineBy"><dt>Deadline Set By</dt><dd id="rdDeadlineBy">—</dd></div>
                        <div class="rv-kv" id="rowRdExpected"><dt>Expected End</dt><dd id="rdExpected">—</dd></div>
                        <div class="rv-kv" id="rowRdExpired"><dt>Expired At</dt><dd id="rdExpired">—</dd></div>
                        <div class="rv-kv" id="rowRdOverdue"><dt>Overdue Return At</dt><dd id="rdOverdueAt">—</dd></div>
                        <div class="rv-kv" id="rowRdPenalty"><dt>Penalty Rule</dt><dd id="rdPenalty">—</dd></div>
                        <div class="rv-kv" id="rowRdPenaltyAcc"><dt>Penalty Accrued</dt><dd id="rdPenaltyAcc">—</dd></div>
                        <div class="rv-kv"><dt>Duration</dt><dd id="rdDuration">—</dd></div>
                        <div class="rv-kv" id="rowRdExt"><dt>Ride Extended</dt><dd><span id="rdExt"></span><br><small class="text-muted" id="rdExtLast"></small></dd></div>
                        <div class="rv-kv"><dt>Created</dt><dd id="rdCreated">—</dd></div>
                    </dl>
                    <div class="rv-notes" id="rdNotesWrap" style="display:none;"><strong><i class="bi bi-sticky me-1"></i>Notes</strong><span id="rdNotes"></span></div>
                </div>
                <div class="rv-panel">
                    <div class="rv-panel__label"><i class="bi bi-cash-coin"></i>Fees &amp; Payment</div>
                    <div class="rv-fees">
                        <div class="rv-tile">
                            <div class="rv-tile__label">Hourly Rate</div>
                            <div class="rv-tile__value" id="rdRate">—</div>
                        </div>
                        <div class="rv-tile">
                            <div class="rv-tile__label">Duration</div>
                            <div class="rv-tile__value" id="rdDurationTile">—</div>
                        </div>
                        <div class="rv-tile rv-tile--total">
                            <div class="rv-tile__label">Current Fee</div>
                            <div class="rv-tile__value" id="rdTotal">—</div>
                        </div>
                    </div>
                    <div class="rv-payline">
                        <span class="rv-method" id="rdMethod">—</span>
                        <span class="rv-status" id="rdPayStatus">—</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="admin-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- ===== Shared End Ride dialog =====
     Rendered once for the whole page so no per-row dialog markup is needed.
     __RENTAL_ID__ is swapped for the clicked rental by admin.js. --}}
<div class="admin-modal" id="endRideModal">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-label="End ride and settle">
        <form action="{{ route('admin.rentals.end-ride', '__RENTAL_ID__') }}" method="POST" id="endRideForm" data-end-ride-form>
            @csrf
            @method('PUT')
            <div class="admin-modal__head">
                <h3>End Ride <span id="endRideCode" class="cell-key"></span></h3>
                <button type="button" class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="admin-modal__body">
                <div class="admin-form">
                    <p class="text-muted">
                        Ending the ride for <strong id="endRideBike"></strong><br>
                        Rider: <strong id="endRideRider"></strong>
                    </p>
                    <p class="form-hint">
                        This closes the rental, records the fees owed, and sets the bicycle back to
                        Available (or Maintenance if it came back damaged). The rental moves to history.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="endRideCondition">Condition on return</label>
                        <select class="form-select" id="endRideCondition" name="condition" required>
                            <option value="good">Good &mdash; bicycle returns to Available</option>
                            <option value="fair">Fair &mdash; bicycle returns to Available</option>
                            <option value="damaged">Damaged &mdash; routes to Maintenance</option>
                            <option value="needs_maintenance">Needs maintenance &mdash; routes to Maintenance</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="endRideNote">Note (optional)</label>
                        <textarea class="form-control" id="endRideNote" name="note" rows="2" maxlength="1000"
                                  placeholder="Anything the rider reported on return"></textarea>
                    </div>
                </div>
            </div>
            <div class="admin-modal__foot">
                <button type="button" class="btn-admin btn-admin--secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn-admin btn-admin--warning">End Ride &amp; Settle</button>
            </div>
        </form>
    </div>
</div>

{{-- ===== Mark as Paid confirmation modal ===== --}}
<div class="rv-paid-modal" id="rvPaidModal">
    <div class="rv-paid-modal__backdrop" data-rv-paid-close></div>
    <div class="rv-paid-modal__dialog" role="dialog" aria-modal="true" aria-label="Mark rental as paid">
        <div class="rv-paid-modal__head">
            <h3><i class="bi bi-cash-coin"></i>Mark as Paid</h3>
            <button type="button" class="rv-paid-modal__close" data-rv-paid-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="rv-paid-modal__body">
            <p style="margin:0 0 14px;font-size:12.5px;color:var(--text-2);">
                Please review the payment details before confirming.
            </p>
            <dl class="rv-paid-modal__list">
                <div class="rv-paid-modal__kv rv-paid-modal__kv--wide">
                    <dt>Rental ID</dt>
                    <dd id="rvPaidRentalId">&mdash;</dd>
                </div>
                <div class="rv-paid-modal__kv">
                    <dt>Rider</dt>
                    <dd id="rvPaidRider">&mdash;</dd>
                </div>
                <div class="rv-paid-modal__kv">
                    <dt>Bicycle</dt>
                    <dd id="rvPaidBike">&mdash;</dd>
                </div>
                <div class="rv-paid-modal__kv rv-paid-modal__kv--fee">
                    <dt>Total Fee</dt>
                    <dd id="rvPaidFee">&mdash;</dd>
                </div>
                <div class="rv-paid-modal__kv">
                    <dt>Payment Method</dt>
                    <dd id="rvPaidMethod">&mdash;</dd>
                </div>
            </dl>
            <div class="rv-paid-modal__note">
                <i class="bi bi-info-circle me-1"></i>Confirming will mark this rental (and its payment record) as paid immediately.
            </div>
        </div>
        <div class="rv-paid-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-rv-paid-close>Cancel</button>
            <form id="rvPaidForm" action="#" method="POST" style="margin:0;">
                @csrf
                @method('PUT')
                <button type="submit" class="btn-admin btn-admin--success btn-admin--sm">
                    <i class="bi bi-check-circle me-1"></i>Confirm Payment
                </button>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
(function () {
    var tbody = document.querySelector('#rvTable tbody');
    var modal = document.getElementById('rentalDetailModal');
    if (!tbody || !modal) return;

    /* --- Floating rental-details modal (mirrors the Customer List review
           modal: centered over a dark backdrop, table layout untouched) --- */
    function setText(id, value) {
        var node = document.getElementById(id);
        if (node) node.textContent = value || '\u2014';
    }

    function setRowVisible(rowId, visible) {
        var row = document.getElementById(rowId);
        if (row) row.style.display = visible ? '' : 'none';
    }

    function statusPill(status) {
        var map = {
            active: ['rv-status--active rv-status--dot rv-status--live', 'Active'],
            pending: ['rv-status--pending rv-status--dot', 'Pending'],
            overdue: ['rv-status--overdue rv-status--dot rv-status--live', 'Overdue'],
            expired: ['rv-status--overdue rv-status--dot rv-status--live', 'Expired'],
            completed: ['rv-status--completed rv-status--dot', 'Completed'],
            cancelled: ['rv-status--cancelled rv-status--dot', 'Cancelled']
        };
        var key = String(status || '').toLowerCase();
        var entry = map[key] || ['rv-status--cancelled rv-status--dot', status ? status.charAt(0).toUpperCase() + status.slice(1) : '\u2014'];
        var node = document.getElementById('rdStatus');
        if (node) node.className = 'rv-status ' + entry[0];
        if (node) node.textContent = entry[1];
    }

    function openDetail(d) {
        setText('rdSub', d.id);
        statusPill(d.status);
        var avatar = document.getElementById('rdAvatar');
        if (avatar) avatar.textContent = String(d.rider || 'U').trim().charAt(0).toUpperCase() || 'U';
        setText('rdRider', d.rider);
        setText('rdEmail', d.email);
        setText('rdBike', d.bike);
        setText('rdBikeSub', (d.serial ? 'SN \u00B7 ' + d.serial : '') + (d.bikeRate ? ' \u00B7 \u20B1' + d.bikeRate + '/hour' : ''));
        setText('rdId', d.id);
        setText('rdStart', d.start);
        setText('rdSubmittedAt', d.submitted);
        setText('rdDeadline', d.deadline);
        setText('rdDeadlineBy', d.deadlineBy);
        setText('rdExpected', d.expected);
        setText('rdExpired', d.expiredAt);
        setText('rdOverdueAt', d.overdueAt);
        setText('rdPenalty', d.penalty);
        setText('rdPenaltyAcc', d.penaltyAccrued ? '\u20B1' + d.penaltyAccrued : '');
        setText('rdDuration', d.duration);
        setText('rdDurationTile', d.duration);
        setText('rdCreated', d.created);
        setText('rdRate', d.rate ? '\u20B1' + d.rate : '');
        setText('rdTotal', d.totalFee ? '\u20B1' + d.totalFee : '');
        setRowVisible('rowRdSubmittedAt', !!d.submitted);
        setRowVisible('rowRdDeadline', !!d.deadline);
        setRowVisible('rowRdDeadlineBy', !!d.deadlineBy);
        setRowVisible('rowRdExpected', !!d.expected);
        setRowVisible('rowRdExpired', !!d.expiredAt);
        setRowVisible('rowRdOverdue', !!d.overdueAt);
        setRowVisible('rowRdPenalty', !!d.penalty);
        setRowVisible('rowRdPenaltyAcc', !!d.penaltyAccrued);
        setRowVisible('rowRdExt', !!d.hasExt);
        if (d.hasExt) {
            setText('rdExt', d.extText);
            setText('rdExtLast', d.extLast ? 'Last extended ' + d.extLast : '');
        }
        var noteWrap = document.getElementById('rdNotesWrap');
        if (noteWrap) {
            noteWrap.style.display = d.notes ? '' : 'none';
            setText('rdNotes', d.notes);
        }
        var method = document.getElementById('rdMethod');
        if (method) {
            method.textContent = d.method || '\u2014';
            method.className = 'rv-method ' + (d.method === 'GCash' ? 'rv-method--gcash' : 'rv-method--cash');
        }
        var pay = document.getElementById('rdPayStatus');
        if (pay) {
            var st = String(d.payStatus || '').toLowerCase();
            pay.textContent = d.payStatus || '\u2014';
            pay.className = 'rv-status ' + (st === 'paid' ? 'rv-status--paid' : 'rv-status--unpaid') + (st === 'paid' ? '' : ' rv-status--dot');
            if (st === 'paid') {
                pay.innerHTML = '<i class="bi bi-check-circle-fill"></i>' + (d.payStatus || 'Paid');
            }
        }
        modal.classList.add('open');
    }

    function closeDetail() { modal.classList.remove('open'); }

    /* Idempotent: the live sync re-calls this after swapping tbody HTML so
       freshly rendered rows open the modal too, without double-binding. */
    function bindDetailModal() {
        document.querySelectorAll('[data-rental-detail]').forEach(function (btn) {
            if (btn.dataset.liveBound === '1') return;
            btn.dataset.liveBound = '1';
            btn.addEventListener('click', function () {
                try { openDetail(JSON.parse(btn.getAttribute('data-rental-detail'))); } catch (e) { /* ignore */ }
            });
        });
    }
    bindDetailModal();
    window.__rentalsBindDetails = bindDetailModal;

    modal.querySelectorAll('[data-modal-close]').forEach(function (node) {
        node.addEventListener('click', closeDetail);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeDetail();
    });

    /* --- Client-side search (table rows only; modal never alters layout) --- */
    var searchInput = document.querySelector('[data-table-search]');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = searchInput.value.toLowerCase().trim();
            tbody.querySelectorAll('tr.rv-row').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();

/* ==================== Mark as Paid confirmation modal ==================== */
(function () {
    var modal = document.getElementById('rvPaidModal');
    if (!modal) return;

    var form = document.getElementById('rvPaidForm');
    var setId = document.getElementById('rvPaidRentalId');
    var setRider = document.getElementById('rvPaidRider');
    var setBike = document.getElementById('rvPaidBike');
    var setFee = document.getElementById('rvPaidFee');
    var setMethod = document.getElementById('rvPaidMethod');

    function openModal(btn) {
        if (form) form.action = btn.getAttribute('data-pay-action') || '#';
        if (setId) setId.textContent = btn.getAttribute('data-pay-rental-id') || '—';
        if (setRider) setRider.textContent = btn.getAttribute('data-pay-rider') || '—';
        if (setBike) setBike.textContent = btn.getAttribute('data-pay-bike') || '—';
        if (setFee) setFee.textContent = '₱' + (btn.getAttribute('data-pay-fee') || '0.00');
        if (setMethod) setMethod.textContent = btn.getAttribute('data-pay-method') || '—';
        modal.classList.add('open');
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    function bindPayButtons() {
        document.querySelectorAll('[data-pay-action]').forEach(function (btn) {
            if (btn.dataset.liveBound === '1') return;
            btn.dataset.liveBound = '1';
            btn.addEventListener('click', function () { openModal(btn); });
        });
    }
    bindPayButtons();
    window.__rentalsBindPay = bindPayButtons;

    modal.querySelectorAll('[data-rv-paid-close]').forEach(function (node) {
        node.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
    });
})();

/* ==================== Automatic return-grace countdown ==================== */
/* Single shared 1s timer, re-querying rows on every tick so swapped-in rows
   count down too. Restarted (never duplicated) by the live-sync hook. */
function initRvCountdowns() {
    if (window.__rvCountdownTimer) {
        clearInterval(window.__rvCountdownTimer);
        window.__rvCountdownTimer = 0;
    }

    function pad(n) { return String(n).padStart(2, '0'); }

    function render() {
        var now = Date.now();
        var nodes = document.querySelectorAll('[data-return-countdown]');
        nodes.forEach(function (node) {
            var target = new Date(node.getAttribute('data-return-countdown'));
            var out = node.querySelector('[data-return-countdown-text]');
            if (!out || isNaN(target.getTime())) return;

            var remaining = Math.max(0, Math.floor((target.getTime() - now) / 1000));
            var h = Math.floor(remaining / 3600);
            var m = Math.floor((remaining % 3600) / 60);
            var s = remaining % 60;

            out.textContent = h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
            node.classList.toggle('is-expired', remaining === 0);
        });
    }

    render();
    window.__rvCountdownTimer = setInterval(render, 1000);
}
initRvCountdowns();

/* Live-sync hook (rental-live.js): re-bind row buttons + countdowns after
   the table regions are swapped with fresh server HTML. */
window.PedalyaRentalsLiveRefresh = function () {
    ['__rentalsBindDetails', '__rentalsBindPay'].forEach(function (key) {
        if (typeof window[key] === 'function') {
            try { window[key](); } catch (e) { /* keep old rows working */ }
        }
    });
    initRvCountdowns();
    if (window.PedalyaRowMenuInit) {
        try { window.PedalyaRowMenuInit(document); } catch (e) { /* guarded internally */ }
    }
};

</script>
@endsection
