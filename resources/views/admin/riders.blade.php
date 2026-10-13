@extends('layouts.admin')

@section('title', 'Rider Management')

@section('page-header')
    <h1>Riders</h1>
    <p>Manage registered riders and verifications</p>
@endsection

@section('content')
{{-- Riders Table --}}
<div class="admin-table-wrap">
    <div class="admin-table-toolbar">
        <form method="GET" action="{{ route('admin.riders.index') }}" class="d-flex gap-2 align-items-center">
            <div class="grow">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Name, email, or phone..." value="{{ request('search') }}">
            </div>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>
            <select name="verified" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="1" {{ request('verified') === '1' ? 'selected' : '' }}>Verified</option>
                <option value="0" {{ request('verified') === '0' ? 'selected' : '' }}>Unverified</option>
            </select>
            <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </form>
    </div>

    <div class="table-responsive">
    <table class="admin-table">
        <thead>
            <tr>
                <th class="sortable">Name <span class="sort-ind"></span></th>
                <th class="sortable">Email <span class="sort-ind"></span></th>
                <th class="sortable">Phone <span class="sort-ind"></span></th>
                <th class="sortable">Status <span class="sort-ind"></span></th>
                <th class="sortable">Verified <span class="sort-ind"></span></th>
                <th class="sortable text-center">Total Rentals <span class="sort-ind"></span></th>
                <th class="sortable">Total Spent <span class="sort-ind"></span></th>
                <th class="sortable">Joined <span class="sort-ind"></span></th>
                <th class="col-actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($riders as $rider)
                <tr>
                    <td class="cell-title" data-label="Name">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar me-2" style="width:32px;height:32px;font-size:12px;" aria-hidden="true">
                                {{ strtoupper(substr($rider->name, 0, 1)) }}
                            </div>
                            <button type="button"
                                    class="customer-name-link"
                                    onclick="openVerifyReview(this)"
                                    data-id="{{ $rider->id }}"
                                    data-name="{{ $rider->name }}"
                                    data-email="{{ $rider->email }}"
                                    data-phone="{{ $rider->phoneNumber ?? '—' }}"
                                    data-address="{{ $rider->address ?? '—' }}"
                                    data-joined="{{ $rider->created_at->format('M d, Y') }}"
                                    data-id-url="{{ $rider->idVerification['id_url'] ?? '' }}"
                                    data-id-status="{{ $rider->idVerification['status'] ?? 'pending' }}"
                                    data-id-submitted="{{ $rider->idVerification['submitted_at'] ?? '' }}"
                                    title="View customer details for {{ $rider->name }}"
                                    aria-label="View details for {{ $rider->name }}">
                                {{ $rider->name }}
                            </button>
                        </div>
                    </td>
                    <td data-label="Email"><small>{{ $rider->email }}</small></td>
                    <td data-label="Phone">{{ $rider->phoneNumber ?? '—' }}</td>
                    <td data-label="Status">
                        @if($rider->status === 'active')
                            <x-admin.badge type="success" label="Active" />
                        @elseif($rider->status === 'inactive')
                            <x-admin.badge type="neutral" label="Inactive" />
                        @elseif($rider->status === 'suspended')
                            <x-admin.badge type="danger" label="Suspended" />
                        @else
                            <x-admin.badge type="neutral" :label="ucfirst($rider->status ?? 'active')" />
                        @endif
                    </td>
                    <td data-label="Verified">
                        @if($rider->verified ?? false)
                            <x-admin.badge type="success" label="Verified" />
                        @else
                            <x-admin.badge type="warning" label="Pending" />
                        @endif
                    </td>
                    <td class="text-center" data-label="Total Rentals">{{ $rider->totalRentals ?? 0 }}</td>
                    <td data-label="Total Spent">₱{{ number_format($rider->totalSpent ?? 0, 2) }}</td>
                    <td data-label="Joined"><small class="text-muted">{{ $rider->created_at->format('M d, Y') }}</small></td>
                    <td data-label="Actions" class="col-actions">
                        <div class="actions-row">
                            @if(($rider->status ?? 'active') === 'active')
                                <form action="{{ route('admin.riders.status', $rider->id) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="inactive">
                                    <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm" title="Disable" aria-label="Disable" data-confirm="Disable this rider?">
                                        <i class="bi bi-pause-circle"></i>
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('admin.riders.status', $rider->id) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" class="btn-admin btn-admin--secondary btn-admin--sm" title="Enable" aria-label="Enable">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                </form>
                            @endif

                            @if(($rider->status ?? 'active') !== 'suspended')
                                <form action="{{ route('admin.riders.status', $rider->id) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="suspended">
                                    <button type="submit" class="btn-admin btn-admin--danger btn-admin--sm" title="Suspend" aria-label="Suspend" data-confirm="Suspend this rider?">
                                        <i class="bi bi-slash-circle"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-admin.empty-state icon="bi-people" title="No riders found" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div class="admin-table-foot">
        <span>Showing {{ method_exists($riders, 'total') ? $riders->total() : $riders->count() }} records</span>
        @if(method_exists($riders, 'links'))
            {{ $riders->withQueryString()->links() }}
        @endif
    </div>
