@extends('layouts.rider')

@section('title', 'Notifications')

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <div class="card-pedalya notif-panel">
            <div class="card-pedalya-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span><strong>Notifications</strong></span>
                    <span class="notification-unread-label" id="riderNotifUnreadLabel" data-notif-unread-label>{{ $unreadCount }} unread</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary active" onclick="filterNotif('all', this)">All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="filterNotif('unread', this)">Unread</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="filterNotif('rental', this)">Rentals</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="filterNotif('system', this)">System</button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-mark-all-read>
                    <i class="bi bi-check-all"></i> Mark All Read
                </button>
            </div>

            <div id="riderNotificationWrap">
                @include('rider.notifications-list', ['notifications' => $notifications])
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    var wrap = document.getElementById('riderNotificationWrap');
    var tableUrl = @json(route('rider.notifications.table'));

    window.filterNotif = function (type, btn) {
        document.querySelectorAll('.btn-group .btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.querySelectorAll('#riderNotifList .notification-item').forEach(function (item) {
            if (type === 'all') {
                item.style.display = '';
            } else if (type === 'unread') {
                item.style.display = item.classList.contains('unread') ? '' : 'none';
            } else {
                item.style.display = item.dataset.group === type ? '' : 'none';
            }
        });
    };

    // "Mark all read" and per-row "Mark as read" are handled globally in
    // js/notifications.js; both refresh the badges and dispatch this event so
    // the list below reloads in place, exactly like the admin list.
    window.addEventListener('pedalya:notifications-changed', function () {
        if (!wrap) return;

        fetch(tableUrl, { headers: { 'Accept': 'text/html' } })
            .then(function (res) {
                if (!res.ok) throw new Error();
                return res.text();
            })
            .then(function (html) { wrap.innerHTML = html; })
            .catch(function () { /* keep the rendered list */ });
    });
})();
</script>
@endsection