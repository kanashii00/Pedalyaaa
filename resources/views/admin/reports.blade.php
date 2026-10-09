@extends('layouts.admin')

@php
    $reportMeta = [
        'customer' => ['title' => 'Customer Reports', 'desc' => 'View, filter and export customer reports'],
        'rental'   => ['title' => 'Rental Reports', 'desc' => 'View, filter and export rental reports'],
        'bicycle'  => ['title' => 'Bicycle Reports', 'desc' => 'View, filter and export bicycle usage reports'],
        'theft'    => ['title' => 'Theft Reports', 'desc' => 'View, filter and export theft / theft-detection reports'],
        'accident' => ['title' => 'Accident Reports', 'desc' => 'View, filter and export accident reports'],
        'revenue'  => ['title' => 'Revenue Reports', 'desc' => 'View, filter and export revenue reports'],
        'export'   => ['title' => 'Export Reports', 'desc' => 'Export report data to PDF, Excel, or CSV'],
    ];
    $meta = $reportMeta[$reportType] ?? $reportMeta['customer'];
@endphp

@section('title', $meta['title'])

@section('page-header')
    <h1>{{ $meta['title'] }}</h1>
    <p>{{ $meta['desc'] }}</p>
@endsection

@section('actions')
<button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" onclick="clearResults()">
    <i class="bi bi-x-lg me-1"></i>Clear Results
</button>
@endsection

@section('styles')
<style>
    /* ── Rental Details modal: cohesive theme-aware navy treatment ──
       Bootstrap paints .modal-content white by default, which split this
       dialog into a dark header/cards over a white body and footer. These
       overrides bind every region to the dashboard tokens instead, so the
       modal reads as one surface in both light and dark themes. */
    .modal-content.rental-detail {
        --bs-modal-bg: var(--surface);
        --bs-modal-border-color: var(--border-subtle);
        --bs-modal-header-border-color: var(--border-subtle);
        --bs-modal-footer-border-color: var(--border-subtle);
        background: var(--surface);
        color: var(--text-1);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius);
        box-shadow: var(--shadow-pop);
        overflow: hidden;
    }
    .rental-detail__head {
        background: transparent; border-bottom: 1px solid var(--border-subtle);
        padding: 14px 18px; gap: 12px; align-items: center;
    }
    .rental-detail__icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
        background: var(--brand-soft); color: var(--brand-strong); font-size: 17px;
    }
    .rental-detail__titles { flex: 1; min-width: 0; }
    .rental-detail__titles .modal-title { font-size: 15px; font-weight: 700; color: var(--text-1); line-height: 1.3; }
    .rental-detail__subtitle {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 12px; color: var(--text-3); margin-top: 2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .rental-detail__close {
        flex-shrink: 0; width: 32px; height: 32px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid transparent; border-radius: 9px;
        background: none; color: var(--text-3); font-size: 14px;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .rental-detail__close:hover { background: var(--surface-3); border-color: var(--border-subtle); color: var(--text-1); }
    .rental-detail__close:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    .rental-detail .modal-body { padding: 18px; color: var(--text-1); background: transparent; }
    .rental-detail__foot {
        background: transparent; border-top: 1px solid var(--border-subtle);
        padding: 13px 18px; align-items: center;
    }
    .rd-sec { margin-bottom: 16px; }
    .rd-sec:last-child { margin-bottom: 0; }
    .rd-sec__label {
        display: flex; align-items: center; gap: 7px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
        color: var(--text-3); margin-bottom: 8px;
    }
    .rd-sec__label i { font-size: 13px; color: var(--brand-strong); }
    /* Cards sit one surface above the dialog (surface-2) with blue-gray
       dividers; labels stay muted slate, values stay near-white. */
    .rd-rows { border: 1px solid var(--border-subtle); border-radius: 10px; overflow: hidden; background: var(--surface-2); }
    .rd-row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 10px 14px; font-size: 13px; line-height: 1.45; }
    .rd-row + .rd-row { border-top: 1px solid var(--border-subtle); }
    .rd-row dt { margin: 0; color: var(--text-3); font-weight: 500; flex-shrink: 0; }
    .rd-row dd { margin: 0; color: var(--text-1); font-weight: 600; text-align: right; min-width: 0; overflow-wrap: anywhere; }
    .rd-row dd.rd-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
    .rd-row dd.rd-num { font-variant-numeric: tabular-nums; }
    .rd-badges { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
    /* Final fee: prominent through type scale and a slim success edge —
       not a full green color block. */
    .rd-total {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        background: var(--surface-2); border: 1px solid var(--border-subtle); border-left: 3px solid var(--success);
        border-radius: 10px; padding: 12px 14px; margin-top: 10px;
        box-shadow: var(--shadow-card);
    }
    .rd-total dt { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-3); }
    .rd-total dd { margin: 0; font-size: 21px; font-weight: 800; color: var(--text-1); font-variant-numeric: tabular-nums; line-height: 1.2; }
    @media (max-width: 576px) {
        .rental-detail__head { padding: 12px 14px; }
        .rental-detail .modal-body { padding: 14px; }
        .rental-detail__foot { padding: 11px 14px; }
        .rd-row { padding: 9px 12px; font-size: 12.5px; }
        .rd-total dd { font-size: 19px; }
    }

    /* ── Customer Details modal: same enterprise navy treatment as the
       Rental Details modal, scoped separately so each dialog stays
       independent. Green appears only on accents, badges and positive
       statuses; surfaces, borders and text all come from dashboard tokens
       so light and dark themes stay cohesive. */
    .modal-content.customer-detail {
        --bs-modal-bg: var(--surface);
        --bs-modal-border-color: var(--border-subtle);
        --bs-modal-header-border-color: var(--border-subtle);
        --bs-modal-footer-border-color: var(--border-subtle);
        background: var(--surface);
        color: var(--text-1);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius);
        box-shadow: var(--shadow-pop);
        overflow: hidden;
    }
    .customer-detail__head {
        background: transparent; border-bottom: 1px solid var(--border-subtle);
        padding: 14px 18px; gap: 12px; align-items: center;
    }
    .customer-detail__icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
        background: var(--brand-soft); color: var(--brand-strong); font-size: 17px;
    }
    .customer-detail__titles { flex: 1; min-width: 0; }
    .customer-detail__titles .modal-title { font-size: 15px; font-weight: 700; color: var(--text-1); line-height: 1.3; }
    .customer-detail__subtitle {
        font-size: 12px; color: var(--text-3); margin-top: 2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .customer-detail__close {
        flex-shrink: 0; width: 32px; height: 32px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid transparent; border-radius: 9px;
        background: none; color: var(--text-3); font-size: 14px;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .customer-detail__close:hover { background: var(--surface-3); border-color: var(--border-subtle); color: var(--text-1); }
    .customer-detail__close:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    .customer-detail .modal-body { padding: 18px; color: var(--text-1); background: transparent; }
    .customer-detail__foot {
        background: transparent; border-top: 1px solid var(--border-subtle);
        padding: 13px 18px; align-items: center;
    }
    .cd-profile { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; min-width: 0; }
    .cd-avatar {
        width: 56px; height: 56px; border-radius: 50%; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--brand-soft); color: var(--brand-strong);
        font-size: 1.15rem; font-weight: 700;
    }
    .cd-profile__text { flex: 1; min-width: 0; }
    .cd-profile__name { font-size: 16px; font-weight: 700; color: var(--text-1); line-height: 1.3; overflow-wrap: anywhere; }
    .cd-profile__email { font-size: 12.5px; color: var(--text-3); margin-top: 2px; overflow-wrap: anywhere; }
    .cd-profile__badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .cd-sec { margin-bottom: 16px; }
    .cd-sec:last-child { margin-bottom: 0; }
    .cd-sec__label {
        display: flex; align-items: center; gap: 7px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
        color: var(--text-3); margin-bottom: 8px;
    }
    .cd-sec__label i { font-size: 13px; color: var(--brand-strong); }
    .cd-rows { border: 1px solid var(--border-subtle); border-radius: 10px; overflow: hidden; background: var(--surface-2); }
    .cd-row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 10px 14px; font-size: 13px; line-height: 1.45; }
    .cd-row + .cd-row { border-top: 1px solid var(--border-subtle); }
    .cd-row dt { margin: 0; color: var(--text-3); font-weight: 500; flex-shrink: 0; }
    .cd-row dd { margin: 0; color: var(--text-1); font-weight: 600; text-align: right; min-width: 0; overflow-wrap: anywhere; }
    .cd-row dd.cd-num { font-variant-numeric: tabular-nums; }
    .cd-badges { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
    /* Total Spent: prominent through type scale and a slim success edge —
       same restraint as the rental Final Fee card. */
    .cd-total {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        background: var(--surface-2); border: 1px solid var(--border-subtle); border-left: 3px solid var(--success);
        border-radius: 10px; padding: 12px 14px; margin-top: 10px;
        box-shadow: var(--shadow-card);
    }
    .cd-total dt { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--text-3); }
    .cd-total dd { margin: 0; font-size: 21px; font-weight: 800; color: var(--text-1); font-variant-numeric: tabular-nums; line-height: 1.2; }
    @media (max-width: 576px) {
        .customer-detail__head { padding: 12px 14px; }
        .customer-detail .modal-body { padding: 14px; }
        .customer-detail__foot { padding: 11px 14px; }
        .cd-avatar { width: 48px; height: 48px; font-size: 1rem; }
        .cd-profile__name { font-size: 15px; }
        .cd-row { padding: 9px 12px; font-size: 12.5px; }
        .cd-total dd { font-size: 19px; }
    }

    /* ── Report results pagination: dark navy buttons, green text, solid
       green active page. Reuses the shared admin-pagination pattern; only
       the text colour is tuned to the reference (active/disabled states
       come from the shared classes untouched). */
    .report-table-wrap { overflow: hidden; }
    .report-table-wrap > .table-responsive { border-bottom: none; }
    .report-pagination .admin-pagination__item { color: var(--brand-strong); }
    .report-pagination a.admin-pagination__item:hover { color: var(--brand-strong); }
    .report-pagination .admin-pagination__item.is-disabled { color: var(--text-3); }
    @media (max-width: 767.98px) {
        .report-pagination { justify-content: center; text-align: center; }
    }