</div>

{{-- ── Review Verification Modal ────────────────────────────────── --}}
<div class="admin-modal" id="verifyReviewModal">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog admin-modal__dialog--xl">
        {{-- Header: icon + title/subtitle as one baseline-aligned group, with a
             fixed-size close X so nothing drifts between states. --}}
        <div class="admin-modal__head vr-review-head">
            <span class="vr-header-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
            <div class="vr-review-head__text">
                <h3 class="vr-review-head__title">Review Verification</h3>
                <p class="vr-review-head__subtitle">Check the submitted ID, then approve or reject</p>
            </div>
            <button type="button" class="vr-review-head__close" data-modal-close
                    aria-label="Close" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        {{-- Body --}}
        <div class="admin-modal__body">
            <div class="row g-4">

                {{-- ── Customer Details Card ──────────────────── --}}
                <div class="col-lg-5">
                    <div class="vr-card vr-card--details">
                        <div class="vr-card__head">
                            <span class="vr-card__icon"><i class="bi bi-person-circle"></i></span>
                            <span class="vr-card__title">Customer Details</span>
                        </div>
                        <div class="vr-card__body">
                            <div class="vr-field">
                                <span class="vr-field__label">Full Name</span>
                                <span class="vr-field__value" id="vrName">—</span>
                            </div>
                            <div class="vr-field">
                                <span class="vr-field__label">Email Address</span>
                                <span class="vr-field__value" id="vrEmail">—</span>
                            </div>
                            <div class="vr-field">
                                <span class="vr-field__label">Phone Number</span>
                                <span class="vr-field__value" id="vrPhone">—</span>
                            </div>
                            <div class="vr-field">
                                <span class="vr-field__label">Address</span>
                                <span class="vr-field__value" id="vrAddress">—</span>
                            </div>
                            <div class="vr-field">
                                <span class="vr-field__label">Date Joined</span>
                                <span class="vr-field__value" id="vrJoined">—</span>
                            </div>
                            <div class="vr-field">
                                <span class="vr-field__label">Verification Status</span>
                                <span class="vr-field__value" id="vrIdStatus">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Submitted ID Card ──────────────────────── --}}
                <div class="col-lg-7">
                    <div class="vr-card vr-card--id">
                        <div class="vr-card__head">
                            <span class="vr-card__icon vr-card__icon--id"><i class="bi bi-card-image"></i></span>
                            <span class="vr-card__title">Submitted ID</span>
                            <button type="button" class="btn-admin btn-admin--ghost btn-admin--xs ms-auto d-none" id="vrZoomBtn" title="View full size">
                                <i class="bi bi-arrows-fullscreen me-1"></i>Full Size
                            </button>
                        </div>
                        <div class="vr-card__body vr-card__body--id">
                            <div id="vrIdWrap" class="vr-id-preview">
                                <img id="vrIdImg" src="" alt="Submitted ID">
                                <p id="vrIdEmpty" class="vr-id-empty d-none">
                                    <i class="bi bi-file-earmark-image"></i>
                                    <span>No ID image submitted</span>
                                    <small>The customer has not uploaded an identification document.</small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Footer: both buttons simply open their own styled confirmation dialog,
             which is where the rejection reason is collected. --}}
        <div class="admin-modal__foot">
            <form id="vrRejectForm" method="POST" action="{{ route('admin.riders.verify', '__ID__') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="approved" value="0">
                <button type="submit" class="btn-admin btn-admin--danger btn-admin--sm vr-action-btn vr-action-btn--reject">
                    <i class="bi bi-x-lg me-1"></i>Reject Verification
                </button>
            </form>
            <form id="vrApproveForm" method="POST" action="{{ route('admin.riders.verify', '__ID__') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="approved" value="1">
                <button type="submit" class="btn-admin btn-admin--success btn-admin--sm vr-action-btn vr-action-btn--approve">
                    <i class="bi bi-check-lg me-1"></i>Approve Verification
                </button>
            </form>
        </div>
    </div>
