/* ============================================================
   PEDALYA ADMIN — RENTAL LIVE SYNC
   Keeps Active Rentals, Returns and the dashboard KPIs current without
   a page reload. The backend stays the source of truth:
     1. A tiny versioned feed (/admin/rentals/feed) is polled or re-read
        whenever something may have changed.
     2. Only when the version actually moves are counters patched and the
        page's table regions re-rendered from the server's own HTML.
   Transports, in order of preference:
     - WebSocket: the `rentals-feed` private channel (`rental.updated`,
       emitted by RentalFeed::touch() after every lifecycle transition).
     - WebSocket: the signed-in admin's notification channel, filtered to
       rental lifecycle types (covers the same transitions even if one
       broadcast path hiccups).
     - Efficient polling of the version feed (20s) when Echo is absent,
       plus a 60s safety poll when it is present.
   Also refetches when the tab becomes visible again or a bfcache-restore
   happens. Event listeners, subscriptions and timers are all torn down by
   destroy() (and guarded by a singleton so double-evaluation can never
   create duplicate timers).
   Pages opt in with:
     window.PedalyaRentalLivePage = { page: 'rentals'|'returns'|'dashboard',
                                      feedUrl: '...' }
   and, for table pages, a re-init hook that re-binds row buttons after a
   region swap:
     window.PedalyaRentalsLiveRefresh = function (table) {...}
     window.PedalyaReturnsLiveRefresh = function (table) {...}
   ============================================================ */
