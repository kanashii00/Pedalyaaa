/* ============================================================
   PEDALYA — SHARED NOTIFICATION RUNTIME
   Loaded by both the admin and the rider shell so the header
   dropdown, badges, read actions and live updates can never
   drift apart. Expects window.Pedalya.csrfToken (layouts/app)
   and window.PedalyaSettings (each shell's own routes).
   ============================================================ */
(function () {
    'use strict';

    var settings = window.PedalyaSettings || {};
    var CSRF = (window.Pedalya && window.Pedalya.csrfToken) || '';
    var badgeEls = function () {
        return Array.prototype.slice.call(document.querySelectorAll('[data-notif-badge], #topbarNotifBadge, #sidebarNotifBadge'));
    };

    /* ---------- Badges + unread label ---------- */
    function setBadge(count) {
        var value = Math.max(parseInt(count, 10) || 0, 0);

        badgeEls().forEach(function (badge) {
            badge.textContent = value > 99 ? '99+' : String(value);
            badge.style.display = value > 0 ? '' : 'none';
        });

        Array.prototype.slice.call(document.querySelectorAll('[data-notif-unread-label]')).forEach(function (label) {
            label.textContent = value + ' unread';
        });

        return value;
    }

    function refreshBadge() {
        if (!settings.unreadCountUrl) return Promise.resolve(0);

        return fetch(settings.unreadCountUrl, { headers: { Accept: 'application/json' } })
            .then(function (res) {
                return res.ok ? res.json() : null;
            })
            .then(function (data) {
                return data ? setBadge(data.unread_count) : null;
            })
            .catch(function () {
                /* keep the last known badge */
                return null;
            });
    }

    /* ---------- Toasts (reuses the admin stack when present) ---------- */
    var TOAST_ICONS = {
        success: 'bi-check-circle-fill',
        error: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
    };
    var TOAST_VARIANTS = {
        success: 'success',
        error: 'danger',
        warning: 'warning',
        info: 'info',
    };

    function toast(message, variant, title) {
        if (!message) return;

        if (window.PedalyaToast && window.PedalyaToast[variant]) {
            window.PedalyaToast[variant](message, title);
            return;
        }

        var stack = document.getElementById('pedalyaToasts');
        if (!stack) return;

        var el = document.createElement('div');
        el.className = 'pedalya-toast pedalya-toast--' + variant;
        el.setAttribute('role', 'status');
        el.innerHTML =
            '<i class="bi ' + (TOAST_ICONS[variant] || TOAST_ICONS.info) + ' pedalya-toast__icon"></i>' +
            '<div><div class="pedalya-toast__title"></div><div class="pedalya-toast__msg"></div></div>' +
            '<button class="pedalya-toast__close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button>';
        el.querySelector('.pedalya-toast__title').textContent = title || 'Notification';
        el.querySelector('.pedalya-toast__msg').textContent = message;
        el.classList.add('pedalya-toast--' + (TOAST_VARIANTS[variant] || 'info'));

        stack.appendChild(el);
        requestAnimationFrame(function () {
            el.classList.add('show');
        });

        var remove = function () {
            el.classList.remove('show');
            setTimeout(function () {
                el.remove();
            }, 320);
        };
        el.querySelector('.pedalya-toast__close').addEventListener('click', remove);
        setTimeout(remove, variant === 'error' ? 7000 : 4200);
    }

    /* ---------- Requests ---------- */
    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                Accept: 'application/json',
            },
        }).then(function (res) {
            return res.json().then(function (data) {
                data._httpStatus = res.status;
                return data;
            });
        });
    }

    function changed() {
        window.dispatchEvent(new Event('pedalya:notifications-changed'));
    }

    /* ---------- Dropdowns (bell + profile) ---------- */
    function initDropdowns() {
        Array.prototype.slice.call(document.querySelectorAll('.admin-dropdown')).forEach(function (dd) {
            if (dd.dataset.dropdownBound === '1') return;

            var btn = dd.querySelector('[data-dropdown-toggle]');
            var menu = dd.querySelector('.admin-dropdown__menu');
            if (!btn || !menu) return;

            dd.dataset.dropdownBound = '1';
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                Array.prototype.slice.call(document.querySelectorAll('.admin-dropdown__menu.open')).forEach(function (other) {
                    if (other !== menu) other.classList.remove('open');
                });
                menu.classList.toggle('open');
            });
        });
    }

    document.addEventListener('click', function () {
        Array.prototype.slice.call(document.querySelectorAll('.admin-dropdown__menu.open')).forEach(function (menu) {
            menu.classList.remove('open');
        });
    });

    /* ---------- Mark all read (dropdown footer + notifications page) ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-mark-all-read]');
        if (!btn || !settings.markAllReadUrl) return;

        e.preventDefault();
        btn.disabled = true;

        post(settings.markAllReadUrl)
            .then(function (data) {
                if (data._httpStatus >= 200 && data._httpStatus < 300) {
                    toast(data.message || 'All notifications marked as read.', 'success');
                    if (typeof data.unread_count !== 'undefined') setBadge(data.unread_count);
                    else refreshBadge();
                    changed();
                    return;
                }
                toast(data.message || 'Unable to mark notifications as read.', 'error', 'Failed');
            })
            .catch(function () {
                toast('Unable to mark notifications as read. Please try again.', 'error', 'Failed');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });

    /* ---------- Mark one as read (list rows) ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-mark-read]');
        if (!btn) return;

        var id = btn.dataset.markRead;
        if (!id || !settings.markReadUrl) return;

        e.preventDefault();
        btn.disabled = true;

        post(settings.markReadUrl.replace('__ID__', encodeURIComponent(id)))
            .then(function (data) {
                if (data._httpStatus >= 200 && data._httpStatus < 300) {
                    toast(data.message || 'Notification marked as read.', 'success');
                    refreshBadge();
                    changed();
                    return;
                }
                toast(data.message || 'Unable to mark the notification as read.', 'error', 'Failed');
            })
            .catch(function () {
                toast('Unable to mark the notification as read.', 'error', 'Failed');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });

    /* ---------- Plain form fallback (works without this runtime) ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('form[data-notifications-form]')) return;

        var action = form.getAttribute('action') || '';
        if (!/\/notifications(\/|$)/.test(action)) return;

        e.preventDefault();
        var button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;

        post(action)
            .then(function (data) {
                if (data._httpStatus >= 200 && data._httpStatus < 300) {
                    toast(data.message || 'Done.', 'success');
                    if (typeof data.unread_count !== 'undefined') setBadge(data.unread_count);
                    else refreshBadge();
                    changed();
                    return;
                }
                toast(data.message || 'Unable to update notifications.', 'error', 'Failed');
            })
            .catch(function () {
                toast('Unable to update notifications. Please try again.', 'error', 'Failed');
            })
            .finally(function () {
                if (button) button.disabled = false;
            });
    });

    /* ---------- Live updates ---------- */
    function subscribe() {
        if (!window.Echo || !window.PedalyaChannels || !window.PedalyaChannels.notifications) return;

        var channel = window.Echo.private(window.PedalyaChannels.notifications);
        if (!channel) return;

        channel.listen('.notification.created', function (payload) {
            payload = payload || {};
            toast(payload.message || 'You have a new notification.', 'info', payload.title || 'Notification');
            refreshBadge();
            changed();
        });
    }

    window.PedalyaNotifications = {
        toast: toast,
        setBadge: setBadge,
        refreshBadge: refreshBadge,
        initDropdowns: initDropdowns,
        subscribe: subscribe,
    };

    /* Back-compat alias used by the admin notifications page. */
    window.PedalyaBadges = { set: setBadge, refresh: refreshBadge };

    function boot() {
        initDropdowns();
        subscribe();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();