</div>

{{-- ── Approve Verification Confirmation ─────────────────────
         A Pedalya-styled replacement for window.confirm: it sits above the
         review dialog, and it only closes when the server has confirmed the
         write (the dialog is the visible progress indicator). --}}
<div class="admin-modal admin-modal--confirm" id="verifyApproveModal"
     role="dialog" aria-modal="true" aria-labelledby="verifyApproveTitle">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog">
        <div class="admin-modal__head vr-confirm-head">
            <span class="vr-confirm-head__icon" aria-hidden="true"><i class="bi bi-person-check"></i></span>
            <div class="vr-review-head__text">
                <h3 class="vr-review-head__title" id="verifyApproveTitle">Approve Customer Verification</h3>
            </div>
            <button type="button" class="vr-review-head__close" data-modal-close
                    aria-label="Close" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="admin-modal__body">
            <p class="vr-confirm-msg">
                Approve this customer's identity verification? This marks them as
                <strong>Verified</strong> and lets them start renting bicycles.
            </p>
            <div class="vr-confirm-error d-none" id="verifyApproveError" role="alert"></div>
        </div>
        <div class="admin-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" id="verifyApproveCancel">
                Cancel
            </button>
            <button type="button" class="btn-admin btn-admin--success btn-admin--sm vr-action-btn vr-action-btn--approve"
                    id="verifyApproveConfirm">
                <span class="spinner-border spinner-border-sm me-1 d-none" id="verifyApproveSpinner" role="status"
                      aria-hidden="true"></span>
                <i class="bi bi-check-lg me-1" id="verifyApproveIcon"></i>
                <span id="verifyApproveLabel">Approve Verification</span>
            </button>
        </div>
    </div>
</div>

{{-- ── Reject Verification Confirmation ──────────────────────
         Same header layout and same staged behaviour as the approve dialog;
         the reason field posts with vrRejectForm through its form="" owner. --}}
