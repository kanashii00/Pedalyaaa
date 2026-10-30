@extends('layouts.admin')

@section('title', 'Rental History')

@section('styles')
<style>
    /* ============================================================
       Rental History — Read-only archive view
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
        width: 100%; border-collapse: collapse; font-size: 13px; min-width: 960px;
    }
    .rv-table thead th {
        position: sticky; top: 0; z-index: 2;
        padding: 11px 16px; text-align: left; white-space: nowrap;
        font-size: 10.5px; font-weight: 700; letter-spacing: 0.07em;
        text-transform: uppercase; color: var(--text-3); background: var(--surface-2);
        border-bottom: 1px solid var(--border-subtle); user-select: none;
    }
    .rv-table tbody td {
        padding: 12px 16px; border-bottom: 1px solid var(--border-subtle);
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
    .rv-rider { display: flex; align-items: center; gap: 10px; min-width: 160px; }
    .rv-rider__name { font-weight: 600; color: var(--text-1); font-size: 13px; line-height: 1.25; }
    .rv-rider__email { font-size: 11.5px; color: var(--text-3); line-height: 1.3; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

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
    .rv-status--returned { background: var(--accent-soft); color: var(--accent); border-color: color-mix(in srgb, var(--accent) 22%, transparent); }
    .rv-status--expired { background: var(--danger-soft); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 24%, transparent); }
    .rv-status--paid { background: var(--success-soft); color: var(--success); border-color: color-mix(in srgb, var(--success) 22%, transparent); }
    .rv-status--unpaid { background: var(--danger-soft); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 24%, transparent); }
    .rv-status--dot::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

    /* Payment method */
    .rv-method {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 11.5px; font-weight: 600; padding: 3px 10px;
        border-radius: 999px; white-space: nowrap;
    }
    .rv-method--gcash { background: var(--accent-soft); color: var(--accent); }
    .rv-method--cash { background: var(--success-soft); color: var(--success); }

    /* ---- Action buttons (view + print only) ---- */
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
    .rv-btn--print { color: var(--text-3); border-color: var(--border-subtle); }
    .rv-btn--print:hover { color: var(--brand); border-color: var(--brand); background: var(--brand-soft); }
    .rv-btn[title]::after {
        content: attr(title); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%);
        background: var(--text-1); color: var(--canvas); padding: 4px 8px; border-radius: 6px;
        font-size: 11px; font-weight: 600; white-space: nowrap; pointer-events: none;
        opacity: 0; transition: opacity 0.15s; z-index: 10;
    }
    .rv-btn:hover[title]::after { opacity: 1; }

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
       Receipt preview modal
       ============================================================ */
    .rcpt-modal {
        position: fixed; inset: 0; z-index: 2000;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; visibility: hidden; transition: opacity 0.2s, visibility 0.2s;
    }
    .rcpt-modal.open { opacity: 1; visibility: visible; }
    .rcpt-modal__backdrop {
        position: absolute; inset: 0;
        background: rgba(9, 12, 20, 0.6); backdrop-filter: blur(2px);
    }
    .rcpt-modal__dialog {
        position: relative; z-index: 1; width: 100%; max-width: 460px;
        max-height: 90vh; overflow-y: auto; margin: 16px;
        background: var(--surface); border: 1px solid var(--border-subtle);
        border-radius: 14px; box-shadow: var(--shadow-pop);
        transform: translateY(12px) scale(0.97);
        transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .rcpt-modal.open .rcpt-modal__dialog { transform: translateY(0) scale(1); }
    .rcpt-modal__head {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 16px 20px; border-bottom: 1px solid var(--border-subtle);
    }
    .rcpt-modal__head h3 {
        margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;
    }
    .rcpt-modal__head h3 i { color: var(--brand); font-size: 17px; }
    .rcpt-modal__close {
        width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
        border-radius: 8px; border: 1px solid var(--border-subtle); background: transparent;
        color: var(--text-2); font-size: 13px; cursor: pointer; transition: all 0.15s;
    }
    .rcpt-modal__close:hover { background: var(--surface-3); color: var(--text-1); }
    .rcpt-modal__body { padding: 20px; }
    .rcpt-modal__foot {
        display: flex; align-items: center; justify-content: flex-end; gap: 10px;
        padding: 14px 20px; border-top: 1px solid var(--border-subtle);
    }

    /* ---- Receipt sheet (white paper) ---- */
    .rcpt-sheet {
        background: #ffffff; color: #111827; border-radius: 10px;
        padding: 22px 22px 16px; font-size: 13px; line-height: 1.45;
        box-shadow: inset 0 0 0 1px rgba(17, 24, 39, 0.06);
    }
    .rcpt-brandrow {
        display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;
        padding-bottom: 12px; border-bottom: 3px solid #14532d;
    }
    .rcpt-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .rcpt-logo { width: 42px; height: 42px; border-radius: 10px; object-fit: cover; flex-shrink: 0; }
    .rcpt-name { font-family: 'Poppins', sans-serif; font-size: 18px; font-weight: 700; line-height: 1.15; color: #14532d; }
    .rcpt-name span { color: #16a34a; }
    .rcpt-tagline { font-size: 9px; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280; font-weight: 600; margin-top: 1px; }
    .rcpt-doc { text-align: right; flex-shrink: 0; }
    .rcpt-doc__label { font-size: 9px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; }
    .rcpt-doc__id {
        display: inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 11.5px; font-weight: 700; color: #14532d; white-space: nowrap;
        background: #f0fdf4; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 6px;
    }
    .rcpt-meta {
        display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 6px;
        padding: 8px 2px; border-bottom: 1px dashed #e5e7eb; font-size: 11px; color: #6b7280;
    }
    .rcpt-meta i { font-size: 11px; }
    .rcpt-chip {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 999px;
        font-size: 10.5px; font-weight: 700; letter-spacing: 0.02em;
    }
    .rcpt-chip--live { background: #ecfdf5; color: #166534; }
    .rcpt-chip--green { background: #ecfdf5; color: #166534; }
    .rcpt-chip--amber { background: #fffbeb; color: #92400e; }
    .rcpt-chip--red { background: #fef2f2; color: #991b1b; }
    .rcpt-chip--blue { background: #eff6ff; color: #1e40af; }

    .rcpt-sec { margin-top: 14px; }
    .rcpt-sec__label {
        font-size: 9.5px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
        color: #6b7280; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;
    }
    .rcpt-sec__label i { font-size: 11px; color: #14532d; }
    .rcpt-card {
        background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px;
    }
    .rcpt-strong { font-weight: 700; color: #111827; font-size: 13px; }
    .rcpt-sub { font-size: 11.5px; color: #6b7280; }

    .rcpt-list { margin: 0; }
    .rcpt-kv {
        display: flex; justify-content: space-between; align-items: baseline;
        font-size: 12px; padding: 4px 0; border-bottom: 1px dashed #f3f4f6;
    }
    .rcpt-kv:last-child { border-bottom: none; }
    .rcpt-kv dt { color: #6b7280; }
    .rcpt-kv dd { margin: 0; font-weight: 600; color: #111827; }

    .rcpt-total {
        display: flex; justify-content: space-between; align-items: baseline;
        margin: 8px 0 10px; padding: 10px 12px;
        background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    }
    .rcpt-total dt { font-size: 12px; font-weight: 600; color: #14532d; }
    .rcpt-total dd { margin: 0; font-size: 18px; font-weight: 800; color: #14532d; }

    .rcpt-chips { display: flex; flex-wrap: wrap; gap: 6px; }

    .rcpt-footer {
        margin-top: 18px; padding-top: 12px; border-top: 2px solid #14532d;
        text-align: center; font-size: 10.5px; font-weight: 600; color: #14532d; line-height: 1.5;
    }
    .rcpt-footer small { display: block; margin-top: 4px; font-size: 9px; font-weight: 500; color: #9ca3af; }

    /* ---- Print styles ---- */
    @media print {
        body > *:not(.rcpt-printing) { display: none !important; }
        body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
        body.rcpt-printing { background: #fff !important; }
        body.rcpt-printing > * { display: none !important; }
        body.rcpt-printing .rcpt-modal,
        body.rcpt-printing .rcpt-modal.open { position: static; opacity: 1; visibility: visible; background: none !important; }
        body.rcpt-printing .rcpt-modal__backdrop,
        body.rcpt-printing .rcpt-modal__head,
        body.rcpt-printing .rcpt-modal__foot { display: none !important; }
        body.rcpt-printing .rcpt-modal__dialog {
            width: 100%; max-width: 100%; margin: 0; border: none; border-radius: 0; box-shadow: none; transform: none;
            max-height: none; overflow: visible;
        }
        body.rcpt-printing .rcpt-modal__body { padding: 0; }
        body.rcpt-printing .rcpt-sheet { box-shadow: none; border-radius: 0; padding: 18px; max-width: 100%; margin: 0; }
        .rv-btn[title]::after { display: none !important; }
        * { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }
    }
</style>
@endsection

@section('page-header')
<div class="admin-pagehead">
    <div class="admin-pagehead__title">
        <h1>Rental History</h1>
        <p>Completed, returned, cancelled, and expired rental records</p>
    </div>
</div>
@endsection

@section('content')
<div style="display:flex;flex-direction:column;gap:0;">

    {{-- ===== Filter bar ===== --}}
    <div class="rv-filter-card">
        <div class="rv-filter-head"><i class="bi bi-funnel"></i>Refine History</div>
        <form method="GET" action="{{ route('admin.rentals.history') }}" class="rv-filter-grid">
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
                        <option value="">All History</option>
                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                        <option value="returned" {{ request('status') === 'returned' ? 'selected' : '' }}>Returned</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
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
                            <option value="{{ $b->id }}" {{ request('bicycle_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
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
                <a href="{{ route('admin.rentals.history') }}" class="btn-admin btn-admin--ghost btn-admin--sm" title="Clear all filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>

    {{-- ===== Rentals history table ===== --}}
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
                                <div class="rv-bike__name">{{ $rental->bicycle->name ?? 'Unknown' }}</div>
                                @if($rental->bicycle?->serialNumber)
                                    <div class="rv-bike__sub">SN &middot; {{ $rental->bicycle->serialNumber }}</div>
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
                                    <div class="rv-dt__time">{{ $rental->endTime?->format('h:i A') ?? '—' }}</div>
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
                                @if($rental->status === 'completed')
                                    <span class="rv-status rv-status--completed rv-status--dot">Completed</span>
                                @elseif($rental->status === 'returned')
                                    <span class="rv-status rv-status--returned rv-status--dot">Returned</span>
                                @elseif($rental->status === 'cancelled')
                                    <span class="rv-status rv-status--cancelled rv-status--dot">Cancelled</span>
                                @elseif($rental->status === 'expired')
                                    <span class="rv-status rv-status--expired rv-status--dot">Expired</span>
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
                            </td>
                            <td>
                                @if($rental->paymentStatus === 'paid')
                                    <span class="rv-status rv-status--paid"><i class="bi bi-check-circle-fill"></i>Paid</span>
                                @elseif($rental->paymentStatus === 'partial')
                                    <span class="rv-status rv-status--pending rv-status--dot">Partial</span>
                                    @if($rental->payment && (float) $rental->payment->balanceDue > 0)
                                        <div class="rv-fee__sub text-danger">Due &#8369;{{ number_format($rental->payment->balanceDue, 2) }}</div>
                                    @endif
                                @elseif($rental->paymentStatus === 'pending')
                                    <span class="rv-status rv-status--pending rv-status--dot">Pending</span>
                                @else
                                    <span class="rv-status rv-status--unpaid rv-status--dot">{{ ucfirst($rental->paymentStatus ?? 'unpaid') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="rv-actions">
                                    @php
                                        $detailBaseFee = ($rental->totalFee ?? 0) - (float) ($rental->overdueFee ?? 0);
                                        $historyPayment = $rental->payment;
                                        $historyPaid = (float) ($historyPayment->amountPaid ?? 0);
                                        if ($historyPaid <= 0 && $historyPayment && $historyPayment->status === 'paid') {
                                            $historyPaid = (float) ($historyPayment->totalAmount ?? 0);
                                        }
                                        $historyDue = (float) ($historyPayment->balanceDue ?? 0);
                                        $detailHasReturn = $rental->status === 'returned'
                                            || $rental->returnProcessedAt
                                            || $rental->returnCondition
                                            || $rental->returnInspectedBy
                                            || $rental->returnNote;
                                        $detailData = [
                                            'id'        => $rental->rentalId ?? ('REN-' . str_pad($rental->id, 4, '0', STR_PAD_LEFT)),
                                            'status'    => $rental->status,
                                            'rider'     => $rental->rider->name ?? 'Unknown',
                                            'email'     => $rental->rider->email ?? '',
                                            'bike'      => $rental->bicycle->name ?? 'Unknown',
                                            'serial'    => $rental->bicycle->serialNumber ?? '',
                                            'bikeRate'  => number_format((float) ($rental->bicycle->hourlyRate ?? $rental->ratePerHour ?? 0), 2),
                                            'start'     => $rental->startTime?->format('M d, Y h:i A') ?? '',
                                            'end'       => $rental->endTime?->format('M d, Y h:i A') ?? '',
                                            'created'   => $rental->created_at?->format('M d, Y h:i A') ?? '',
                                            'duration'  => $durLabel ?? '',
                                            'rate'      => number_format((float) ($rental->ratePerHour ?? 0), 2),
                                            'baseFee'   => number_format((float) $detailBaseFee, 2),
                                            'hasOverdue'=> (float) ($rental->overdueFee ?? 0) > 0,
                                            'overdue'   => number_format((float) ($rental->overdueFee ?? 0), 2),
                                            'finalFee'  => number_format((float) ($rental->finalFee ?? $rental->totalFee ?? 0), 2),
                                            'totalFee'  => number_format((float) ($rental->totalFee ?? 0), 2),
                                            'condition' => $rental->returnCondition ? ucwords(str_replace('_', ' ', $rental->returnCondition)) : '',
                                            'returnAt'  => $rental->returnProcessedAt?->format('M d, Y h:i A') ?? '',
                                            'inspector' => $rental->returnInspectedBy ? 'User #' . $rental->returnInspectedBy : '',
                                            'returnNote'=> $rental->returnNote ?? '',
                                            'notes'     => $rental->notes ?? '',
                                            'method'    => $rental->paymentMethod === 'gcash' ? 'GCash' : 'Cash',
                                            'payStatus' => ucfirst($rental->paymentStatus ?? 'unpaid'),
                                            'hasPayment'=> (bool) $historyPayment,
                                            'amountPaid'=> number_format($historyPaid, 2, '.', ''),
                                            'balanceDue'=> number_format($historyDue, 2, '.', ''),
                                            'hasReturn' => (bool) $detailHasReturn,
                                        ];
                                    @endphp
                                    <button type="button" class="rv-btn rv-btn--view"
                                            data-rental-detail="{{ json_encode($detailData) }}"
                                            title="View details" aria-label="View rental details">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    @php
                                        $receiptData = [
                                            'id'        => $rental->rentalId ?? ('REN-' . str_pad($rental->id, 4, '0', STR_PAD_LEFT)),
                                            'rider'     => $rental->rider->name ?? $rental->riderName ?? 'Unknown',
                                            'email'     => $rental->rider->email ?? $rental->riderEmail ?? '',
                                            'bike'      => $rental->bicycle->name ?? $rental->bicycleName ?? 'Unknown',
                                            'serial'    => $rental->bicycle->serialNumber ?? $rental->bicycleSerial ?? '',
                                            'start'     => $rental->startTime?->format('M d, Y \a\t h:i A') ?? '—',
                                            'end'       => $rental->endTime?->format('M d, Y \a\t h:i A') ?? '',
                                            'startIso'  => $rental->startTime?->toIso8601String() ?? '',
                                            'hasEnd'    => (bool) $rental->endTime,
                                            'duration'  => $durLabel,
                                            'rate'      => number_format((float) ($rental->ratePerHour ?? 0), 2),
                                            'total'     => number_format((float) ($rental->totalFee ?? 0), 2),
                                            'method'    => $rental->paymentMethod === 'gcash' ? 'GCash' : 'Cash',
                                            'payStatus' => ucfirst($rental->paymentStatus ?? 'Unpaid'),
                                            'status'    => ucfirst($rental->status),
                                            'extCount'  => (int) ($rental->extensionCount ?? 0),
                                            'extMinutes'=> (int) ($rental->extendedMinutes ?? 0),
                                            'extFee'    => number_format((float) ($rental->extensionFee ?? 0), 2),
                                            'extLast'   => $rental->lastExtendedAt?->format('M d, Y \a\t h:i A') ?? '',
                                            'extLines'  => $rental->extensionReceiptLines(),
                                            'issued'    => now()->format('M d, Y \a\t h:i A'),
                                        ];
                                    @endphp
                                    <button type="button" class="rv-btn rv-btn--print"
                                            data-print-receipt="{{ json_encode($receiptData) }}"
                                            title="Print receipt">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="11" class="rv-empty">
                                <x-admin.empty-state icon="bi-clock-history" title="No rental history found" message="No finished rentals match your filters."/>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($rentals, 'links'))
            @php($historyPages = $rentals->withQueryString())
            <div class="admin-table-foot">
                <span class="admin-table-foot__info">
                    @if($historyPages->total() > 0)
                        Showing {{ $historyPages->firstItem() }}&ndash;{{ $historyPages->lastItem() }} of {{ $historyPages->total() }} records
                    @else
                        Showing 0 records
                    @endif
                </span>
                <nav class="admin-pagination" aria-label="Rental history pages">
                    @if($historyPages->onFirstPage())
                        <span class="admin-pagination__item is-disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>
                    @else
                        <a class="admin-pagination__item" href="{{ $historyPages->previousPageUrl() }}" rel="prev"><i class="bi bi-chevron-left"></i> Previous</a>
                    @endif
                    @for($page = 1; $page <= $historyPages->lastPage(); $page++)
                        @if($page === $historyPages->currentPage())
                            <span class="admin-pagination__item is-active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="admin-pagination__item" href="{{ $historyPages->url($page) }}">{{ $page }}</a>
                        @endif
                    @endfor
                    @if($historyPages->hasMorePages())
                        <a class="admin-pagination__item" href="{{ $historyPages->nextPageUrl() }}" rel="next">Next <i class="bi bi-chevron-right"></i></a>
                    @else
                        <span class="admin-pagination__item is-disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>
                    @endif
                </nav>
            </div>
        @endif
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
                        <div class="rv-kv"><dt>End Time</dt><dd id="rdEnd">—</dd></div>
                        <div class="rv-kv"><dt>Duration</dt><dd id="rdDuration">—</dd></div>
                        <div class="rv-kv"><dt>Hourly Rate</dt><dd id="rdRate">—</dd></div>
                        <div class="rv-kv"><dt>Created</dt><dd id="rdCreated">—</dd></div>
                    </dl>
                    <div class="rv-notes" id="rdNotesWrap" style="display:none;"><strong><i class="bi bi-sticky me-1"></i>Notes</strong><span id="rdNotes"></span></div>
                </div>
                <div class="rv-panel">
                    <div class="rv-panel__label"><i class="bi bi-cash-coin"></i>Fees &amp; Payment</div>
                    <div class="rv-fees">
                        <div class="rv-tile">
                            <div class="rv-tile__label">Base Fee</div>
                            <div class="rv-tile__value" id="rdBase">—</div>
                        </div>
                        <div class="rv-tile">
                            <div class="rv-tile__label">Overdue Fee</div>
                            <div class="rv-tile__value" id="rdOverdue">—</div>
                        </div>
                        <div class="rv-tile rv-tile--total">
                            <div class="rv-tile__label">Final Fee</div>
                            <div class="rv-tile__value" id="rdFinal">—</div>
                        </div>
                    </div>
                    <div class="rv-payline">
                        <span class="rv-method" id="rdMethod">—</span>
                        <span class="rv-status" id="rdPayStatus">—</span>
                    </div>
                    <dl style="margin:8px 0 0;">
                        <div class="rv-kv" id="rowRdPaidHistory"><dt>Amount Paid</dt><dd id="rdPaidHistory">—</dd></div>
                        <div class="rv-kv" id="rowRdDueHistory"><dt>Balance Due</dt><dd id="rdDueHistory">—</dd></div>
                    </dl>
                </div>
            </div>
            <div class="rv-panel" id="rdReturnPanel" style="margin-top:14px;">
                <div class="rv-panel__label"><i class="bi bi-arrow-return-left"></i>Return Details</div>
                <dl style="margin:0;">
                    <div class="rv-kv"><dt>Returned At</dt><dd id="rdReturnAt">—</dd></div>
                    <div class="rv-kv"><dt>Condition</dt><dd id="rdCondition">—</dd></div>
                    <div class="rv-kv"><dt>Inspected By</dt><dd id="rdInspector">—</dd></div>
                </dl>
                <div class="rv-notes" id="rdReturnNoteWrap" style="display:none;margin-top:8px;"><strong><i class="bi bi-sticky me-1"></i>Return Note</strong><span id="rdReturnNote"></span></div>
            </div>
        </div>
        <div class="admin-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-modal-close>Close</button>
        </div>
    </div>
</div>

{{-- ===== Receipt preview modal ===== --}}
<div class="rcpt-modal" id="rcptModal">
    <div class="rcpt-modal__backdrop" data-rcpt-close></div>
    <div class="rcpt-modal__dialog" role="dialog" aria-modal="true" aria-label="Rental receipt preview">
        <div class="rcpt-modal__head">
            <h3><i class="bi bi-receipt"></i>Receipt Preview</h3>
            <button type="button" class="rcpt-modal__close" data-rcpt-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="rcpt-modal__body">
            <div class="rcpt-sheet" id="rcptSheet">
                <div class="rcpt-brandrow">
                    <div class="rcpt-brand">
                        <img src="{{ asset('assets/img/Logo.png') }}" alt="Pedalya logo" class="rcpt-logo">
                        <div style="min-width:0;">
                            <div class="rcpt-name">Peda<span>lya</span></div>
                            <div class="rcpt-tagline">IoT Bicycle Rental System</div>
                        </div>
                    </div>
                    <div class="rcpt-doc">
                        <div class="rcpt-doc__label">Official Receipt</div>
                        <span class="rcpt-doc__id" id="rcptId">&mdash;</span>
                    </div>
                </div>

                <div class="rcpt-meta">
                    <span><i class="bi bi-clock-history me-1"></i>Issued: <strong id="rcptIssued" style="color:#111827;">&mdash;</strong></span>
                    <span class="rcpt-chip rcpt-chip--blue" id="rcptExtChip" style="display:none;"><i class="bi bi-plus-circle"></i>Extended</span>
                    <span class="rcpt-chip rcpt-chip--live" id="rcptRideChip"><i class="bi bi-play-fill"></i>Ride in progress</span>
                </div>

                <div class="rcpt-sec">
                    <div class="rcpt-sec__label"><i class="bi bi-person-circle"></i>Rider</div>
                    <div class="rcpt-card">
                        <div class="rcpt-strong" id="rcptRiderName">&mdash;</div>
                        <div class="rcpt-sub" id="rcptRiderContact">&mdash;</div>
                    </div>
                </div>

                <div class="rcpt-sec">
                    <div class="rcpt-sec__label"><i class="bi bi-bicycle"></i>Bicycle</div>
                    <div class="rcpt-card">
                        <div class="rcpt-strong" id="rcptBikeName">&mdash;</div>
                        <div class="rcpt-sub" id="rcptBikeSub">&mdash;</div>
                    </div>
                </div>

                <div class="rcpt-sec">
                    <div class="rcpt-sec__label"><i class="bi bi-calendar3"></i>Rental Period</div>
                    <dl class="rcpt-list">
                        <div class="rcpt-kv"><dt>Start</dt><dd id="rcptStart">&mdash;</dd></div>
                        <div class="rcpt-kv"><dt>End</dt><dd id="rcptEnd">In progress</dd></div>
                        <div class="rcpt-kv">
                            <dt>Duration</dt>
                            <dd><span id="rcptDuration">&mdash;</span> <small style="font-weight:500;font-size:10px;color:#c2410c;" id="rcptDurationNote"></small></dd>
                        </div>
                        <div class="rcpt-kv"><dt>Rate</dt><dd>&#8369;<span id="rcptRate">0.00</span> / hour</dd></div>
                    </dl>
                </div>

                {{-- Ride extensions: shown only when the rider bought extra time,
                     so staff can read an extended ride instead of an overdue one. --}}
                <div class="rcpt-sec" id="rcptExtSec" style="display:none;">
                    <div class="rcpt-sec__label"><i class="bi bi-plus-circle"></i>Ride Extended</div>
                    <dl class="rcpt-list">
                        <div class="rcpt-kv"><dt>Extensions</dt><dd id="rcptExtCount">&mdash;</dd></div>
                        <div class="rcpt-kv"><dt>Extra Time</dt><dd id="rcptExtMinutes">&mdash;</dd></div>
                        <div class="rcpt-kv"><dt>Extension Fee</dt><dd>&#8369;<span id="rcptExtFee">0.00</span></dd></div>
                        <div class="rcpt-kv"><dt>Last Extended</dt><dd id="rcptExtLast">&mdash;</dd></div>
                    </dl>
                    <ul id="rcptExtLines" style="margin:6px 0 0;padding-left:16px;font-size:10.5px;color:#6b6b6b;"></ul>
                </div>

                <div class="rcpt-sec">
                    <div class="rcpt-sec__label"><i class="bi bi-cash-coin"></i>Payment</div>
                    <dl class="rcpt-total">
                        <dt>Total Fee</dt>
                        <dd>&#8369;<span id="rcptTotal">0.00</span></dd>
                    </dl>
                    <div class="rcpt-chips">
                        <span class="rcpt-chip" id="rcptMethod">&mdash;</span>
                        <span class="rcpt-chip" id="rcptPayStatus">&mdash;</span>
                    </div>
                </div>

                <div class="rcpt-footer">
                    Thank you for riding with Pedalya!
                    <small>
                        Pedalya IoT Bicycle Rental Management System &bull; Azuela Cove, Davao City<br>
                        This receipt was generated electronically and is valid without signature.
                    </small>
                </div>
            </div>
        </div>
        <div class="rcpt-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-rcpt-close>Close</button>
            <button type="button" class="btn-admin btn-admin--primary btn-admin--sm" id="rcptPrintBtn">
                <i class="bi bi-printer me-1"></i>Print Receipt
            </button>
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

    function statusPill(status) {
        var map = {
            completed: ['rv-status--completed', 'Completed'],
            returned: ['rv-status--returned', 'Returned'],
            cancelled: ['rv-status--cancelled', 'Cancelled'],
            expired: ['rv-status--expired', 'Expired']
        };
        var entry = map[String(status || '').toLowerCase()] || ['rv-status--cancelled', status ? status.charAt(0).toUpperCase() + status.slice(1) : '\u2014'];
        var node = document.getElementById('rdStatus');
        if (node) node.className = 'rv-status rv-status--dot ' + entry[0];
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
        setText('rdEnd', d.end);
        setText('rdDuration', d.duration);
        setText('rdRate', d.rate ? '\u20B1' + d.rate : '');
        setText('rdCreated', d.created);
        setText('rdBase', d.baseFee ? '\u20B1' + d.baseFee : '');
        setText('rdOverdue', d.hasOverdue ? '\u20B1' + d.overdue : '');
        setText('rdFinal', d.finalFee ? '\u20B1' + d.finalFee : '');
        setText('rdPaidHistory', d.hasPayment ? '\u20B1' + d.amountPaid : '');
        setText('rdDueHistory', d.hasPayment ? '\u20B1' + d.balanceDue : '');
        var paidRow = document.getElementById('rowRdPaidHistory');
        var dueRow = document.getElementById('rowRdDueHistory');
        if (paidRow) paidRow.style.display = d.hasPayment ? '' : 'none';
        if (dueRow) dueRow.style.display = d.hasPayment && Number(d.balanceDue) > 0 ? '' : 'none';
        var method = document.getElementById('rdMethod');
        if (method) {
            method.textContent = d.method || '\u2014';
            method.className = 'rv-method ' + (d.method === 'GCash' ? 'rv-method--gcash' : 'rv-method--cash');
        }
        var pay = document.getElementById('rdPayStatus');
        if (pay) {
            var st = String(d.payStatus || '').toLowerCase();
            pay.textContent = d.payStatus || '\u2014';
            pay.className = 'rv-status ' + (st === 'paid' ? 'rv-status--paid' : (st === 'partial' ? 'rv-status--pending' : 'rv-status--unpaid')) + (st === 'paid' ? '' : ' rv-status--dot');
            if (st === 'paid') {
                pay.innerHTML = '<i class="bi bi-check-circle-fill"></i>' + (d.payStatus || 'Paid');
            }
        }
        setText('rdReturnAt', d.returnAt);
        setText('rdCondition', d.condition);
        setText('rdInspector', d.inspector);
        var returnPanel = document.getElementById('rdReturnPanel');
        if (returnPanel) returnPanel.style.display = d.hasReturn ? '' : 'none';
        var noteWrap = document.getElementById('rdNotesWrap');
        if (noteWrap) {
            noteWrap.style.display = d.notes ? '' : 'none';
            setText('rdNotes', d.notes);
        }
        var returnNoteWrap = document.getElementById('rdReturnNoteWrap');
        if (returnNoteWrap) {
            returnNoteWrap.style.display = d.returnNote ? '' : 'none';
            setText('rdReturnNote', d.returnNote);
        }
        modal.classList.add('open');
    }

    function closeDetail() { modal.classList.remove('open'); }

    document.querySelectorAll('[data-rental-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try { openDetail(JSON.parse(btn.getAttribute('data-rental-detail'))); } catch (e) { /* ignore */ }
        });
    });

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

/* --- Receipt preview & print --- */
(function () {
    var modal = document.getElementById('rcptModal');
    if (!modal) return;
    var current = null;
    var tick = null;

    function el(id) { return document.getElementById(id); }

    function fmtDuration(totalMin) {
        var h = Math.floor(totalMin / 60), m = totalMin % 60;
        return h ? h + 'h ' + m + 'm' : m + 'm';
    }

    function chip(node, text, tone) { node.textContent = text; node.className = 'rcpt-chip ' + tone; }

    /* Ride extensions: hides the section entirely for a rental that was never
       extended, otherwise lists every extension the rider bought. */
    function paintExtensions(d) {
        var count = Number(d.extCount) || 0;
        var sec = el('rcptExtSec');
        var extChip = el('rcptExtChip');
        if (extChip) extChip.style.display = count > 0 ? '' : 'none';
        if (!sec) return;
        sec.style.display = count > 0 ? '' : 'none';
        if (!count) return;
        el('rcptExtCount').textContent = count + (count === 1 ? ' extension' : ' extensions');
        el('rcptExtMinutes').textContent = fmtDuration(Number(d.extMinutes) || 0);
        el('rcptExtFee').textContent = d.extFee || '0.00';
        el('rcptExtLast').textContent = d.extLast || '\u2014';
        var list = el('rcptExtLines');
        if (list) {
            list.innerHTML = '';
            (d.extLines || []).forEach(function (line) {
                var li = document.createElement('li');
                li.textContent = line;
                list.appendChild(li);
            });
        }
    }

    function refreshLive() {
        if (!current || current.hasEnd) return;
        var start = new Date(current.startIso).getTime();
        var mins = isNaN(start) ? 0 : Math.max(0, Math.floor((Date.now() - start) / 60000));
        el('rcptDuration').textContent = fmtDuration(mins);
        el('rcptDurationNote').textContent = '(ongoing)';
    }

    function openReceipt(d) {
        current = d;
        el('rcptId').textContent = d.id;
        el('rcptIssued').textContent = d.issued;
        el('rcptRiderName').textContent = d.rider;
        var contact = [d.email].filter(Boolean).join('');
        el('rcptRiderContact').textContent = contact || '\u2014';
        el('rcptRiderContact').style.display = contact ? '' : 'none';
        el('rcptBikeName').textContent = d.bike;
        el('rcptBikeSub').textContent = d.serial ? 'SN \u00B7 ' + d.serial : '\u2014';
        el('rcptStart').textContent = d.start;
        el('rcptEnd').textContent = d.hasEnd ? d.end : 'In progress';
        el('rcptDurationNote').textContent = '';
        if (d.duration && d.hasEnd) { el('rcptDuration').textContent = d.duration; }
        else { refreshLive(); }
        el('rcptRate').textContent = d.rate;
        el('rcptTotal').textContent = d.total;
        paintExtensions(d);
        chip(el('rcptMethod'), d.method, d.method === 'GCash' ? 'rcpt-chip--blue' : 'rcpt-chip--green');
        var st = String(d.payStatus).toLowerCase();
        chip(el('rcptPayStatus'), d.payStatus,
             st === 'paid' ? 'rcpt-chip--green' : ((st === 'pending' || st === 'partial') ? 'rcpt-chip--amber' : 'rcpt-chip--red'));
        var ride = String(d.status || '').toLowerCase();
        var rideTone = ride === 'completed' ? 'rcpt-chip--green'
                     : (ride === 'pending' ? 'rcpt-chip--amber'
                     : (ride === 'cancelled' ? 'rcpt-chip--red'
                     : (ride === 'overdue' ? 'rcpt-chip--red' : 'rcpt-chip--live')));
        var rideIcon = ride === 'completed' ? 'bi-check-circle-fill'
                     : (ride === 'pending' ? 'bi-hourglass-split'
                     : (ride === 'cancelled' ? 'bi-x-circle-fill'
                     : (ride === 'overdue' ? 'bi-exclamation-triangle-fill' : 'bi-play-fill')));
        var rideLabel = ride === 'active' ? 'Ride in progress' : d.status;
        var rc = el('rcptRideChip');
        rc.className = 'rcpt-chip ' + rideTone;
        rc.innerHTML = '<i class="bi ' + rideIcon + '"></i>' + rideLabel;
        modal.classList.add('open');
        document.body.classList.add('rcpt-printing');
        if (tick) clearInterval(tick);
        tick = setInterval(refreshLive, 30000);
    }

    function closeReceipt() {
        modal.classList.remove('open');
        document.body.classList.remove('rcpt-printing');
        if (tick) { clearInterval(tick); tick = null; }
        current = null;
    }

    document.querySelectorAll('[data-print-receipt]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try { openReceipt(JSON.parse(btn.getAttribute('data-print-receipt'))); } catch (e) { /* ignore */ }
        });
    });

    modal.querySelectorAll('[data-rcpt-close]').forEach(function (node) {
        node.addEventListener('click', closeReceipt);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('open')) closeReceipt();
    });

    var printBtn = el('rcptPrintBtn');
    if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
})();
</script>
@endsection