(function () {
    'use strict';

    if (window.PedalyaRentalLive) {
        window.PedalyaRentalLive.destroy();
    }

    var config = window.PedalyaRentalLivePage || null;
    if (!config || !config.page || !config.feedUrl) return;

    var PAGE = config.page;
    var FEED_URL = config.feedUrl;
    var POLL_MS = 20000;
    var SAFETY_MS = 60000;
    var EVENT_DEBOUNCE_MS = 1500;
    var RETRY_MS = 5000;

    var RENTAL_TYPES = [
        'rental_started', 'rental_approved', 'rental_returned',
        'return_requested', 'rental_completed', 'rental_expired',
        'rental_overdue_return', 'rental_deadline', 'rental_extended',
        'rental_cancelled',
    ];

    /* Regions swapped from the freshly fetched page HTML. `swap: 'html'`
       replaces innerHTML (keeps the element and its own listeners);
       `swap: 'outer'` replaces the whole element. */
    var REGIONS = {
        rentals: [
            { selector: '#rvTable tbody', swap: 'html' },
            { selector: '#rvFoot', swap: 'outer' },
        ],
        returns: [
            { selector: '#rvTabs', swap: 'html' },
            { selector: '#rvSummary', swap: 'html' },
            { selector: '#rvTable tbody', swap: 'html' },
            { selector: '#rvFoot', swap: 'outer' },
        ],
        dashboard: [
            { selector: '#kpiRow', swap: 'html' },
            { selector: '#recentRentalsFeed', swap: 'html' },
        ],
    };

    var destroyed = false;
    var refreshing = false;
    var pendingRefresh = false;
    var debounceTimer = 0;
    var pollTimer = 0;
    var retryTimer = 0;
    var lastCheck = 0;
    var lastVersion = null;
    var lastSnapshot = '';

    function snapshotOf(data) {
        return JSON.stringify(data);
    }

    function sameSnapshot(data) {
        return lastSnapshot !== '' && snapshotOf(data) === lastSnapshot;
    }

    /* ---------- Sidebar badge (lives in the layout, outside regions) ---------- */
    function paintBadges(data) {
        var badge = document.getElementById('sidebarActiveRentalsBadge');
        if (badge && typeof data.active === 'number') {
            var n = Math.max(0, data.active);
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.style.display = n > 0 ? '' : 'none';
        }
    }

    /* ---------- Swap guards ---------- */
    function modalOpen() {
        return !!document.querySelector('.admin-modal.open, .pr-modal.open, .rcpt-modal.open');
    }

    function focusInsideRegions() {
        var active = document.activeElement;
        if (!active || active === document.body) return false;
        if (!/^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) return false;
        return (REGIONS[PAGE] || []).some(function (region) {
            var host = document.querySelector(region.selector);
            return host && (host === active || host.contains(active));
        });
    }

    function deferRefresh() {
        pendingRefresh = true;
        if (!retryTimer) {
            retryTimer = setTimeout(function () {
                retryTimer = 0;
                if (!destroyed && pendingRefresh) refreshNow();
            }, RETRY_MS);
        }
    }

    /* ---------- Region swap ---------- */
    function collectOpenDetails() {
        var open = [];
        Array.prototype.slice.call(document.querySelectorAll('tr.rv-detail.open')).forEach(function (row) {
            if (row.id) open.push(row.id);
        });
        return open;
    }

    function restoreOpenDetails(open) {
        open.forEach(function (id) {
            var row = document.getElementById(id);
            if (row) row.classList.add('open');
            var main = document.querySelector('tr.rv-row[data-detail="' + id + '"]');
            if (main) main.classList.add('is-open');
            var btn = document.querySelector('[data-detail-toggle="' + id + '"]');
            if (btn) btn.classList.add('is-open');
        });
    }

    function swapRegions(doc) {
        var open = collectOpenDetails();
        var swapped = 0;

        (REGIONS[PAGE] || []).forEach(function (region) {
            var current = document.querySelector(region.selector);
            var fresh = doc.querySelector(region.selector);
            if (!current || !fresh) return;
            if (region.swap === 'outer') {
                current.outerHTML = fresh.outerHTML;
            } else {
                current.innerHTML = fresh.innerHTML;
            }
            swapped += 1;
        });

        if (!swapped) return false;

        restoreOpenDetails(open);

        // Client-side search text lives outside the swapped regions: re-apply
        // it so freshly rendered rows are filtered identically, no flicker.
        var search = document.querySelector('[data-table-search]');
        if (search && search.value) {
            var evt;
            try {
                evt = new Event('input', { bubbles: true });
            } catch (e) {
                evt = document.createEvent('Event');
                evt.initEvent('input', true, true);
            }
            search.dispatchEvent(evt);
        }

        // Page-owned row bindings (detail toggles, receipt/process/pay
        // buttons, row menus, countdowns) for the replaced rows.
        var hook = PAGE === 'rentals' ? window.PedalyaRentalsLiveRefresh
            : PAGE === 'returns' ? window.PedalyaReturnsLiveRefresh
            : null;
        if (typeof hook === 'function') {
            try { hook(document.getElementById('rvTable')); } catch (e) { /* keep old rows working */ }
        }
        if (window.PedalyaRowMenuInit) {
            try { window.PedalyaRowMenuInit(document); } catch (e) { /* guarded internally */ }
        }

        try {
            window.dispatchEvent(new Event('pedalya:rentals-refreshed'));
        } catch (e) { /* very old browsers */ }

        return true;
    }

    function fetchPage() {
        if (refreshing) {
            pendingRefresh = true;
            return;
        }
        if (modalOpen() || focusInsideRegions() || document.body.classList.contains('rcpt-printing')) {
            // Never yank rows out from under an open dialog, a focused field
            // or a print: retry shortly instead of dropping the update.
            deferRefresh();
            return;
        }
        refreshing = true;
        pendingRefresh = false;

        fetch(window.location.href, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                if (destroyed) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                swapRegions(doc);
            })
            .catch(function () { pendingRefresh = true; })
            .finally(function () {
                refreshing = false;
                if (!destroyed && pendingRefresh) deferRefresh();
            });
    }

    /* ---------- Version check ---------- */
    function applyVersion(data) {
        paintBadges(data);
        if (lastVersion === null || !sameSnapshot(data)) {
            lastVersion = data.version;
            lastSnapshot = snapshotOf(data);
            fetchPage();
        }
        lastCheck = Date.now();
    }

    function checkVersion() {
        if (destroyed || refreshing) {
            pendingRefresh = true;
            return;
        }
        fetch(FEED_URL + (FEED_URL.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!destroyed && data) applyVersion(data);
            })
            .catch(function () { /* next poll / event retries */ });
    }

    function scheduleCheck() {
        if (destroyed) return;
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            debounceTimer = 0;
            if (!destroyed) checkVersion();
        }, EVENT_DEBOUNCE_MS);
    }

    function refreshNow() {
        pendingRefresh = false;
        checkVersion();
    }

    /* ---------- Transports ---------- */
    function hasEcho() {
        return !!(window.Echo && window.PedalyaChannels);
    }

    function subscribeFeed() {
        if (!hasEcho() || typeof window.Echo.private !== 'function') return;
        try {
            window.Echo.private('rentals-feed').listen('.rental.updated', function () {
                scheduleCheck();
            });
        } catch (e) { /* polling covers it */ }
    }

    function subscribeNotifications() {
        // Second trigger path: rental lifecycle notifications addressed to
        // this admin. Independent of notifications.js (Echo allows multiple
        // listeners); filtered so unrelated toasts never cause a refetch.
        if (!hasEcho() || !window.PedalyaChannels.notifications) return;
        try {
            window.Echo.private(window.PedalyaChannels.notifications)
                .listen('.notification.created', function (payload) {
                    payload = payload || {};
                    if (RENTAL_TYPES.indexOf(payload.type) !== -1) scheduleCheck();
                });
        } catch (e) { /* polling covers it */ }
    }

    function startPolling() {
        stopPolling();
        pollTimer = setInterval(function () {
            if (destroyed || document.hidden) return;
            checkVersion();
        }, hasEcho() ? SAFETY_MS : POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = 0;
    }

    function onVisibility() {
        if (destroyed || document.hidden) return;
        // Returning to the tab re-syncs (at most one catch-up fetch): the
        // version gate keeps it a no-op when nothing changed.
        if (Date.now() - lastCheck > 10000) checkVersion();
        if (pendingRefresh) refreshNow();
    }

    function onPageShow(event) {
        if (destroyed) return;
        if (event && event.persisted) checkVersion();
        else if (pendingRefresh) refreshNow();
    }

    function destroy() {
        destroyed = true;
        if (debounceTimer) clearTimeout(debounceTimer);
        if (retryTimer) clearTimeout(retryTimer);
        stopPolling();
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('pageshow', onPageShow);
        if (window.Echo && typeof window.Echo.leave === 'function') {
            try { window.Echo.leave('rentals-feed'); } catch (e) { /* already gone */ }
        }
        if (window.PedalyaRentalLive === api) window.PedalyaRentalLive = null;
    }

    var api = { destroy: destroy, refreshNow: refreshNow, checkVersion: checkVersion };
    window.PedalyaRentalLive = api;

    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('pageshow', onPageShow);

    subscribeFeed();
    subscribeNotifications();
    startPolling();
    checkVersion();
})();