</style>
@endsection

@section('content')
@if($reportType === 'export')
    @include('admin.reports.export')
@else
<div class="admin-card mb-4">
    <div class="admin-card__body">
        @if($reportType === 'customer')
        <form action="{{ route('admin.reports.customer') }}" method="POST" id="customerReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="customerSearch" name="search"
                        placeholder="Name, email, phone, student ID...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="customerDateFrom" name="date_from">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="customerDateTo" name="date_to">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="customerStatus" name="status">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                        <option value="blacklisted">Blacklisted</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Verification</label>
                    <select class="form-select" id="customerVerified" name="verified">
                        <option value="">All</option>
                        <option value="1">Verified</option>
                        <option value="0">Pending</option>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('customer')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('customer', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('customer', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('customer', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @elseif($reportType === 'rental')
        <form action="{{ route('admin.reports.rental') }}" method="POST" id="rentalReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="rentalSearch" name="search"
                        placeholder="Rental ID, rider, bicycle...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="rentalDateFrom" name="date_from"
                        value="{{ now()->subDays(30)->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="rentalDateTo" name="date_to"
                        value="{{ now()->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Rental Status</label>
                    <select class="form-select" id="rentalStatus" name="status">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="returned">Returned</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="expired">Expired</option>
                        <option value="overdue">Overdue</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Status</label>
                    <select class="form-select" id="rentalPaymentStatus" name="payment_status">
                        <option value="">All</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bicycle</label>
                    <select class="form-select" id="rentalBicycle" name="bicycle_id">
                        <option value="">All</option>
                        @foreach($bicycles ?? [] as $bicycle)
                            <option value="{{ $bicycle->id }}">{{ $bicycle->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-2">
                    <label class="form-label">Rider</label>
                    <select class="form-select" id="rentalRider" name="user_id">
                        <option value="">All</option>
                        @foreach($users ?? [] as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm w-100" onclick="clearRentalFilters()" title="Clear Filters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('rental')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('rental', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('rental', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('rental', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @elseif($reportType === 'bicycle')
        <form action="{{ route('admin.reports.bicycle') }}" method="POST" id="bicycleReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="bicycleSearch" name="search"
                        placeholder="Bicycle name, model, serial...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="bicycleStatus" name="status">
                        <option value="">All</option>
                        <option value="available">Available</option>
                        <option value="rented">Rented</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="{{ \App\Models\Bicycle::STATUS_OUT_OF_SERVICE }}">Out of Service</option>
                        <option value="removed">Removed</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm w-100" onclick="clearBicycleFilters()" title="Clear Filters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('bicycle')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('bicycle', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('bicycle', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('bicycle', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @elseif($reportType === 'theft')
        <form action="{{ route('admin.reports.theft') }}" method="POST" id="theftReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="theftSearch" name="search"
                        placeholder="Description, action taken...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="theftDateFrom" name="date_from"
                        value="{{ now()->subDays(30)->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="theftDateTo" name="date_to"
                        value="{{ now()->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="theftStatus" name="status">
                        <option value="">All</option>
                        <option value="open">Open</option>
                        <option value="resolved">Resolved</option>
                        <option value="in_progress">In Progress</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bicycle</label>
                    <select class="form-select" id="theftBicycle" name="bicycle_id">
                        <option value="">All</option>
                        @foreach($bicycles ?? [] as $bicycle)
                            <option value="{{ $bicycle->id }}">{{ $bicycle->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm w-100" onclick="clearTheftFilters()" title="Clear Filters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('theft')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('theft', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('theft', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('theft', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @elseif($reportType === 'accident')
        <form action="{{ route('admin.reports.accident') }}" method="POST" id="accidentReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" id="accidentSearch" name="search"
                        placeholder="Description, action taken...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="accidentDateFrom" name="date_from"
                        value="{{ now()->subDays(30)->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="accidentDateTo" name="date_to"
                        value="{{ now()->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Severity</label>
                    <select class="form-select" id="accidentSeverity" name="severity">
                        <option value="">All</option>
                        <option value="minor">Minor</option>
                        <option value="moderate">Moderate</option>
                        <option value="major">Major</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="accidentStatus" name="status">
                        <option value="">All</option>
                        <option value="open">Open</option>
                        <option value="resolved">Resolved</option>
                        <option value="in_progress">In Progress</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bicycle</label>
                    <select class="form-select" id="accidentBicycle" name="bicycle_id">
                        <option value="">All</option>
                        @foreach($bicycles ?? [] as $bicycle)
                            <option value="{{ $bicycle->id }}">{{ $bicycle->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-2">
                    <label class="form-label">Rider</label>
                    <select class="form-select" id="accidentRider" name="user_id">
                        <option value="">All</option>
                        @foreach($users ?? [] as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select class="form-select" id="accidentType" name="incident_type">
                        <option value="">All</option>
                        <option value="accident">Accident</option>
                        <option value="impact_detected">Impact Detected</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn-admin btn-admin--ghost btn-admin--sm w-100" onclick="clearAccidentFilters()" title="Clear Filters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                    </button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('accident')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('accident', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('accident', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('accident', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @elseif($reportType === 'revenue')
        <form action="{{ route('admin.reports.revenue') }}" method="POST" id="revenueReportForm" onsubmit="return generateReport(event, this)">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="revenueDateFrom" name="date_from"
                        value="{{ old('date_from', now()->subDays(30)->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="revenueDateTo" name="date_to"
                        value="{{ old('date_to', now()->format('Y-m-d')) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Group By</label>
                    <select class="form-select" id="revenueGroupBy" name="group_by" required>
                        <option value="day">Day</option>
                        <option value="week">Week</option>
                        <option value="month" selected>Month</option>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button type="submit" class="btn-admin btn-admin--primary">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printReport('revenue')">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="exportReport('revenue', 'pdf')">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="exportReport('revenue', 'excel')">
                        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="exportReport('revenue', 'csv')">
                        <i class="bi bi-filetype-csv me-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
        @endif
    </div>
</div>

<!-- Results Display Area -->
<div class="admin-card mb-4" id="resultsCard" style="display: none;">
    <div class="admin-card__head">
        <div class="admin-card__title"><i class="bi bi-pie-chart me-2"></i>Report Results</div>
        <div class="admin-card__tools"><span class="badge-admin badge-admin--neutral" id="resultCount">0</span></div>
    </div>
    <div class="admin-card__body">
        <div id="reportResults">
            <div class="text-center text-muted py-5">
                <i class="bi bi-bar-chart" style="font-size:3rem;"></i>
                <p class="mt-3">No data loaded yet.</p>
            </div>
        </div>
    </div>
</div>

<!-- Customer Details Modal: compact dark-theme enterprise layout matching
     the Rental Details modal. The accident modal keeps its own header. -->
<div class="modal fade" id="customerDetailModal" tabindex="-1" aria-labelledby="customerDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content customer-detail">
            <div class="modal-header customer-detail__head">
                <span class="customer-detail__icon" aria-hidden="true"><i class="bi bi-person-circle"></i></span>
                <div class="customer-detail__titles">
                    <h6 class="modal-title" id="customerDetailTitle">Customer Details</h6>
                    <div class="customer-detail__subtitle" id="customerDetailSubtitle">—</div>
                </div>
                <button type="button" class="customer-detail__close" data-bs-dismiss="modal" aria-label="Close customer details"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body" id="customerDetailBody"></div>
            <div class="modal-footer customer-detail__foot">
                <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Rental Details Modal: compact dark-theme enterprise layout. The other
     report modals keep their own headers; only this shell is restyled. -->
<div class="modal fade" id="rentalDetailModal" tabindex="-1" aria-labelledby="rentalDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rental-detail">
            <div class="modal-header rental-detail__head">
                <span class="rental-detail__icon" aria-hidden="true"><i class="bi bi-bicycle"></i></span>
                <div class="rental-detail__titles">
                    <h6 class="modal-title" id="rentalDetailTitle">Rental Details</h6>
                    <div class="rental-detail__subtitle" id="rentalDetailSubtitle">—</div>
                </div>
                <button type="button" class="rental-detail__close" data-bs-dismiss="modal" aria-label="Close rental details"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body" id="rentalDetailBody"></div>
            <div class="modal-footer rental-detail__foot">
                <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Accident Details Modal -->
<div class="modal fade" id="accidentDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: var(--radius); border: none;">
            <div class="modal-header" style="background: var(--brand); color: #fff; border-radius: var(--radius) var(--radius) 0 0;">
                <h6 class="modal-title fw-semibold"><i class="bi bi-activity me-2"></i>Accident Details</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="accidentDetailBody"></div>
            <div class="modal-footer" style="border-top: 1px solid var(--border-subtle);">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@section('scripts')
<script>
var _token = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
var _baseUrl = '{{ url("/admin/reports/export/pdf") }}'.replace('/export/pdf', '');
var _reportType = '{{ $reportType }}';
var _reportData = { customer: [], rental: [], bicycle: [], theft: [], accident: [], revenue: [], incident: [] };
var _reportPage = { customer: 1, rental: 1, bicycle: 1, theft: 1, accident: 1, revenue: 1, incident: 1 };
var _reportSeq = { customer: 0, rental: 0, bicycle: 0, theft: 0, accident: 0, revenue: 0, incident: 0 };
var _appliedFilters = {};
var _summaryHtml = {};
var _pageSize = 10;

/* ─── Report loading ─── */
function generateReport(event, form) {
    event.preventDefault();
    loadReport(form);
    return false;
}

/* Stable snapshot of the visible filter values, used to tell whether the table
   on screen still matches the controls above it. */
function filterSignature(form) {
    var parts = [];
    form.querySelectorAll('input[name], select[name]').forEach(function (field) {
        if (field.type === 'hidden') return;
        parts.push(field.name + '=' + (field.value || ''));
    });
    return parts.sort().join('&');
}

/* Loads a report for the form's current filter values and renders it. Returns a
   promise that resolves once the table is on screen. */
function loadReport(form) {
    var url = form.getAttribute('action');
    var formData = new FormData(form);
    var resultsDiv = document.getElementById('reportResults');
    var resultsCard = document.getElementById('resultsCard');
    var resultCount = document.getElementById('resultCount');
    var type = detectReportType(form);
    var seq = ++_reportSeq[type];

    resultsCard.style.display = 'block';
    resultsDiv.innerHTML = '<div class="text-center py-5"><div class="spinner-border" style="color:var(--brand);" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-3 text-muted">Loading report...</p></div>';

    return fetch(url, {
        method: 'POST',
        body: formData,
        headers: { 'X-CSRF-TOKEN': _token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(function (r) {
        if (!r.ok) {
            return r.json().catch(function () { return null; }).then(function (body) {
                var msg = (body && body.message) ? body.message : 'Server error (' + r.status + ')';
                throw new Error(msg);
            });
        }
        return r.json();
    })
    .then(function (data) {
        if (seq !== _reportSeq[type]) return;
        var records = data.data || [];
        var summary = data.summary || null;
        if (!_reportData.hasOwnProperty(type)) _reportData[type] = [];
        _reportData[type] = records; _reportPage[type] = 1;
        _appliedFilters[type] = filterSignature(form);

        if (records.length === 0 && !summary) {
            resultCount.textContent = '0 records';
            resultsDiv.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:3rem;"></i><p class="mt-3">No data found for the selected criteria.</p></div>';
            return;
        }

        var html = '';
        if (summary) {
            _summaryHtml[type] = renderSummaryCards(type, summary);
            html += _summaryHtml[type];
        } else {
            _summaryHtml[type] = '';
        }

        if (records.length > 0) {
            resultCount.textContent = records.length + ' record' + (records.length !== 1 ? 's' : '');
            // Table + footer share one rounded card so the pagination row
            // spans the full table width inside the border.
            html += '<div class="admin-table-wrap report-table-wrap">' + renderTable(type, records) + '</div>';
        } else {
            resultCount.textContent = summary ? 'Summary' : '0 records';
        }

        resultsDiv.innerHTML = html;
    })
    .catch(function (error) {
        if (seq !== _reportSeq[type]) return;
        resultCount.textContent = 'Error';
        resultsDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Error loading report: ' + error.message + '</div>';
    });
}

function detectReportType(form) {
    var id = form.id || '';
    if (id.indexOf('customer') !== -1) return 'customer';
    if (id.indexOf('rental') !== -1) return 'rental';
    if (id.indexOf('bicycle') !== -1) return 'bicycle';
    if (id.indexOf('theft') !== -1) return 'theft';
    if (id.indexOf('accident') !== -1) return 'accident';
    if (id.indexOf('revenue') !== -1) return 'revenue';
    if (id.indexOf('incident') !== -1) return 'incident';
    return 'rental';
}

function renderTable(type, records, page) {
    var target = page || 1;
    switch (type) {
        case 'customer': return renderCustomerTable(records, target);
        case 'rental': return renderRentalTable(records, target);
        case 'bicycle': return renderBicycleTable(records, target);
        case 'theft': return renderTheftTable(records, target);
        case 'accident': return renderAccidentTable(records, target);
        case 'revenue': return renderRevenueTable(records, target);
        case 'incident': return renderIncidentTable(records, target);
        default: return renderRentalTable(records, target);
    }
}

/* ─── Summary Cards ─── */
function renderSummaryCards(type, summary) {
    var labels = {
        total: 'Total', verified: 'Verified', pending: 'Pending Verification',
        blacklisted: 'Blacklisted', active: 'Active',
        completed: 'Completed', cancelled: 'Cancelled', overdue: 'Overdue',
        totalRevenue: 'Total Revenue', averageFee: 'Average Fee',
        averageRevenue: 'Avg Revenue', totalRentals: 'Total Rentals', periods: 'Periods',
        critical: 'Critical', high: 'High', moderate: 'Moderate', major: 'Major',
        minor: 'Minor', theftIncidents: 'Theft', acknowledged: 'Acknowledged',
        unacknowledged: 'Unacknowledged', pending_rental: 'Pending',
        resolved: 'Resolved', in_progress: 'In Progress', open: 'Open',
        available: 'Available', rented: 'Rented', maintenance: 'Maintenance',
        lowBattery: 'Low Battery'
    };
    var icons = {
        total: 'bi-bar-chart', verified: 'bi-patch-check-fill', pending: 'bi-hourglass-split',
        blacklisted: 'bi-shield-slash-fill', active: 'bi-play-circle-fill',
        completed: 'bi-check-circle-fill', cancelled: 'bi-x-circle-fill', overdue: 'bi-exclamation-triangle-fill',
        critical: 'bi-thermometer-high', major: 'bi-exclamation-diamond', acknowledged: 'bi-check2-circle',
        resolved: 'bi-check-circle-fill', in_progress: 'bi-gear', open: 'bi-hourglass-split',
        available: 'bi-cloud-check', rented: 'bi-person-check', maintenance: 'bi-tools',
        lowBattery: 'bi-battery-half', moderate: 'bi-exclamation-triangle', minor: 'bi-info-circle'
    };
    var colors = {
        total: '#2563EB', verified: '#16A34A', pending: '#D97706',
        blacklisted: '#DC2626', active: '#2E7D32',
        completed: '#16A34A', cancelled: '#DC2626', overdue: '#D97706',
        critical: '#DC2626', major: '#D97706', acknowledged: '#0EA5E9',
        resolved: '#16A34A', in_progress: '#D97706', open: '#DC2626',
        available: '#16A34A', rented: '#2563EB', maintenance: '#D97706',
        lowBattery: '#DC2626', moderate: '#D97706', minor: '#0EA5E9'
    };
    var money = { totalRevenue: true, averageFee: true, averageRevenue: true };

    var html = '<div class="row g-3 mb-4">';
    Object.entries(summary).forEach(function (entry) {
        var key = entry[0], val = entry[1];
        var label = labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); });
        var display = money[key] ? '\u20B1' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    : typeof val === 'number' ? val.toLocaleString() : val;
        var icon = icons[key] || 'bi-bar-chart';
        var color = colors[key] || 'var(--text-2)';
        html += '<div class="col-md-3 col-6"><div class="admin-card mb-0"><div class="admin-card__body text-center py-3">'
              + '<div style="color:' + color + ';font-size:1.5rem;margin-bottom:0.25rem;"><i class="bi ' + icon + '"></i></div>'
              + '<div class="fw-bold" style="font-size:1.3rem;color:var(--text-1);">' + display + '</div>'
              + '<div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;">' + label + '</div>'
              + '</div></div></div>';
    });
    html += '</div>';
    return html;
}

/* ─── Customer Table ─── */
function renderCustomerTable(records, page) {
    _reportPage.customer = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.customer - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Name</th><th>Student ID</th><th>Email</th><th>Phone</th><th>Status</th>'
        + '<th>Verified</th><th>Rentals</th><th>Total Spent</th><th>Joined</th><th>Action</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="10" class="text-center text-muted py-4">No customers found</td></tr>';
    pageRecords.forEach(function (c, idx) {
        var statusClass = 'secondary';
        if (c.status === 'active') statusClass = 'success';
        else if (c.status === 'inactive') statusClass = 'info';
        else if (c.status === 'suspended') statusClass = 'warning';
        else if (c.status === 'blacklisted') statusClass = 'danger';
        var verBadge = c.verified ? '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Verified</span>' : '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pending</span>';
        var joined = c.createdAt || c.created_at;
        var joinedStr = joined ? new Date(joined).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-';
        var spent = Number(c.totalSpent || 0);
        html += '<tr>'
            + '<td class="fw-semibold">' + esc(c.name || '-') + '</td>'
            + '<td><code>' + esc(c.studentId || '-') + '</code></td>'
            + '<td>' + esc(c.email || '-') + '</td>'
            + '<td>' + esc(c.phoneNumber || '-') + '</td>'
            + '<td><span class="badge bg-' + statusClass + '">' + esc(c.status) + '</span></td>'
            + '<td>' + verBadge + '</td>'
            + '<td class="text-center">' + (c.rentals_count ?? c.totalRentals ?? 0) + '</td>'
            + '<td class="fw-bold" style="color:var(--success);">\u20B1' + spent.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</td>'
            + '<td>' + joinedStr + '</td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-primary" onclick="viewCustomerDetail(' + start + '+' + idx + ')"><i class="bi bi-eye"></i></button></td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('customer', total, totalPages);
    return html;
}

/* ─── Rental Table ─── */
function renderRentalTable(records, page) {
    _reportPage.rental = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.rental - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Rental ID</th><th>Rider</th><th>Bicycle</th><th>Start</th><th>End</th>'
        + '<th>Duration</th><th>Rate/Hr</th><th>Fee</th><th>Payment</th><th>Pay Status</th><th>Status</th><th></th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="12" class="text-center text-muted py-4">No rentals found</td></tr>';
    pageRecords.forEach(function (r, idx) {
        var startT = r.startTime ? new Date(r.startTime).toLocaleString() : '-';
        var endT = r.endTime ? new Date(r.endTime).toLocaleString() : '-';
        var dur = r.durationMinutes ? Math.floor(r.durationMinutes / 60) + 'h ' + (r.durationMinutes % 60) + 'm' : '-';
        var rate = '\u20B1' + Number(r.ratePerHour || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
        var fee = '\u20B1' + Number(r.totalFee || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
        var statusClass = 'secondary';
        if (r.status === 'active') statusClass = 'success';
        else if (r.status === 'completed') statusClass = 'primary';
        else if (r.status === 'cancelled') statusClass = 'danger';
        else if (r.status === 'overdue') statusClass = 'warning';
        else if (r.status === 'pending') statusClass = 'info';
        var payClass = r.paymentStatus === 'paid' ? 'success' : (r.paymentStatus === 'pending' ? 'warning' : (r.paymentStatus === 'refunded' ? 'info' : 'danger'));
        html += '<tr>'
            + '<td><code>' + esc(r.rentalId || r.id) + '</code></td>'
            + '<td>' + esc((r.rider && r.rider.name) || r.riderName || '-') + '</td>'
            + '<td>' + esc((r.bicycle && r.bicycle.name) || r.bicycleName || '-') + '</td>'
            + '<td>' + startT + '</td>'
            + '<td>' + endT + '</td>'
            + '<td>' + dur + '</td>'
            + '<td>' + rate + '</td>'
            + '<td class="fw-bold">' + fee + '</td>'
            + '<td>' + esc(r.paymentMethod || '-') + '</td>'
            + '<td><span class="badge bg-' + payClass + '">' + esc(r.paymentStatus || '-') + '</span></td>'
            + '<td><span class="badge bg-' + statusClass + '">' + esc(r.status) + '</span></td>'
            + '<td><button type="button" class="btn btn-sm btn-outline-primary" onclick="viewRentalDetail(' + start + '+' + idx + ')"><i class="bi bi-eye"></i></button></td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('rental', total, totalPages);
    return html;
}

/* ─── Bicycle Table ─── */
function renderBicycleTable(records, page) {
    _reportPage.bicycle = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.bicycle - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Bicycle</th><th>Model</th><th>Status</th><th>Battery</th><th>Total Rentals</th><th>Total Distance</th><th>Total Revenue</th><th>Condition</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="8" class="text-center text-muted py-4">No bicycles found</td></tr>';
    pageRecords.forEach(function (b) {
        var statusClass = 'secondary';
        if (b.status === 'available') statusClass = 'success';
        else if (b.status === 'rented') statusClass = 'primary';
        else if (b.status === 'maintenance') statusClass = 'warning';
        else if (b.status === 'removed') statusClass = 'danger';
        var battery = (b.batteryLevel !== null && b.batteryLevel !== undefined) ? b.batteryLevel + '%' : '-';
        var batteryClass = 'success';
        if (b.batteryLevel < 20) batteryClass = 'danger';
        else if (b.batteryLevel < 50) batteryClass = 'warning';
        var dist = b.totalDistance ? Number(b.totalDistance).toFixed(2) + ' km' : '0.00 km';
        html += '<tr>'
            + '<td class="fw-semibold">' + esc(b.name || '-') + '</td>'
            + '<td>' + esc(b.model || '-') + '</td>'
            + '<td><span class="badge bg-' + statusClass + '">' + esc(b.status || '-') + '</span></td>'
            + '<td><span class="badge bg-' + batteryClass + '">' + battery + '</span></td>'
            + '<td class="text-center">' + (b.totalRentals || 0) + '</td>'
            + '<td>' + dist + '</td>'
            + '<td class="fw-bold" style="color:var(--success);">\u20B1' + (b.totalRevenue ? Number(b.totalRevenue).toFixed(2) : '0.00') + '</td>'
            + '<td>' + esc(b.condition || '-') + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('bicycle', total, totalPages);
    return html;
}

/* ─── Theft Table ─── */
function renderTheftTable(records, page) {
    _reportPage.theft = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.theft - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Theft ID</th><th>Bicycle</th><th>Severity</th><th>Description</th><th>Location</th><th>Status</th><th>Acknowledged</th><th>Timestamp</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="8" class="text-center text-muted py-4">No theft records found</td></tr>';
    pageRecords.forEach(function (r) {
        var sevClass = 'secondary';
        if (r.severity === 'critical') sevClass = 'danger';
        else if (r.severity === 'major') sevClass = 'warning';
        else if (r.severity === 'moderate') sevClass = 'info';
        else if (r.severity === 'minor') sevClass = 'light text-dark';
        var statusClass = 'secondary';
        if (r.status === 'open') statusClass = 'danger';
        else if (r.status === 'resolved' || r.status === 'closed') statusClass = 'success';
        else if (r.status === 'in_progress') statusClass = 'warning';
        var ts = r.createdAt || r.created_at;
        var tsStr = ts ? new Date(ts).toLocaleString() : '-';
        html += '<tr>'
            + '<td><code>#' + esc(r.id) + '</code></td>'
            + '<td>' + esc((r.bicycle && r.bicycle.name) || r.bicycleId || '-') + '</td>'
            + '<td><span class="badge bg-' + sevClass + '">' + esc(r.severity || '-') + '</span></td>'
            + '<td class="text-truncate" style="max-width:200px;" title="' + esc(r.description || '') + '">' + esc(r.description || '-') + '</td>'
            + '<td>' + formatLocation(r) + '</td>'
            + '<td><span class="badge bg-' + statusClass + '">' + esc(r.status || '-') + '</span></td>'
            + '<td>' + (r.acknowledged ? '<i class="bi bi-check-circle-fill text-success"></i> Yes' : '<i class="bi bi-x-circle text-muted"></i> No') + '</td>'
            + '<td>' + tsStr + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('theft', total, totalPages);
    return html;
}

/* ─── Accident Table ─── */
function renderAccidentTable(records, page) {
    _reportPage.accident = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.accident - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Accident ID</th><th>Rider</th><th>Bicycle</th><th>Location</th><th>Date/Time</th>'
        + '<th>Severity</th><th>Status</th><th>Action Taken</th><th>Actions</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="10" class="text-center text-muted py-4">No accidents found</td></tr>';
    pageRecords.forEach(function (a, idx) {
        var sevClass = 'secondary';
        if (a.severity === 'critical') sevClass = 'danger';
        else if (a.severity === 'major') sevClass = 'warning';
        else if (a.severity === 'moderate') sevClass = 'info';
        else if (a.severity === 'minor') sevClass = 'light text-dark';
        var statusClass = 'secondary';
        if (a.status === 'open') statusClass = 'danger';
        else if (a.status === 'resolved' || a.status === 'closed') statusClass = 'success';
        else if (a.status === 'in_progress') statusClass = 'warning';
        var ts = a.createdAt || a.created_at;
        var tsStr = ts ? new Date(ts).toLocaleString() : '-';
        var loc = formatLocation(a);
        var coords = getLocationCoords(a);
        var viewMapBtn = coords
            ? '<a class="btn btn-sm btn-outline-success me-1" href="https://www.google.com/maps?q=' + coords.lat + ',' + coords.lng + '" target="_blank" rel="noopener" title="View Location"><i class="bi bi-geo-alt"></i></a>'
            : '<span class="btn btn-sm btn-outline-secondary me-1 disabled" title="No location"><i class="bi bi-geo-alt"></i></span>';

        html += '<tr>'
            + '<td><code>#' + esc(a.id) + '</code></td>'
            + '<td class="fw-semibold">' + esc((a.rider && a.rider.name) || a.reportedBy || '-') + '</td>'
            + '<td>' + esc((a.bicycle && a.bicycle.name) || a.bicycleId || '-') + '</td>'
            + '<td class="text-truncate" style="max-width:140px;">' + loc + '</td>'
            + '<td>' + tsStr + '</td>'
            + '<td><span class="badge bg-' + sevClass + '">' + esc(a.severity || '-') + '</span></td>'
            + '<td><span class="badge bg-' + statusClass + '">' + esc(a.status || '-') + '</span></td>'
            + '<td class="text-truncate" style="max-width:160px;" title="' + esc(a.actionTaken || '') + '">' + esc(a.actionTaken || '-') + '</td>'
            + '<td><div class="d-flex">' + viewMapBtn + '<button type="button" class="btn btn-sm btn-outline-primary" onclick="viewAccidentDetail(' + start + '+' + idx + ')"><i class="bi bi-eye"></i></button></div></td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('accident', total, totalPages);
    return html;
}

/* ─── Pagination Builder ───
   Shared dark admin-pagination pattern (same as the audit log and
   notifications tables): record count bottom-left, controls bottom-right,
   navy buttons with green text, solid green active page. Range, pages and
   disabled states all derive from the live data — nothing hardcoded. */
function buildPagination(type, total, totalPages) {
    if (total <= 0) return '';
    totalPages = Math.max(1, totalPages);
    var currentPage = Math.min(Math.max(_reportPage[type] || 1, 1), totalPages);
    var from = (currentPage - 1) * _pageSize + 1;
    var to = Math.min(currentPage * _pageSize, total);

    var html = '<div class="admin-table-foot report-pagination">';
    html += '<span class="admin-table-foot__info">Showing ' + from + '&ndash;' + to + ' of ' + total + ' records</span>';

    /* Page buttons only earn their space once there is more than one page. */
    if (totalPages <= 1) return html + '</div>';

    function pageLink(page, labelHtml, labelText, rel) {
        return '<a class="admin-pagination__item" href="#" aria-label="' + labelText + '"' + (rel ? ' rel="' + rel + '"' : '')
            + ' onclick="event.preventDefault();paginateTable(\'' + type + '\',' + page + ')">' + labelHtml + '</a>';
    }

    html += '<nav class="admin-pagination" aria-label="' + type + ' report pages">';
    if (currentPage === 1) {
        html += '<span class="admin-pagination__item is-disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>';
    } else {
        html += pageLink(currentPage - 1, '<i class="bi bi-chevron-left"></i> Previous', 'Previous page', 'prev');
    }
    var maxPages = 7;
    var pStart = Math.max(1, currentPage - 3);
    var pEnd = Math.min(totalPages, pStart + maxPages - 1);
    if (pEnd - pStart < maxPages - 1) pStart = Math.max(1, pEnd - maxPages + 1);
    if (pStart > 1) {
        html += pageLink(1, '1', 'Page 1', '');
        if (pStart > 2) html += '<span class="admin-pagination__item is-disabled" aria-hidden="true">&hellip;</span>';
    }
    for (var p = pStart; p <= pEnd; p++) {
        if (p === currentPage) {
            html += '<span class="admin-pagination__item is-active" aria-current="page">' + p + '</span>';
        } else {
            html += pageLink(p, String(p), 'Page ' + p, '');
        }
    }
    if (pEnd < totalPages) {
        if (pEnd < totalPages - 1) html += '<span class="admin-pagination__item is-disabled" aria-hidden="true">&hellip;</span>';
        html += pageLink(totalPages, String(totalPages), 'Page ' + totalPages, '');
    }
    if (currentPage === totalPages) {
        html += '<span class="admin-pagination__item is-disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>';
    } else {
        html += pageLink(currentPage + 1, 'Next <i class="bi bi-chevron-right"></i>', 'Next page', 'next');
    }
    html += '</nav></div>';
    return html;
}

function paginateTable(type, page) {
    var container = document.getElementById('reportResults');
    if (!container) return;

    var records = _reportData[type] || [];
    var totalPages = Math.max(1, Math.ceil(records.length / _pageSize));
    var target = Math.min(Math.max(parseInt(page, 10) || 1, 1), totalPages);

    /* Re-render the whole results area from the cached summary + this page's
       slice. Rebuilding the container wholesale is what guarantees the previous
       page's table is never left behind underneath the new one. */
    container.innerHTML = (_summaryHtml[type] || '') + '<div class="admin-table-wrap report-table-wrap">' + renderTable(type, records, target) + '</div>';

    /* Stay where the user already is; only pull the results back into view when
       they have scrolled past them, so long tables keep their place. */
    if (container.getBoundingClientRect().top < 0) {
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function formatLocation(a) {
    var loc = a.gpsLocation || a.location;
    if (loc && loc.lat && loc.lng) {
        return Number(loc.lat).toFixed(5) + ', ' + Number(loc.lng).toFixed(5);
    }
    return a.location || '-';
}

function getLocationCoords(a) {
    var loc = a.gpsLocation || a.location;
    if (loc && loc.lat && loc.lng) return loc;
    return null;
}

/* ─── View Customer Detail ───
   Enterprise layout fed by the same report record (no new bindings): profile
   header plus Contact / Account Status / Rental Summary sections. Every
   value comes from the selected row; gaps render as "Not provided" or
   "Never", never as misleading placeholders. */
function viewCustomerDetail(index) {
    var c = _reportData.customer[index];
    if (!c) return;
    // Accessible badges: dot + text via badge-admin, never colour alone.
    var statusBadge = function (status) {
        var map = {
            active: ['success', 'Active'],
            inactive: ['neutral', 'Inactive'],
            suspended: ['warning', 'Suspended'],
            blacklisted: ['danger', 'Blacklisted']
        };
        var tone = (map[status] || [])[0] || 'neutral';
        var label = (map[status] || [])[1] || (status ? status.charAt(0).toUpperCase() + status.slice(1) : '—');
        return '<span class="badge-admin badge-admin--' + tone + '">' + esc(label) + '</span>';
    };
    var verBadge = c.verified
        ? '<span class="badge-admin badge-admin--success">Verified</span>'
        : '<span class="badge-admin badge-admin--warning">Unverified</span>';
    var fmtMoney = function (n) {
        return '\u20B1' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var fmtJoined = function (s) {
        if (!s) return 'Not provided';
        var d = new Date(s);
        if (isNaN(d.getTime())) return 'Not provided';
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    };
    var fmtLogin = function (s) {
        if (!s) return 'Never';
        var d = new Date(s);
        if (isNaN(d.getTime())) return 'Never';
        return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    var row = function (label, valueHtml) {
        return '<div class="cd-row"><dt>' + label + '</dt><dd>' + valueHtml + '</dd></div>';
    };
    var section = function (icon, label, rowsHtml) {
        return '<div class="cd-sec"><div class="cd-sec__label"><i class="bi ' + icon + '"></i>' + label + '</div><dl class="cd-rows" style="margin:0;">' + rowsHtml + '</dl></div>';
    };
    var name = c.name || null;
    var email = c.email || null;
    var initials = (name || '').split(' ').map(function (w) { return w[0]; }).join('').toUpperCase().substring(0, 2) || '–';
    var rentalsCount = c.rentals_count ?? c.totalRentals ?? 0;
    var html = '<div class="cd-profile">'
        + '<div class="cd-avatar" aria-hidden="true">' + esc(initials) + '</div>'
        + '<div class="cd-profile__text">'
        + '<div class="cd-profile__name">' + esc(name || 'Not provided') + '</div>'
        + '<div class="cd-profile__email">' + esc(email || 'Not provided') + '</div>'
        + '<div class="cd-profile__badges">' + verBadge + statusBadge(c.status) + '</div>'
        + '</div></div>'
        + section('bi-telephone', 'Contact Information',
            row('Email', esc(email || 'Not provided'))
            + row('Phone', esc(c.phoneNumber || 'Not provided'))
            + row('Student ID', esc(c.studentId || 'Not provided'))
            + row('Address', esc(c.address || 'Not provided')))
        + section('bi-shield-check', 'Account Status',
            row('Verification', '<span class="cd-badges">' + verBadge + '</span>')
            + row('Account Status', '<span class="cd-badges">' + statusBadge(c.status) + '</span>')
            + row('Date Joined', esc(fmtJoined(c.createdAt || c.created_at)))
            + row('Last Login', esc(fmtLogin(c.last_login_at)))
            + (c.blacklistReason ? row('Blacklist Reason', esc(c.blacklistReason)) : ''))
        + section('bi-clock-history', 'Rental Summary',
            row('Total Rentals', '<span class="cd-num">' + esc(rentalsCount) + '</span>')
            + '<dl class="cd-total" style="margin-bottom:0;"><dt>Total Spent</dt><dd>' + esc(fmtMoney(c.totalSpent)) + '</dd></dl>');
    document.getElementById('customerDetailSubtitle').textContent = email || name || '—';
    document.getElementById('customerDetailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('customerDetailModal')).show();
}

/* ─── View Rental Detail ───
   Enterprise layout fed by the same report record (no new bindings): four
   labelled sections plus a final-fee summary card. Every value comes from
   the selected row; missing values render as an em dash. */
function viewRentalDetail(index) {
    var r = _reportData.rental[index];
    if (!r) return;
    var code = r.rentalId || r.id;
    var fmtMoney = function (n) {
        return '\u20B1' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var fmtDate = function (s) {
        if (!s) return '—';
        var d = new Date(s);
        if (isNaN(d.getTime())) return '—';
        return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };
    var fmtDur = function (m) {
        if (m === null || m === undefined || m === '') return '—';
        m = Number(m) || 0;
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    };
    // Accessible badges: dot + text via badge-admin, never colour alone.
    var rentalBadge = function (status) {
        var map = {
            returned: ['success', 'Returned'],
            completed: ['success', 'Completed'],
            active: ['info', 'Active'],
            awaiting_return: ['warning', 'Pending Confirmation'],
            overdue_return: ['warning', 'Pending Confirmation'],
            pending: ['neutral', 'Pending'],
            overdue: ['warning', 'Overdue'],
            expired: ['warning', 'Expired'],
            cancelled: ['neutral', 'Cancelled']
        };
        var tone = (map[status] || [])[0] || 'neutral';
        var label = (map[status] || [])[1] || (status ? status.charAt(0).toUpperCase() + status.slice(1) : '—');
        return '<span class="badge-admin badge-admin--' + tone + '">' + esc(label) + '</span>';
    };
    var payBadge = function (status) {
        if (!status) return '<span class="badge-admin badge-admin--neutral">—</span>';
        if (status === 'paid') return '<span class="badge-admin badge-admin--success">Paid</span>';
        if (status === 'pending') return '<span class="badge-admin badge-admin--warning">Pending</span>';
        if (status === 'refunded') return '<span class="badge-admin badge-admin--info">Refunded</span>';
        return '<span class="badge-admin badge-admin--danger">Unpaid</span>';
    };
    var row = function (label, valueHtml, mono) {
        return '<div class="rd-row"><dt>' + label + '</dt><dd' + (mono ? ' class="rd-mono"' : '') + '>' + valueHtml + '</dd></div>';
    };
    var section = function (icon, label, rowsHtml) {
        return '<div class="rd-sec"><div class="rd-sec__label"><i class="bi ' + icon + '"></i>' + label + '</div><dl class="rd-rows" style="margin:0;">' + rowsHtml + '</dl></div>';
    };
    var riderName = (r.rider && r.rider.name) || r.riderName || null;
    var riderEmail = (r.rider && r.rider.email) || r.riderEmail || null;
    var bikeName = (r.bicycle && r.bicycle.name) || r.bicycleName || null;
    var bikeSerial = (r.bicycle && r.bicycle.serialNumber) || r.bicycleSerial || null;
    // Original booked amount vs. what was actually charged: the late portion
    // is the frozen penalty where present, otherwise any settled uplift.
    var baseFee = Number(r.totalFee || 0);
    var lateFee = Number(r.overdueFee || 0);
    if (!(lateFee > 0) && r.finalFee !== null && r.finalFee !== undefined && Number(r.finalFee) > baseFee) {
        lateFee = Number(r.finalFee) - baseFee;
    }
    lateFee = Math.round(lateFee * 100) / 100;
    var finalFee = (r.finalFee !== null && r.finalFee !== undefined) ? Number(r.finalFee) : baseFee;
    var method = r.paymentMethod ? r.paymentMethod.charAt(0).toUpperCase() + r.paymentMethod.slice(1) : null;
    var html = section('bi-receipt', 'Rental Information',
            row('Rental ID', esc(code), true)
            + row('Status', '<span class="rd-badges">' + rentalBadge(r.status) + '</span>')
            + row('Start Time', esc(fmtDate(r.startTime)))
            + row('End Time', esc(fmtDate(r.endTime)))
            + row('Duration', esc(fmtDur(r.durationMinutes)))
            + (r.notes ? row('Notes', esc(r.notes)) : ''))
        + section('bi-person-circle', 'Rider Information',
            row('Full Name', esc(riderName || '—'))
            + row('Email', esc(riderEmail || '—')))
        + section('bi-bicycle', 'Bicycle Information',
            row('Bicycle Name', esc(bikeName || '—'))
            + row('Serial Number', esc(bikeSerial || '—'), true))
        + section('bi-cash-coin', 'Payment Summary',
            row('Hourly Rate', '<span class="rd-num">' + esc(fmtMoney(r.ratePerHour)) + '</span>')
            + row('Base Fee', '<span class="rd-num">' + esc(fmtMoney(baseFee)) + '</span>')
            + (lateFee > 0 ? row('Late Fees', '<span class="rd-num">' + esc(fmtMoney(lateFee)) + '</span>') : '')
            + row('Payment Method', esc(method || '—'))
            + row('Payment Status', '<span class="rd-badges">' + payBadge(r.paymentStatus) + '</span>')
            + '<dl class="rd-total" style="margin-bottom:0;"><dt>Final Fee</dt><dd>' + esc(fmtMoney(finalFee)) + '</dd></dl>');
    document.getElementById('rentalDetailSubtitle').textContent = String(code);
    document.getElementById('rentalDetailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('rentalDetailModal')).show();
}

/* ─── View Accident Detail ─── */
function viewAccidentDetail(index) {
    var a = _reportData.accident[index];
    if (!a) return;
    var sevClass = 'secondary';
    if (a.severity === 'critical') sevClass = 'danger';
    else if (a.severity === 'major') sevClass = 'warning';
    else if (a.severity === 'moderate') sevClass = 'info';
    else if (a.severity === 'minor') sevClass = 'light text-dark';
    var statusClass = 'secondary';
    if (a.status === 'open') statusClass = 'danger';
    else if (a.status === 'resolved' || a.status === 'closed') statusClass = 'success';
    else if (a.status === 'in_progress') statusClass = 'warning';
    var ts = a.createdAt || a.created_at;
    var tsStr = ts ? new Date(ts).toLocaleString() : '-';
    var loc = formatLocation(a);
    var coords = getLocationCoords(a);
    var impact = a.impactForce ? Number(a.impactForce).toFixed(2) + ' N' : '-';

    var mapBtn = coords
        ? '<a class="btn btn-sm btn-outline-primary mt-2" href="https://www.google.com/maps?q=' + coords.lat + ',' + coords.lng + '" target="_blank" rel="noopener"><i class="bi bi-geo-alt me-1"></i>View on Map</a>'
        : '';

    var html = '<div class="row g-3">'
        + '<div class="col-md-6">'
        + '<div class="d-flex align-items-center mb-3">'
        + '<div style="width:48px;height:48px;border-radius:var(--radius-sm);background:var(--brand-soft);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--brand);"><i class="bi bi-activity"></i></div>'
        + '<div class="ms-3"><div class="fw-bold" style="font-size:1rem;">Accident <code>#' + esc(a.id) + '</code></div>'
        + '<span class="badge bg-' + sevClass + '">' + esc(a.severity || '-') + '</span> '
        + '<span class="badge bg-' + statusClass + '">' + esc(a.status || '-') + '</span></div>'
        + '</div>'
        + '<table class="table table-borderless mb-0" style="font-size:0.9rem;">'
        + '<tr><td class="text-muted" style="width:42%;">Rider</td><td class="fw-semibold">' + esc((a.rider && a.rider.name) || a.reportedBy || '-') + '</td></tr>'
        + '<tr><td class="text-muted">Bicycle</td><td>' + esc((a.bicycle && a.bicycle.name) || a.bicycleId || '-') + '</td></tr>'
        + '<tr><td class="text-muted">Type</td><td>' + esc(a.type || '-') + '</td></tr>'
        + '<tr><td class="text-muted">Date / Time</td><td>' + tsStr + '</td></tr>'
        + '<tr><td class="text-muted">Impact Force</td><td>' + impact + '</td></tr>'
        + '<tr><td class="text-muted">Location</td><td>' + loc + '</td></tr>'
        + '</table>'
        + mapBtn
        + '</div>'
        + '<div class="col-md-6"><table class="table table-borderless mb-0" style="font-size:0.9rem;">'
        + '<tr><td class="text-muted" style="width:38%;">Acknowledged</td><td>' + (a.acknowledged ? '<i class="bi bi-check-circle-fill text-success"></i> Yes' : '<i class="bi bi-x-circle text-muted"></i> No') + '</td></tr>'
        + '<tr><td class="text-muted">Status</td><td><span class="badge bg-' + statusClass + '">' + esc(a.status || '-') + '</span></td></tr>'
        + '<tr><td class="text-muted">Description</td><td>' + esc(a.description || '-') + '</td></tr>'
        + '<tr><td class="text-muted">Action Taken</td><td class="fw-semibold">' + esc(a.actionTaken || '-') + '</td></tr>'
        + (a.warningLevel ? '<tr><td class="text-muted">Warning Level</td><td>' + esc(a.warningLevel) + '</td></tr>' : '')
        + (a.imageUrl ? '<tr><td class="text-muted">Image</td><td><a href="' + esc(a.imageUrl) + '" target="_blank" rel="noopener"><i class="bi bi-image me-1"></i>View</a></td></tr>' : '')
        + '</table></div></div>';

    document.getElementById('accidentDetailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('accidentDetailModal')).show();
}

/* ─── Revenue Table ─── */
function renderRevenueTable(records, page) {
    _reportPage.revenue = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.revenue - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>Period</th><th>Rentals</th><th>Total Revenue</th><th>Avg Revenue</th><th>Duration (min)</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="5" class="text-center text-muted py-4">No revenue data found</td></tr>';
    pageRecords.forEach(function (r) {
        html += '<tr>'
            + '<td class="fw-bold">' + esc(r.period) + '</td>'
            + '<td>' + Number(r.total_rentals || 0).toLocaleString() + '</td>'
            + '<td class="fw-bold" style="color:var(--success);">\u20B1' + Number(r.total_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
            + '<td>\u20B1' + Number(r.avg_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
            + '<td>' + Number(r.total_duration_minutes || 0).toLocaleString() + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('revenue', total, totalPages);
    return html;
}

/* ─── Incident Table ─── */
function renderIncidentTable(records, page) {
    _reportPage.incident = page || 1;
    var total = records.length, totalPages = Math.ceil(total / _pageSize);
    var start = (_reportPage.incident - 1) * _pageSize;
    var pageRecords = records.slice(start, start + _pageSize);
    var html = '<div class="table-responsive"><table class="admin-table"><thead><tr>'
        + '<th>ID</th><th>Type</th><th>Severity</th><th>Bicycle</th><th>Description</th>'
        + '<th>Status</th><th>Acknowledged</th><th>Timestamp</th>'
        + '</tr></thead><tbody>';
    if (pageRecords.length === 0) html += '<tr><td colspan="8" class="text-center text-muted py-4">No incidents found</td></tr>';
    pageRecords.forEach(function (r) {
        var sevClass = 'secondary';
        if (r.severity === 'critical') sevClass = 'danger';
        else if (r.severity === 'major') sevClass = 'warning';
        else if (r.severity === 'moderate') sevClass = 'info';
        else if (r.severity === 'minor') sevClass = 'light text-dark';
        var ts = r.createdAt ? new Date(r.createdAt).toLocaleString() : (r.created_at ? new Date(r.created_at).toLocaleString() : '-');
        html += '<tr>'
            + '<td><code>#' + esc(r.id) + '</code></td>'
            + '<td>' + esc(r.type || '-') + '</td>'
            + '<td><span class="badge bg-' + sevClass + '">' + esc(r.severity || '-') + '</span></td>'
            + '<td>' + esc((r.bicycle && r.bicycle.name) || r.bicycleId || '-') + '</td>'
            + '<td>' + esc(r.description || '-') + '</td>'
            + '<td>' + esc(r.status || '-') + '</td>'
            + '<td>' + (r.acknowledged ? '<i class="bi bi-check-circle-fill text-success"></i> Yes' : '<i class="bi bi-x-circle text-muted"></i> No') + '</td>'
            + '<td>' + ts + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    html += buildPagination('incident', total, totalPages);
    return html;
}

function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }

/* ─── Export ─── */
function exportReport(type, format) {
    var form = document.getElementById(type + 'ReportForm');
    if (!form) { alert('Report form not found.'); return; }
    var params = new URLSearchParams();
    params.append('type', type);
    // Only the visible filters - hidden inputs (e.g. the CSRF token) stay out of the URL.
    form.querySelectorAll('input[name], select[name]').forEach(function (field) {
        if (field.type === 'hidden') return;
        if (field.value) params.append(field.name, field.value);
    });
    window.location.href = _baseUrl + '/export/' + format + '?' + params.toString();
}

/* Runs loadReport only when the on-screen table no longer matches the filters,
   so Print/Export always act on the data the user is actually looking at. */
function ensureFreshResults(type, done) {
    var form = document.getElementById(type + 'ReportForm');
    if (!form) { done(); return; }
    if (_appliedFilters[type] === filterSignature(form)) { done(); return; }
    var run = function () { done(); };
    var p = loadReport(form);
    if (p && typeof p.then === 'function') p.then(run, run); else run();
}

/* ─── Print ─── */
function printReport(type) {
    ensureFreshResults(type, function () {
        doPrintReport(type);
    });
}

function doPrintReport(type) {
    var records = _reportData[type] || [];
    if (records.length === 0) { alert('No data to print for the current filters.'); return; }

    var win = window.open('', '_blank', 'width=1200,height=700');
    var titles = { customer: 'Customer Report', rental: 'Rental Report', bicycle: 'Bicycle Report', theft: 'Theft Report', accident: 'Accident Report', revenue: 'Revenue Report', incident: 'Incidents Report' };
    var title = titles[type] || 'Report';
    var rows = '';
    var headers;

    if (type === 'customer') {
        headers = '<th>Name</th><th>Student ID</th><th>Email</th><th>Phone</th><th>Status</th><th>Verified</th><th>Rentals</th><th>Total Spent</th>';
        records.forEach(function (c) {
            var spent = Number(c.totalSpent || 0);
            rows += '<tr><td>' + esc(c.name) + '</td><td>' + esc(c.studentId || '-') + '</td><td>' + esc(c.email) + '</td>'
                + '<td>' + esc(c.phoneNumber || '-') + '</td><td>' + esc(c.status) + '</td><td>' + (c.verified ? 'Yes' : 'No') + '</td>'
                + '<td>' + (c.rentals_count ?? c.totalRentals ?? 0) + '</td>'
                + '<td>\u20B1' + spent.toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td></tr>';
        });
    } else if (type === 'rental') {
        headers = '<th>Rental ID</th><th>Rider</th><th>Bicycle</th><th>Start</th><th>End</th><th>Duration</th><th>Rate/Hr</th><th>Fee</th><th>Payment</th><th>Pay Status</th><th>Status</th>';
        records.forEach(function (r) {
            var dur = r.durationMinutes ? Math.floor(r.durationMinutes / 60) + 'h ' + (r.durationMinutes % 60) + 'm' : '-';
            rows += '<tr><td>' + esc(r.rentalId || r.id) + '</td><td>' + esc((r.rider && r.rider.name) || r.riderName || '-') + '</td>'
                + '<td>' + esc((r.bicycle && r.bicycle.name) || r.bicycleName || '-') + '</td>'
                + '<td>' + (r.startTime ? new Date(r.startTime).toLocaleString() : '-') + '</td>'
                + '<td>' + (r.endTime ? new Date(r.endTime).toLocaleString() : '-') + '</td>'
                + '<td>' + dur + '</td>'
                + '<td>\u20B1' + Number(r.ratePerHour || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
                + '<td>\u20B1' + Number(r.totalFee || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
                + '<td>' + esc(r.paymentMethod || '-') + '</td>'
                + '<td>' + esc(r.paymentStatus || '-') + '</td>'
                + '<td>' + esc(r.status) + '</td></tr>';
        });
    } else if (type === 'bicycle') {
        headers = '<th>Bicycle</th><th>Model</th><th>Status</th><th>Battery</th><th>Total Rentals</th><th>Total Distance</th><th>Total Revenue</th><th>Condition</th>';
        records.forEach(function (b) {
            rows += '<tr><td>' + esc(b.name || '-') + '</td><td>' + esc(b.model || '-') + '</td>'
                + '<td>' + esc(b.status || '-') + '</td>'
                + '<td>' + ((b.batteryLevel !== null && b.batteryLevel !== undefined) ? b.batteryLevel + '%' : '-') + '</td>'
                + '<td>' + (b.totalRentals || 0) + '</td>'
                + '<td>' + (b.totalDistance ? Number(b.totalDistance).toFixed(2) + ' km' : '0.00 km') + '</td>'
                + '<td>\u20B1' + (b.totalRevenue ? Number(b.totalRevenue).toFixed(2) : '0.00') + '</td>'
                + '<td>' + esc(b.condition || '-') + '</td></tr>';
        });
    } else if (type === 'theft') {
        headers = '<th>Theft ID</th><th>Bicycle</th><th>Severity</th><th>Description</th><th>Location</th><th>Status</th><th>Acknowledged</th><th>Timestamp</th>';
        records.forEach(function (r) {
            var ts = r.createdAt || r.created_at;
            rows += '<tr><td>#' + esc(r.id) + '</td><td>' + esc((r.bicycle && r.bicycle.name) || r.bicycleId || '-') + '</td>'
                + '<td>' + esc(r.severity || '-') + '</td>'
                + '<td>' + esc(r.description || '-') + '</td>'
                + '<td>' + esc(formatLocation(r)) + '</td>'
                + '<td>' + esc(r.status || '-') + '</td>'
                + '<td>' + (r.acknowledged ? 'Yes' : 'No') + '</td>'
                + '<td>' + (ts ? new Date(ts).toLocaleString() : '-') + '</td></tr>';
        });
    } else if (type === 'accident') {
        headers = '<th>Accident ID</th><th>Rider</th><th>Bicycle</th><th>Location</th><th>Date/Time</th><th>Severity</th><th>Status</th><th>Acknowledged</th><th>Action Taken</th>';
        records.forEach(function (a) {
            var ts = a.createdAt || a.created_at;
            rows += '<tr><td>#' + esc(a.id) + '</td><td>' + esc((a.rider && a.rider.name) || a.reportedBy || '-') + '</td>'
                + '<td>' + esc((a.bicycle && a.bicycle.name) || a.bicycleId || '-') + '</td>'
                + '<td>' + esc(formatLocation(a)) + '</td>'
                + '<td>' + (ts ? new Date(ts).toLocaleString() : '-') + '</td>'
                + '<td>' + esc(a.severity || '-') + '</td>'
                + '<td>' + esc(a.status || '-') + '</td>'
                + '<td>' + (a.acknowledged ? 'Yes' : 'No') + '</td>'
                + '<td>' + esc(a.actionTaken || '-') + '</td></tr>';
        });
    } else if (type === 'revenue') {
        headers = '<th>Period</th><th>Rentals</th><th>Total Revenue</th><th>Avg Revenue</th><th>Duration (min)</th>';
        records.forEach(function (r) {
            rows += '<tr><td>' + esc(r.period) + '</td><td>' + Number(r.total_rentals || 0) + '</td>'
                + '<td>\u20B1' + Number(r.total_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
                + '<td>\u20B1' + Number(r.avg_revenue || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>'
                + '<td>' + Number(r.total_duration_minutes || 0) + '</td></tr>';
        });
    }

    win.document.write('<!DOCTYPE html><html><head><title>Pedalya - ' + title + '</title>'
        + '<style>body{font-family:Arial,sans-serif;font-size:11px;color:#222;margin:20px;}'
        + 'h1{font-size:18px;color:#14532d;margin:0 0 4px;}'
        + '.muted{color:#666;font-size:11px;margin-bottom:12px;}'
        + 'table{width:100%;border-collapse:collapse;margin-top:10px;}'
        + 'th{background:#14532d;color:#fff;padding:5px 6px;text-align:left;font-size:10px;}'
        + 'td{padding:4px 6px;border:1px solid #ddd;font-size:10px;}'
        + 'tr:nth-child(even) td{background:#f9fafb;}'
        + '@media print{body{margin:10px;}}</style></head><body>'
        + '<h1>Pedalya Bicycle Rental &mdash; ' + title + '</h1>'
        + '<div class="muted">Generated: ' + new Date().toLocaleString() + ' | Total: ' + records.length + ' records</div>'
        + '<table><thead><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table>'
        + '<div style="margin-top:16px;text-align:center;color:#888;font-size:9px;">Pedalya IoT Bicycle Rental Management System &bull; ' + new Date().getFullYear() + '</div>'
        + '</body></html>');
    win.document.close();
    win.focus();
    setTimeout(function () { win.print(); }, 500);
}

/* ─── Clear ─── */
function clearResults() {
    document.getElementById('resultsCard').style.display = 'none';
    document.getElementById('reportResults').innerHTML = '';
    document.getElementById('resultCount').textContent = '0';
    _reportData = { customer: [], rental: [], bicycle: [], theft: [], accident: [], revenue: [], incident: [] };
    _reportPage = { customer: 1, rental: 1, bicycle: 1, theft: 1, accident: 1, revenue: 1, incident: 1 };
    _appliedFilters = {};
    _summaryHtml = {};
}

/* The table auto-applies on filter change, so a reset must re-run it too. */
function reloadActiveReport() {
    if (typeof window.applyActiveReportFilters === 'function') window.applyActiveReportFilters();
}

function clearCustomerFilters() {
    document.getElementById('customerSearch').value = '';
    document.getElementById('customerDateFrom').value = '';
    document.getElementById('customerDateTo').value = '';
    document.getElementById('customerStatus').value = '';
    document.getElementById('customerVerified').value = '';
    reloadActiveReport();
}

function clearRentalFilters() {
    document.getElementById('rentalSearch').value = '';
    document.getElementById('rentalDateFrom').value = '{{ now()->subDays(30)->format("Y-m-d") }}';
    document.getElementById('rentalDateTo').value = '{{ now()->format("Y-m-d") }}';
    document.getElementById('rentalStatus').value = '';
    document.getElementById('rentalPaymentStatus').value = '';
    document.getElementById('rentalBicycle').value = '';
    document.getElementById('rentalRider').value = '';
    reloadActiveReport();
}

function clearBicycleFilters() {
    var s = document.getElementById('bicycleSearch'); if (s) s.value = '';
    var st = document.getElementById('bicycleStatus'); if (st) st.value = '';
    reloadActiveReport();
}

function clearTheftFilters() {
    var s = document.getElementById('theftSearch'); if (s) s.value = '';
    document.getElementById('theftDateFrom').value = '{{ now()->subDays(30)->format("Y-m-d") }}';
    document.getElementById('theftDateTo').value = '{{ now()->format("Y-m-d") }}';
    document.getElementById('theftStatus').value = '';
    document.getElementById('theftBicycle').value = '';
    reloadActiveReport();
}

function clearAccidentFilters() {
    document.getElementById('accidentSearch').value = '';
    document.getElementById('accidentDateFrom').value = '{{ now()->subDays(30)->format("Y-m-d") }}';
    document.getElementById('accidentDateTo').value = '{{ now()->format("Y-m-d") }}';
    document.getElementById('accidentSeverity').value = '';
    document.getElementById('accidentStatus').value = '';
    document.getElementById('accidentBicycle').value = '';
    document.getElementById('accidentRider').value = '';
    var typeEl = document.getElementById('accidentType');
    if (typeEl) typeEl.value = '';
    reloadActiveReport();
}

/* ─── Auto-load + live filtering ───
   Every report tab renders its table as soon as the page loads and re-applies
   whenever a filter changes, so there is no separate generate step and the
   actions above always act on what is currently on screen. */
(function () {
    var AUTO_TABS = ['customer', 'rental', 'bicycle', 'theft', 'accident', 'revenue'];
    var form = document.getElementById(_reportType + 'ReportForm');
    if (!form || AUTO_TABS.indexOf(_reportType) === -1) return;

    var debounceTimer = null;
    var applyNow = function () {
        if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
        loadReport(form);
    };
    var applySoon = function () {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyNow, 450);
    };

    form.querySelectorAll('input[name], select[name]').forEach(function (field) {
        if (field.type === 'hidden') return;
        if (field.tagName === 'SELECT' || field.type === 'date') {
            field.addEventListener('change', applyNow);
        } else {
            field.addEventListener('input', applySoon);
        }
    });

    // Lets "Clear Filters" reload the table instead of leaving stale rows behind.
    if (typeof window !== 'undefined') window.applyActiveReportFilters = applyNow;

    applyNow();
})();
</script>
@endsection