<div class="admin-modal admin-modal--confirm" id="verifyRejectModal"
     role="dialog" aria-modal="true" aria-labelledby="verifyRejectTitle">
    <div class="admin-modal__backdrop" data-modal-close></div>
    <div class="admin-modal__dialog">
        <div class="admin-modal__head vr-confirm-head">
            <span class="vr-confirm-head__icon vr-confirm-head__icon--danger" aria-hidden="true"><i class="bi bi-person-x"></i></span>
            <div class="vr-review-head__text">
                <h3 class="vr-review-head__title" id="verifyRejectTitle">Reject Customer Verification</h3>
            </div>
            <button type="button" class="vr-review-head__close" data-modal-close
                    aria-label="Close" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="admin-modal__body">
            <p class="vr-confirm-msg">
                Reject this customer's identity verification? This keeps them
                <strong>Unverified</strong> and lets them submit a different ID.
            </p>
            <div class="vr-reject-reason">
                <label class="vr-reject-reason__label" for="vrRejectReason">Rejection reason</label>
                <textarea id="vrRejectReason" name="reason" class="vr-reject-reason__input"
                          form="vrRejectForm" rows="3" maxlength="500"
                          placeholder="e.g. The photo of the ID is blurry or cropped"
                          aria-describedby="vrRejectReasonHint"></textarea>
                <small id="vrRejectReasonHint" class="vr-reject-reason__hint">
                    Optional — the customer sees this message with the rejection.
                </small>
            </div>
            <div class="vr-confirm-error d-none" id="verifyRejectError" role="alert"></div>
        </div>
        <div class="admin-modal__foot">
            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" id="verifyRejectCancel">
                Cancel
            </button>
            <button type="button" class="btn-admin btn-admin--danger btn-admin--sm vr-action-btn vr-action-btn--reject"
                    id="verifyRejectConfirm">
                <span class="spinner-border spinner-border-sm me-1 d-none" id="verifyRejectSpinner" role="status"
                      aria-hidden="true"></span>
                <i class="bi bi-x-lg me-1" id="verifyRejectIcon"></i>
                <span id="verifyRejectLabel">Reject Verification</span>
            </button>
        </div>
    </div>
</div>

{{-- Full-size ID Lightbox --}}
<div class="vr-lightbox" id="vrLightbox">
    <div class="vr-lightbox__backdrop" onclick="closeLightbox()"></div>
    <div class="vr-lightbox__content">
        <button type="button" class="vr-lightbox__close" onclick="closeLightbox()"><i class="bi bi-x-lg"></i></button>
        <img id="vrLightboxImg" src="" alt="ID Full Size">
    </div>
</div>

<script>
function openVerifyReview(btn) {
    const d = btn.dataset;
    document.getElementById('vrName').textContent = d.name || '—';
    document.getElementById('vrEmail').textContent = d.email || '—';
    document.getElementById('vrPhone').textContent = d.phone || '—';
    document.getElementById('vrAddress').textContent = d.address || '—';
    document.getElementById('vrJoined').textContent = d.joined || '—';

    const statusEl = document.getElementById('vrIdStatus');
    const s = d.idStatus || 'pending';
    const badgeClass = s === 'approved' ? 'vr-badge--success' : s === 'rejected' ? 'vr-badge--danger' : 'vr-badge--warning';
    const badgeLabel = s === 'approved' ? 'Approved' : s === 'rejected' ? 'Rejected' : 'Pending';
    statusEl.innerHTML = '<span class="vr-badge ' + badgeClass + '">' + badgeLabel + '</span>';

    const img = document.getElementById('vrIdImg');
    const empty = document.getElementById('vrIdEmpty');
    const zoomBtn = document.getElementById('vrZoomBtn');
    if (d.idUrl) {
        img.src = d.idUrl;
        img.classList.remove('d-none');
        empty.classList.add('d-none');
        zoomBtn.classList.remove('d-none');
    } else {
        img.classList.add('d-none');
        empty.classList.remove('d-none');
        zoomBtn.classList.add('d-none');
    }

    const id = d.id;
    document.getElementById('vrApproveForm').action = '{{ url("admin/riders") }}/' + id + '/verify';
    document.getElementById('vrRejectForm').action = '{{ url("admin/riders") }}/' + id + '/verify';

    PedalyaModal.open('verifyReviewModal');
}

document.getElementById('vrZoomBtn')?.addEventListener('click', function() {
    const img = document.getElementById('vrIdImg');
    if (img && img.src && !img.classList.contains('d-none')) {
        document.getElementById('vrLightboxImg').src = img.src;
        document.getElementById('vrLightbox').classList.add('open');
    }
});
function closeLightbox() {
    document.getElementById('vrLightbox').classList.remove('open');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeLightbox(); });

