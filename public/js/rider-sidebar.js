/* ============================================================
   PEDALYA — RIDER SIDEBAR (Google Classroom-style navigation)
   Shared by every renter dashboard page via layouts/rider.blade.php.

   Desktop (>=992px, fine pointer) — dual interaction:
   * Hover/focus the collapsed rail to TEMPORARILY expand it (labels,
     badges). Leaving collapses it back. The rail overlays content
     while previewing, so the main content never shifts.
   * Click the hamburger to PIN the sidebar expanded; it then stays
     open after the cursor leaves (content margin animates with it).
     Click again to unpin back to the rail. The pin persists across
     pages via localStorage.
   Hover never overrides the pinned state: mouse events are ignored
   while pinned.
   Mobile / touch (<992px): hamburger-driven off-canvas drawer
   with a backdrop. Selecting a destination auto-closes it.

   - Single init guard: safe if the layout is included twice.
   - Hover uses mouseenter/mouseleave (non-bubbling) plus short
     open/close delays, so moving between items never flickers.
   - Active item is rendered server-side (Blade `active` + aria-current),
     so browser back/forward updates it correctly on full page loads.
   ============================================================ */
(function () {
    'use strict';

    if (window.__pedalyaRiderSidebarInit) return;
    window.__pedalyaRiderSidebarInit = true;

    var MOBILE_BREAKPOINT = 992;
    var HOVER_OPEN_DELAY = 120;
    var HOVER_CLOSE_DELAY = 180;
    var PIN_KEY = 'pedalya.rider.sidebarPinned';

    function isMobile() {
        return window.innerWidth < MOBILE_BREAKPOINT;
    }

    function init() {
        var sidebar = document.getElementById('sidebar');
        var mainContent = document.getElementById('mainContent');
        var overlay = document.getElementById('sidebarOverlay');
        if (!sidebar || !mainContent) return;

        var toggles = Array.prototype.slice.call(
            document.querySelectorAll('[data-sidebar-toggle]')
        );
        var navLinks = Array.prototype.slice.call(
            sidebar.querySelectorAll('.sidebar-nav .nav-link')
        );
        var nav = sidebar.querySelector('.sidebar-nav');
        // Single shared indicator rendered by the Blade layout. It follows
        // the server-rendered active link (aria-current), never clicks, so
        // direct URLs, refresh, back/forward and programmatic navigation
        // all resolve correctly.
        var indicator = nav ? nav.querySelector('.nav-indicator') : null;
        var prefersReducedMotion = window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function activeLink() {
            if (!nav) return null;
            return nav.querySelector('.nav-link[aria-current="page"]') ||
                nav.querySelector('.nav-link.active');
        }

        function moveIndicator(animate) {
            if (!nav || !indicator) return;
            var link = activeLink();
            if (!link) {
                indicator.classList.remove('is-visible');
                return;
            }
            var navRect = nav.getBoundingClientRect();
            var linkRect = link.getBoundingClientRect();
            var top = linkRect.top - navRect.top + (nav.scrollTop || 0);
            var left = linkRect.left - navRect.left;
            if (!animate || prefersReducedMotion) {
                var previous = indicator.style.transition;
                indicator.style.transition = 'none';
                indicator.style.transform = 'translate(' + left + 'px, ' + top + 'px)';
                indicator.style.height = linkRect.height + 'px';
                // Force reflow so the visible snap applies before restoring.
                void indicator.offsetHeight;
                indicator.style.transition = previous;
            } else {
                indicator.style.transform = 'translate(' + left + 'px, ' + top + 'px)';
                indicator.style.height = linkRect.height + 'px';
            }
            indicator.classList.add('is-visible');
        }

        function refreshIndicator() {
            // Re-measure after layout-affecting changes settle.
            if (prefersReducedMotion) {
                moveIndicator(false);
                return;
            }
            window.requestAnimationFrame(function () {
                moveIndicator(true);
            });
        }

        function setHovering(expanded) {
            // Temporary preview only: never engage while pinned.
            if (isPinned()) expanded = false;
            if (expanded === sidebar.classList.contains('hovering')) return;
            sidebar.classList.toggle('hovering', expanded);
            refreshIndicator();
        }

        function isPinned() {
            return sidebar.classList.contains('pinned');
        }

        function readStoredPin() {
            try {
                return localStorage.getItem(PIN_KEY) === 'true';
            } catch (e) { return false; }
        }

        function setPinned(pin, persist) {
            clearHoverTimers();
            sidebar.classList.toggle('pinned', pin);
            if (pin) {
                // Pinned open: full width + content margin follow together.
                sidebar.classList.remove('hovering');
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                // Back to the rail; keep previewing only if the pointer is
                // already over the panel.
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                if (hoverCapable && !isMobile() && sidebar.matches(':hover')) {
                    sidebar.classList.add('hovering');
                } else {
                    sidebar.classList.remove('hovering');
                }
            }
            if (persist !== false) {
                try {
                    localStorage.setItem(PIN_KEY, pin ? 'true' : 'false');
                } catch (e) { /* private mode: session-only */ }
            }
            syncToggles();
            refreshIndicator();
        }

        function openDrawer() {
            sidebar.classList.add('show');
            if (overlay) {
                overlay.classList.add('show');
                overlay.setAttribute('aria-hidden', 'false');
            }
            document.body.classList.add('sidebar-drawer-open');
            syncToggles();
            refreshIndicator();
        }

        function closeDrawer() {
            if (!sidebar.classList.contains('show')) {
                syncToggles();
                return;
            }
            sidebar.classList.remove('show');
            if (overlay) {
                overlay.classList.remove('show');
                overlay.setAttribute('aria-hidden', 'true');
            }
            document.body.classList.remove('sidebar-drawer-open');
            syncToggles();
        }

        function syncToggles() {
            var mobile = isMobile();
            var pinned = isPinned();
            var expanded = mobile
                ? sidebar.classList.contains('show')
                : (pinned || sidebar.classList.contains('hovering'));
            var label = mobile
                ? (expanded ? 'Close navigation menu' : 'Open navigation menu')
                : (pinned ? 'Collapse sidebar' : 'Expand sidebar');

            toggles.forEach(function (btn) {
                btn.setAttribute('data-state', expanded ? 'expanded' : 'collapsed');
                btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                btn.setAttribute('aria-label', label);
                btn.setAttribute('title', label);
            });
        }

        function handleToggle() {
            // Mobile: hamburger opens/closes the drawer.
            // Desktop: hamburger pins/unpins the expanded sidebar.
            if (isMobile()) {
                if (sidebar.classList.contains('show')) {
                    closeDrawer();
                } else {
                    openDrawer();
                }
            } else {
                setPinned(!isPinned());
            }
        }

        // One listener per toggle button, bound exactly once.
        toggles.forEach(function (btn) {
            btn.addEventListener('click', handleToggle);
        });

        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }

        // Mobile drawer: close after choosing a destination (regular
        // full-page navigation still performs the route change).
        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (isMobile()) closeDrawer();
            });
        });

        // Escape closes the drawer and returns focus to the topbar toggle.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isMobile() && sidebar.classList.contains('show')) {
                closeDrawer();
                var opener = document.querySelector('.sidebar-toggle--topbar');
                if (opener) opener.focus();
            }
        });

        // Desktop is always an icon rail until hovered/focused; mobile is a
        // drawer. Drop the obsolete manual-collapse preference so every
        // page starts from the same automatic behavior.
        try {
            localStorage.removeItem('pedalya.rider.sidebarCollapsed');
        } catch (e) { /* private mode: nothing stored */ }

        // Hover-to-expand (fine pointers only). mouseenter/mouseleave do
        // not bubble, so gliding between menu items cannot flicker. Short
        // delays keep accidental pass-overs from popping the panel open.
        var hoverCapable = window.matchMedia &&
            window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        var hoverOpenTimer = null;
        var hoverCloseTimer = null;

        function clearHoverTimers() {
            if (hoverOpenTimer) window.clearTimeout(hoverOpenTimer);
            if (hoverCloseTimer) window.clearTimeout(hoverCloseTimer);
            hoverOpenTimer = null;
            hoverCloseTimer = null;
        }

        if (hoverCapable) {
            sidebar.addEventListener('mouseenter', function () {
                if (isMobile() || isPinned()) return;
                if (hoverCloseTimer) window.clearTimeout(hoverCloseTimer);
                hoverCloseTimer = null;
                if (sidebar.classList.contains('hovering')) return;
                hoverOpenTimer = window.setTimeout(function () {
                    setHovering(true);
                    syncToggles();
                }, HOVER_OPEN_DELAY);
            });

            sidebar.addEventListener('mouseleave', function () {
                if (hoverOpenTimer) window.clearTimeout(hoverOpenTimer);
                hoverOpenTimer = null;
                // A pinned sidebar ignores mouse-leave: the user's chosen
                // persistent state always wins over hover.
                if (isPinned()) return;
                if (!sidebar.classList.contains('hovering')) return;
                hoverCloseTimer = window.setTimeout(function () {
                    setHovering(false);
                    syncToggles();
                }, HOVER_CLOSE_DELAY);
            });

            // Keyboard users get the same expansion when tabbing through
            // (only when not pinned open already).
            sidebar.addEventListener('focusin', function () {
                if (isMobile() || isPinned()) return;
                clearHoverTimers();
                if (!sidebar.classList.contains('hovering')) {
                    setHovering(true);
                    syncToggles();
                }
            });

            sidebar.addEventListener('focusout', function () {
                window.setTimeout(function () {
                    if (isPinned()) return;
                    if (sidebar.contains(document.activeElement)) return;
                    if (sidebar.matches(':hover')) return;
                    if (!sidebar.classList.contains('hovering')) return;
                    setHovering(false);
                    syncToggles();
                }, 0);
            });
        }

        if (!isMobile()) {
            // Restore the user's pinned choice; default is the hover rail.
            setPinned(readStoredPin(), false);
            closeDrawer();
        } else {
            clearHoverTimers();
            sidebar.classList.remove('hovering');
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
            closeDrawer();
        }

        // Crossing the breakpoint cleans up drawer state and re-syncs labels.
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            if (resizeTimer) window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function () {
                clearHoverTimers();
                sidebar.classList.remove('hovering');
                if (isMobile()) {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    if (!sidebar.classList.contains('show')) closeDrawer();
                    else syncToggles();
                } else {
                    // Re-apply the user's pinned choice; never reset it.
                    setPinned(readStoredPin(), false);
                    closeDrawer();
                }
                refreshIndicator();
            }, 120);
        });

        // Sidebar width animation shifts horizontal geometry; re-pin the bar
        // once it settles. Fonts/images loading late can also move rows.
        sidebar.addEventListener('transitionend', function (event) {
            if (event.target === sidebar && (event.propertyName === 'width' || event.propertyName === 'transform')) {
                moveIndicator(false);
            }
        });
        window.addEventListener('load', function () {
            moveIndicator(false);
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                moveIndicator(false);
            });
        }

        // First paint: snap to the active route with no slide-in.
        moveIndicator(false);
        syncToggles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
