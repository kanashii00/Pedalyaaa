@extends('layouts.admin')

@section('title', 'Audit Log')

@section('page-header')
    <h1>{{ ($tab ?? 'audit') === 'activity' ? 'Activity Logs' : 'Audit Log' }}</h1>
    <p>{{ ($tab ?? 'audit') === 'activity' ? 'Routine operational events' : 'Security-sensitive changes and accountability' }}</p>
@endsection

@section('actions')
<button type="button" class="btn-admin btn-admin--secondary" onclick="clearFilters()">
    <i class="bi bi-x-lg me-1"></i>Clear Filters
</button>
@endsection

@section('styles')
<style>
    /* ── Audit Log: enterprise table + detail modal (theme-aware) ── */
    .audit-filter-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--text-3); margin-bottom: 5px; }
    .audit-filter-field select, .audit-filter-field input { min-width: 150px; }
    .admin-table--audit { table-layout: fixed; width: 100%; font-size: 13px; }
    .admin-table--audit .col-ts { width: 17%; }
    .admin-table--audit .col-action { width: 21%; }
    .admin-table--audit .col-user { width: 20%; }
    .admin-table--audit .col-details { width: 42%; }
    .admin-table--audit tbody td { padding: 12px 16px; vertical-align: middle; }
    .audit-ts__date { font-weight: 600; color: var(--text-1); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .audit-ts__time { font-size: 12px; color: var(--text-3); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .audit-user { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .audit-avatar {
        width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--brand-soft); color: var(--brand-strong);
        font-size: 12px; font-weight: 700;
    }
    .audit-user__name { font-weight: 600; color: var(--text-1); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .audit-user__name.is-system { color: var(--text-3); font-weight: 500; }
    .audit-summary { font-size: 12.5px; color: var(--text-2); line-height: 1.5; overflow-wrap: anywhere; }
    .audit-row-actions { margin-top: 7px; }
    /* Audit detail dialog: one cohesive navy surface. Bootstrap paints
       .modal-content white by default, which left this dialog split into a
       dark header over a white body and footer — the same bleed the Rental
       Details modal had. Every region binds to dashboard tokens instead, so
       light and dark themes both read as a single surface. */
    .modal-content.audit-detail {
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
    .audit-detail__head { background: transparent; border-bottom: 1px solid var(--border-subtle); padding: 14px 18px; gap: 12px; align-items: center; }
    .audit-detail__icon {
        display: inline-flex; align-items: center; justify-content: center;
        width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
        background: var(--brand-soft); color: var(--brand-strong); font-size: 17px;
    }
    .audit-detail__titles { flex: 1; min-width: 0; }
    .audit-detail__titles .modal-title { font-size: 15px; font-weight: 700; color: var(--text-1); line-height: 1.3; }
    .audit-detail__subtitle { font-size: 12px; color: var(--text-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .audit-detail__close {
        flex-shrink: 0; width: 32px; height: 32px;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid transparent; border-radius: 9px;
        background: none; color: var(--text-3); font-size: 14px;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .audit-detail__close:hover { background: var(--surface-3); border-color: var(--border-subtle); color: var(--text-1); }
    .audit-detail__close:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
    .audit-detail .modal-body { padding: 18px; color: var(--text-1); background: transparent; }
    /* Flex-lock the dialog regions so only the body ever scrolls: the header
       and footer can neither shrink nor be overlapped, the body keeps its
       full top/bottom padding, and the first and last rows are never clipped
       regardless of Bootstrap load order or viewport height. */
    .modal-content.audit-detail { display: flex; flex-direction: column; }
    .modal-content.audit-detail > .modal-header,
    .modal-content.audit-detail > .modal-footer { flex-shrink: 0; }
    .modal-content.audit-detail > .modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    .audit-detail__foot {
        background: transparent; border-top: 1px solid var(--border-subtle);
        padding: 13px 18px; align-items: center;
    }
    .ad-sec { margin-bottom: 16px; }
    .ad-sec:last-child { margin-bottom: 0; }
    .ad-sec__label {
        display: flex; align-items: center; gap: 7px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
        color: var(--text-3); margin-bottom: 8px;
    }
    .ad-sec__label i { font-size: 13px; color: var(--brand-strong); }
    /* Panels sit one surface above the dialog (surface-2) with blue-gray
       dividers; labels stay muted slate, values stay near-white. */
    .ad-rows { border: 1px solid var(--border-subtle); border-radius: 10px; overflow: hidden; background: var(--surface-2); }
    .ad-row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 10px 14px; font-size: 13px; line-height: 1.45; }
    .ad-row + .ad-row { border-top: 1px solid var(--border-subtle); }
    .ad-row dt { margin: 0; color: var(--text-3); font-weight: 500; flex-shrink: 0; }
    .ad-row dd { margin: 0; color: var(--text-1); font-weight: 600; text-align: right; min-width: 0; overflow-wrap: anywhere; }
    .ad-row dd.ad-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
    .ad-row dd.ad-num { font-variant-numeric: tabular-nums; }
    @media (max-width: 767.98px) {
        .audit-filter-field, .audit-filter-field select, .audit-filter-field input { width: 100%; min-width: 0; }
        .audit-detail__head { padding: 12px 14px; }
        .audit-detail .modal-body { padding: 14px; }
        .audit-detail__foot { padding: 11px 14px; }
        .ad-row { padding: 9px 12px; font-size: 12.5px; }
    }
</style>
@endsection

@section('content')
@php
    // Structured one-line summaries and modal datasets, derived only from
    // keys the backend actually recorded — never fabricated.
    $auditMoneyHint = ['fee', 'amount', 'total', 'penalty', 'price', 'revenue', 'cost'];
    $auditPick = function (array $details, array $keys) {
        foreach ($keys as $k) {
            if (array_key_exists($k, $details) && is_scalar($details[$k]) && (string) $details[$k] !== '') {
                return $details[$k];
            }
        }
        return null;
    };
    $auditMoney = function ($value) {
        return is_numeric($value) ? '₱' . number_format((float) $value, 2) : $value;
    };
    $auditSummary = function (array $details) use ($auditPick, $auditMoney, $auditMoneyHint) {
        $parts = [];
        foreach (['rentalId', 'rental_id'] as $k) {
            if (($v = $auditPick($details, [$k])) !== null) { $parts[] = 'Rental ' . $v; break; }
        }
        foreach (['bicycleSerial', 'bicycle_serial', 'bicycleId', 'bicycle_id'] as $k) {
            if (($v = $auditPick($details, [$k])) !== null) { $parts[] = 'Bike ' . $v; break; }
        }
        foreach (['condition', 'new_status', 'status', 'result'] as $k) {
            if (($v = $auditPick($details, [$k])) !== null) { $parts[] = ucfirst(str_replace('_', ' ', (string) $v)); break; }
        }
        foreach ($details as $k => $v) {
            if (count($parts) >= 3) break;
            $lower = strtolower((string) $k);
            $isMoney = false;
            foreach ($auditMoneyHint as $hint) {
                if (str_contains($lower, $hint)) { $isMoney = true; break; }
            }
            if ($isMoney && is_numeric($v)) { $parts[] = $auditMoney($v); break; }
        }
        return implode(' · ', array_slice($parts, 0, 3));
    };
    $auditResource = function (array $details) {
        foreach ([
            ['Rental', ['rentalId', 'rental_id']],
            ['Bicycle', ['bicycleSerial', 'bicycle_serial', 'bicycleId', 'bicycle_id']],
            ['Payment', ['paymentReference', 'payment_reference', 'paymentId', 'payment_id']],
            ['User', ['userId', 'user_id', 'userEmail', 'user_email', 'riderId', 'rider_id', 'riderEmail', 'rider_email']],
            ['Geofence', ['geofenceId', 'geofence_id']],
            ['Maintenance', ['maintenanceId', 'maintenance_id']],
            ['Incident', ['accidentId', 'accident_id', 'theftId', 'theft_id', 'alertId', 'alert_id']],
            ['Settings', ['keys', 'key']],
        ] as [$resource, $keys]) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $details) && is_scalar($details[$k]) && (string) $details[$k] !== '') {
                    $v = $details[$k];
                    return [$resource, is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v, $k];
                }
            }
        }
        return ['Not available', 'Not available', null];
    };
    $detailMap = [];
@endphp
<div class="admin-table-wrap">
    <div class="admin-tabs" role="tablist" aria-label="Log categories" style="padding: 14px 18px 0; margin-bottom: 0;">
        <a href="{{ route('admin.audit-log.index') }}" role="tab" aria-selected="{{ ($tab ?? 'audit') === 'audit' ? 'true' : 'false' }}" class="tab {{ ($tab ?? 'audit') === 'audit' ? 'active' : '' }}" data-tab="audit">Audit Logs</a>
        <a href="{{ route('admin.audit-log.index', ['tab' => 'activity']) }}" role="tab" aria-selected="{{ ($tab ?? 'audit') === 'activity' ? 'true' : 'false' }}" class="tab {{ ($tab ?? 'audit') === 'activity' ? 'active' : '' }}" data-tab="activity">Activity Logs</a>
    </div>
    <div class="admin-table-toolbar">
        <form method="GET" action="{{ route('admin.audit-log.index') }}" id="auditFilterForm" class="d-flex gap-3 align-items-end flex-wrap">
            @if(($tab ?? 'audit') === 'activity')
                <input type="hidden" name="tab" value="activity">
            @endif
            <div class="audit-filter-field">
                <label class="audit-filter-label" for="auditAction">Action Type</label>
                <select class="form-select form-select-sm" name="action" id="auditAction">
                    <option value="">All Actions</option>
                    @foreach(($actionOptions ?? []) as $actionOption)
                        <option value="{{ $actionOption }}" {{ request('action') == $actionOption ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $actionOption)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="audit-filter-field">
                <label class="audit-filter-label" for="auditUser">User</label>
                <select class="form-select form-select-sm" name="userId" id="auditUser">
                    <option value="">All Users</option>
                    @foreach($users ?? [] as $user)
                        <option value="{{ $user->id }}" {{ request('userId') == $user->id ? 'selected' : '' }}>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="audit-filter-field">
                <label class="audit-filter-label" for="auditFrom">Start Date</label>
                <input type="date" class="form-control form-control-sm" name="date_from" id="auditFrom" value="{{ request('date_from') }}">
            </div>
            <div class="audit-filter-field">
                <label class="audit-filter-label" for="auditTo">End Date</label>
                <input type="date" class="form-control form-control-sm" name="date_to" id="auditTo" value="{{ request('date_to') }}">
            </div>
            <button type="submit" class="btn-admin btn-admin--primary btn-admin--sm">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="admin-table admin-table--audit">
            <colgroup>
                <col class="col-ts"><col class="col-action"><col class="col-user"><col class="col-details">
            </colgroup>
            <thead>
                <tr>
                    <th class="sortable">Timestamp <span class="sort-ind"></span></th>
                    <th class="sortable">Action / Event <span class="sort-ind"></span></th>
                    <th class="sortable">Performed By <span class="sort-ind"></span></th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    @php
                        $actionBadgeType = match (true) {
                            in_array($log->action, ['created', 'rental_started', 'rental_ended', 'rental_ended_by_admin', 'rental_approved', 'rental_return_processed', 'rental_marked_paid', 'gcash_payment_verified', 'payment', 'paid', 'rider_created', 'login_success', 'lock_command_executed'], true) => 'success',
                            in_array($log->action, ['updated', 'rental_deadline_set', 'rider_verification_approved'], true) => 'info',
                            in_array($log->action, ['deleted', 'accident', 'accident_acknowledged', 'theft_alert_acknowledged', 'login_failed', 'login_blocked', 'lock_command_failed'], true) || str_starts_with($log->action, 'theft') => 'danger',
                            in_array($log->action, ['maintenance', 'maintenance_created', 'logout', 'expired', 'overdue'], true) || str_starts_with($log->action, 'overdue') => 'warning',
                            in_array($log->action, ['settings_changed', 'system_settings_updated'], true) => 'danger',
                            default => 'neutral',
                        };
                        $actionLabel = ucfirst(str_replace('_', ' ', $log->action));
                        $logDetails = is_array($log->details) ? $log->details : [];
                        $summary = $auditSummary($logDetails);
                        [$resource, $resourceId, $resourceKey] = $auditResource($logDetails);
                        // The outcome is displayed exactly once (Event Information):
                        // remember which key supplied it so metadata skips it.
                        $outcomeKey = null;
                        $outcomeRaw = null;
                        foreach (['new_status', 'result', 'outcome', 'status'] as $outcomeCandidate) {
                            if (array_key_exists($outcomeCandidate, $logDetails)) {
                                $outcomeKey = $outcomeCandidate;
                                $outcomeRaw = $logDetails[$outcomeCandidate];
                                break;
                            }
                        }
                        // Display the stored outcome verbatim: the modal reports
                        // the actual database record, never a reformatted copy.
                        $outcome = (is_scalar($outcomeRaw) && (string) $outcomeRaw !== '')
                            ? (string) $outcomeRaw
                            : 'Not available';
                        // Never mislabel the actor: a real user wins; otherwise the
                        // attempted address recorded on the event (e.g. failed
                        // logins); only genuine system processes read "System".
                        $actorName = $log->user->name ?? null;
                        $actorEmail = null;
                        if ($actorName === null) {
                            $maybeEmail = $logDetails['email'] ?? $logDetails['actor_email'] ?? null;
                            if (is_string($maybeEmail) && $maybeEmail !== '') {
                                $actorEmail = $maybeEmail;
                            }
                        }
                        $actorDisplay = $actorName ?? $actorEmail ?? 'System';
                        $actorRole = $log->user->role ?? null;
                        // Authentication events carry no affected entity: say so
                        // plainly instead of inventing one.
                        $hasNoResource = in_array($log->action, ['login_success', 'login_failed', 'login_blocked', 'logout'], true)
                            && $resource === 'Not available';
                        // Before/after pairs: prefer the explicit `changes` map
                        // recorders write, then legacy previous_*/new_* shapes.
                        // Keys consumed here are skipped in metadata below so
                        // each value is displayed exactly once.
                        $changeList = [];
                        $consumedChangeKeys = [];
                        $pushChange = function ($field, $from, $to, array $sourceKeys = []) use (&$changeList, &$consumedChangeKeys) {
                            if (!is_scalar($from) && $from !== null) return;
                            if (!is_scalar($to) && $to !== null) return;
                            $changeList[] = [
                                'field' => $field,
                                'from' => $from === null || $from === '' ? null : (string) $from,
                                'to' => $to === null || $to === '' ? null : (string) $to,
                            ];
                            foreach ($sourceKeys as $sourceKey) {
                                $consumedChangeKeys[] = $sourceKey;
                            }
                        };
                        if (isset($logDetails['changes']) && is_array($logDetails['changes'])) {
                            foreach ($logDetails['changes'] as $field => $pair) {
                                if (is_array($pair) && array_key_exists('from', $pair) && array_key_exists('to', $pair)) {
                                    $pushChange((string) $field, $pair['from'], $pair['to']);
                                }
                            }
                        }
                        if ($changeList === []) {
                            $legacyPairs = [
                                ['previous_status', ['new_status', 'status']],
                                ['previous_deadline', ['deadline']],
                                ['previous_lock_status', ['lockStatus', 'lock_status']],
                            ];
                            foreach ($legacyPairs as [$fromKey, $toKeys]) {
                                if (!array_key_exists($fromKey, $logDetails)) continue;
                                foreach ((array) $toKeys as $toKey) {
                                    if (array_key_exists($toKey, $logDetails)) {
                                        $pushChange(preg_replace('/^previous_/', '', $fromKey), $logDetails[$fromKey], $logDetails[$toKey], [$fromKey, $toKey]);
                                        break;
                                    }
                                }
                            }
                        }
                        // Keys with a dedicated home elsewhere never repeat in metadata.
                        // Email/role repeat only when they match what the Actor
                        // section already shows; anything else stays as context.
                        $shownEmail = $actorEmail ?? ($log->user->email ?? null);
                        $shownRole = $actorRole !== null ? ucfirst($actorRole) : null;
                        $metaSkip = ['outcome', 'changes', 'ip_address', 'request_id', 'http_method', 'http_path'];
                        if ($outcomeKey !== null) {
                            $metaSkip[] = $outcomeKey;
                        }
                        foreach ($consumedChangeKeys as $consumedKey) {
                            $metaSkip[] = $consumedKey;
                        }
                        $metaRows = [];
                        foreach ($logDetails as $metaKey => $metaValue) {
                            if ($metaKey === $resourceKey) continue;
                            if (in_array($metaKey, $metaSkip, true)) continue;
                            if (in_array($metaKey, ['email', 'actor_email'], true)
                                && $shownEmail !== null && is_scalar($metaValue)
                                && strcasecmp((string) $metaValue, (string) $shownEmail) === 0) continue;
                            if ($metaKey === 'role'
                                && $shownRole !== null && is_scalar($metaValue)
                                && strcasecmp((string) $metaValue, (string) $shownRole) === 0) continue;
                            if (is_scalar($metaValue) || $metaValue === null) {
                                $metaRows[$metaKey] = $metaValue;
                            } elseif (is_array($metaValue)) {
                                $flat = [];
                                array_walk_recursive($metaValue, function ($leaf) use (&$flat) {
                                    if (is_scalar($leaf) && (string) $leaf !== '') $flat[] = (string) $leaf;
                                });
                                $metaRows[$metaKey] = $flat !== [] ? implode(', ', array_slice($flat, 0, 8)) . (count($flat) > 8 ? ' …' : '') : 'Not available';
                            }
                        }
                        $detailMap[$log->id] = [
                            'title' => $actionLabel,
                            'timestamp' => ($log->timestamp ?? $log->created_at)->format('M d, Y h:i A'),
                            'actor' => $actorDisplay,
                            'email' => $actorEmail ?? ($log->user->email ?? null),
                            'role' => $actorRole !== null ? ucfirst($actorRole) : null,
                            'resource' => $hasNoResource ? 'No specific resource' : $resource,
                            'resourceId' => $hasNoResource ? 'Not applicable' : $resourceId,
                            'outcome' => $outcome,
                            'changes' => $changeList,
                            'ip' => $logDetails['ip_address'] ?? null,
                            'requestId' => $logDetails['request_id'] ?? null,
                            'http' => isset($logDetails['http_method'], $logDetails['http_path'])
                                ? $logDetails['http_method'] . ' ' . $logDetails['http_path']
                                : null,
                            'meta' => $metaRows,
                            'raw' => $logDetails,
                        ];
                    @endphp
                    <tr>
                        <td data-label="Timestamp">
                            <div class="audit-ts__date">{{ ($log->timestamp ?? $log->created_at)->format('M d, Y') }}</div>
                            <div class="audit-ts__time">{{ ($log->timestamp ?? $log->created_at)->format('h:i:s A') }}</div>
                        </td>
                        <td data-label="Action / Event"><x-admin.badge :type="$actionBadgeType" :label="$actionLabel"/></td>
                        <td data-label="Performed By">
                            <div class="audit-user">
                                <span class="audit-avatar" aria-hidden="true">{{ $actorName ? strtoupper(mb_substr($actorName, 0, 1)) : ($actorEmail ? strtoupper(mb_substr($actorEmail, 0, 1)) : '–') }}</span>
                                <span class="audit-user__name {{ $actorName ? '' : 'is-system' }}">{{ $actorDisplay }}</span>
                            </div>
                        </td>
                        <td data-label="Details">
                            @if($summary !== '')
                                <div class="audit-summary">{{ $summary }}</div>
                            @else
                                <div class="audit-summary text-muted">No additional details</div>
                            @endif
                            @if($logDetails !== [])
                                <div class="audit-row-actions">
                                    <button class="btn-admin btn-admin--soft btn-admin--sm" type="button"
                                        onclick="openAuditDetail({{ $log->id }})"
                                        aria-haspopup="dialog"
                                        aria-label="View details for {{ $actionLabel }} event">
                                        <i class="bi bi-eye me-1"></i>View Details
                                    </button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-admin.empty-state icon="bi-journal-text" title="No audit log entries found" message="System activities will appear here as they happen."/>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(method_exists($logs, 'links'))
        <div class="admin-table-foot">
            <span class="admin-table-foot__info">
                @if($logs->total() > 0)
                    Showing {{ $logs->firstItem() }}&ndash;{{ $logs->lastItem() }} of {{ $logs->total() }} records
                @else
                    Showing 0 records
                @endif
            </span>
            <nav class="admin-pagination" aria-label="Audit log pages">
                @if($logs->onFirstPage())
                    <span class="admin-pagination__item is-disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>
                @else
                    <a class="admin-pagination__item" href="{{ $logs->previousPageUrl() }}" rel="prev"><i class="bi bi-chevron-left"></i> Previous</a>
                @endif
                @for($page = 1; $page <= $logs->lastPage(); $page++)
                    @if($page === $logs->currentPage())
                        <span class="admin-pagination__item is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="admin-pagination__item" href="{{ $logs->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor
                @if($logs->hasMorePages())
                    <a class="admin-pagination__item" href="{{ $logs->nextPageUrl() }}" rel="next">Next <i class="bi bi-chevron-right"></i></a>
                @else
                    <span class="admin-pagination__item is-disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>
                @endif
            </nav>
        </div>
    @endif
</div>

<!-- Audit Event Details Modal: compact dark-theme enterprise layout. -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content audit-detail">
            <div class="modal-header audit-detail__head">
                <span class="audit-detail__icon" aria-hidden="true"><i class="bi bi-journal-text"></i></span>
                <div class="audit-detail__titles">
                    <h6 class="modal-title" id="auditDetailTitle">Event Details</h6>
                    <div class="audit-detail__subtitle" id="auditDetailSubtitle">—</div>
                </div>
                <button type="button" class="audit-detail__close" data-bs-dismiss="modal" aria-label="Close event details"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body" id="auditDetailBody"></div>
            <div class="modal-footer audit-detail__foot">
                <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
var __auditDetails = @json($detailMap ?? []);
function auditEsc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s === null || s === undefined ? '' : String(s))); return d.innerHTML; }
function auditRow(label, valueHtml, mono) {
    return '<div class="ad-row"><dt>' + label + '</dt><dd' + (mono ? ' class="ad-mono"' : '') + '>' + valueHtml + '</dd></div>';
}
function auditSection(icon, label, rowsHtml) {
    return '<div class="ad-sec"><div class="ad-sec__label"><i class="bi ' + icon + '"></i>' + label + '</div><dl class="ad-rows" style="margin:0;">' + rowsHtml + '</dl></div>';
}
function openAuditDetail(id) {
    var d = __auditDetails[id];
    if (!d) return;
    document.getElementById('auditDetailTitle').textContent = d.title || 'Event Details';
    document.getElementById('auditDetailSubtitle').textContent = d.timestamp || AUDIT_MISSING;
    var changeRows = '';
    (d.changes || []).forEach(function (c) {
        var from = (c.from === null || c.from === undefined || c.from === '') ? AUDIT_MISSING : String(c.from);
        var to = (c.to === null || c.to === undefined || c.to === '') ? AUDIT_MISSING : String(c.to);
        var field = c.field ? c.field.charAt(0).toUpperCase() + c.field.slice(1).replace(/[_-]+/g, ' ') : AUDIT_MISSING;
        changeRows += '<div class="ad-row"><dt>' + auditEsc(field) + '</dt><dd>'
            + auditEsc(from) + ' &rarr; ' + auditEsc(to) + '</dd></div>';
    });
    var metaRows = '';
    Object.keys(d.meta || {}).forEach(function (key) {
        var value = d.meta[key];
        var label = key.charAt(0).toUpperCase() + key.slice(1).replace(/[_-]+/g, ' ');
        var money = auditMoneyFor(key, value);
        var display = money !== null ? money
            : ((value === null || value === undefined || value === '') ? AUDIT_MISSING : String(value));
        metaRows += auditRow(auditEsc(label), auditEsc(display), /^[₱\d]/.test(display));
    });
    var securityRows = auditRow('IP Address', auditEsc(d.ip || AUDIT_MISSING), true)
        + auditRow('Request ID', auditEsc(d.requestId || AUDIT_MISSING), true);
    if (d.http) {
        securityRows += auditRow('Request', auditEsc(d.http), true);
    }
    var html = auditSection('bi-info-circle', 'Event Information',
            auditRow('Event', auditEsc(d.title || AUDIT_MISSING))
            + auditRow('Timestamp', auditEsc(d.timestamp || AUDIT_MISSING))
            + auditRow('Outcome', auditEsc(d.outcome || AUDIT_MISSING)))
        + auditSection('bi-person-circle', 'Actor',
            auditRow('Name', auditEsc(d.actor || AUDIT_MISSING))
            + auditRow('Email', auditEsc(d.email || AUDIT_MISSING))
            + auditRow('Role', auditEsc(d.role || AUDIT_MISSING)))
        + auditSection('bi-diagram-3', 'Affected Resource',
            auditRow('Resource', auditEsc(d.resource || AUDIT_MISSING))
            + auditRow('Resource ID', auditEsc(d.resourceId || AUDIT_MISSING), true))
        + (changeRows
            ? auditSection('bi-arrow-left-right', 'Change Details', changeRows)
            : '')
        + auditSection('bi-shield-check', 'Security Context', securityRows)
        + (metaRows
            ? auditSection('bi-list-ul', 'Event Metadata', metaRows)
            : auditSection('bi-list-ul', 'Event Metadata', auditRow('Details', 'No additional metadata recorded.')));
    document.getElementById('auditDetailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('auditDetailModal')).show();
}
/* Human-readable event view only: no raw technical payload is rendered or
   reachable from this dialog. Missing information reads "Not available";
   money-like metadata formats as pesos, mirroring the table summary. */
var AUDIT_MISSING = 'Not available';
function auditMoneyFor(key, value) {
    if (value === null || value === undefined || value === '') return null;
    if (!/fee|amount|total|penalty|price|revenue|cost/i.test(key)) return null;
    var n = Number(value);
    if (!isFinite(n)) return null;
    return '₱' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function clearFilters() {
    @if(($tab ?? 'audit') === 'activity')
        window.location.href = '{{ route("admin.audit-log.index", ["tab" => "activity"]) }}';
    @else
        window.location.href = '{{ route("admin.audit-log.index") }}';
    @endif
}
</script>
@endsection