/* ── Approve / Reject Verification: styled confirmation dialogs ─────────────
   Both actions replace window.confirm with a Pedalya modal that opens on the
   form submit, stays open while the request is in flight and only closes once
   the server confirms the write; the page then reloads so the customer list,
   verification status and every count are re-read from the backend. */
function wireVrConfirmAction(config) {
    const form = document.getElementById(config.formId);
    const modal = document.getElementById(config.modalId);
    const confirmBtn = document.getElementById(config.confirmId);
    const cancelBtn = document.getElementById(config.cancelId);
    const errorEl = document.getElementById(config.errorId);
    const spinner = document.getElementById(config.spinnerId);
    const icon = document.getElementById(config.iconId);
    const label = document.getElementById(config.labelId);
    if (!form || !modal || !confirmBtn || !cancelBtn || !errorEl) return;
    let busy = false;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        /* admin.js locks the form in the capture phase (duplicate-submit guard),
           which would disable the footer button before this dialog ever opened.
           The dialog is the real gate, so undo that lock and let the user back in. */
        delete form.dataset.submitting;
        form.querySelectorAll('button').forEach(function(button) {
            button.disabled = false;
            button.classList.remove('is-loading');
        });
        errorEl.textContent = '';
        errorEl.classList.add('d-none');
        PedalyaModal.open(config.modalId);
        (document.getElementById(config.focusId) || confirmBtn).focus();
    });

    cancelBtn.addEventListener('click', function() {
        if (busy) return;
        PedalyaModal.close(config.modalId);
    });

    /* While a request is in flight the dialog itself is the progress
       indicator, so its own X and backdrop cannot dismiss it. This listener
       sits on the element and admin.js's handler sits on document in the same
       (bubble) phase, so stopping propagation here is enough. */
    modal.querySelectorAll('[data-modal-close]').forEach(function(el) {
        el.addEventListener('click', function(e) { if (busy) e.stopPropagation(); });
    });

    confirmBtn.addEventListener('click', async function() {
        if (busy) return;
        busy = true;
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        spinner.classList.remove('d-none');
        icon.classList.add('d-none');
        label.textContent = config.busyLabel;

        try {
            const response = await fetch(form.action, {
                method: 'POST', /* the form already carries _method=PUT, _token and approved */
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: new FormData(form),
            });

            if (!response.ok) {
                let message = config.errorMessage;
                try {
                    const data = await response.json();
                    if (data && data.message) message = data.message;
                } catch (parseError) { /* non-JSON error body: keep the fallback */ }
                throw new Error(message);
            }

            PedalyaModal.close(config.modalId);
            PedalyaModal.close('verifyReviewModal');
            window.location.reload();
        } catch (err) {
            busy = false;
            confirmBtn.disabled = false;
            cancelBtn.disabled = false;
            spinner.classList.add('d-none');
            icon.classList.remove('d-none');
            label.textContent = config.idleLabel;
            errorEl.textContent = err.message || config.errorMessage;
            errorEl.classList.remove('d-none');
        }
    });
}

wireVrConfirmAction({
    formId: 'vrApproveForm', modalId: 'verifyApproveModal',
    confirmId: 'verifyApproveConfirm', cancelId: 'verifyApproveCancel',
    errorId: 'verifyApproveError', spinnerId: 'verifyApproveSpinner',
    iconId: 'verifyApproveIcon', labelId: 'verifyApproveLabel', focusId: '',
    idleLabel: 'Approve Verification', busyLabel: 'Approving...',
    errorMessage: 'Could not approve this verification. Please try again.',
});

wireVrConfirmAction({
    formId: 'vrRejectForm', modalId: 'verifyRejectModal',
    confirmId: 'verifyRejectConfirm', cancelId: 'verifyRejectCancel',
    errorId: 'verifyRejectError', spinnerId: 'verifyRejectSpinner',
    iconId: 'verifyRejectIcon', labelId: 'verifyRejectLabel',
    focusId: 'vrRejectReason',
    idleLabel: 'Reject Verification', busyLabel: 'Rejecting...',
    errorMessage: 'Could not reject this verification. Please try again.',
});
</script>
@endsection