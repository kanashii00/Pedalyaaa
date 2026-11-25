/* ============================================================
   PEDALYA ENTERPRISE ADMIN — Application JS
   ============================================================ */
(function () {
  'use strict';

  if (!document.body.classList.contains('admin-shell')) return;
  const shell = document.body;

  const $ = (sel, el) => (el || document).querySelector(sel);
  const $$ = (sel, el) => Array.from((el || document).querySelectorAll(sel));

  /* ---------- Theme ---------- */
  const themeToggle = $('#themeToggle');
  function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('pedalya_theme', theme);
    if (themeToggle) {
      const icon = themeToggle.querySelector('i');
      if (icon) icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
    }
  }
  if (themeToggle) {
    const saved = localStorage.getItem('pedalya_theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(saved || (prefersDark ? 'dark' : 'light'));
    themeToggle.addEventListener('click', () => {
      applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    });
  }

  /* ---------- Sidebar (Classroom-style dual expand/collapse, shared pattern
     with public/js/rider-sidebar.js) ----------
     Desktop (>=1200px, fine pointer):
     * Hover/focus the collapsed rail to TEMPORARILY expand it (labels,
       badges, full logo). Leaving collapses it back. The rail overlays
       content while previewing, so .admin-main never shifts.
     * Click the hamburger to PIN the sidebar expanded; it then stays open
       after the cursor leaves. Click again to unpin back to the rail.
       The pin persists via localStorage ('pedalya_sidebar').
     Hover never overrides the pinned state.
     Mobile / touch (<1200px): hamburger-driven off-canvas drawer with a
     backdrop. Selecting a destination auto-closes it. */
  if (window.__pedalyaAdminSidebarInit) {
    // Shared admin.js may be evaluated once per page load only; guard anyway.
  } else {
    window.__pedalyaAdminSidebarInit = true;
  }
  const sidebar = $('#adminSidebar');
  const nav = $('#adminSidebarNav');
  const overlay = $('#adminSidebarOverlay') || $('.sidebar-overlay');
  const indicator = nav ? nav.querySelector('.admin-nav-indicator') : null;
  const sidebarToggles = $$('[data-sidebar-toggle]');
  if (!sidebarToggles.length) {
    const legacyToggle = $('#sidebarToggle');
    if (legacyToggle) sidebarToggles.push(legacyToggle);
  }

  const MOBILE_MAX = 1199.98;
  const HOVER_OPEN_DELAY = 120;
  const HOVER_CLOSE_DELAY = 180;

  function isMobile() { return window.innerWidth <= MOBILE_MAX; }

  const hoverCapable = window.matchMedia &&
    window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const prefersReducedMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let hoverOpenTimer = null;
  let hoverCloseTimer = null;
  function clearHoverTimers() {
    if (hoverOpenTimer) window.clearTimeout(hoverOpenTimer);
    if (hoverCloseTimer) window.clearTimeout(hoverCloseTimer);
    hoverOpenTimer = null;
    hoverCloseTimer = null;
  }

  function isCollapsed() { return shell.classList.contains('sidebar-collapsed'); }
  function isHovering() { return shell.classList.contains('sidebar-hovering'); }

  function isVisibleLink(link) {
    if (!link || !link.isConnected) return false;
    const rect = link.getBoundingClientRect();
    if (rect.height < 4 || rect.width < 4) return false;
    // display:none ancestors (collapsed rail hides .admin-nav__sub) report
    // zero rects; offsetParent null (outside fixed positioning) also hidden.
    if (link.offsetParent === null && getComputedStyle(link).position !== 'fixed') {
      // Sidebar itself is fixed, so check the nav-sub ancestor instead.
      const sub = link.closest('.admin-nav__sub');
      if (sub && getComputedStyle(sub).display === 'none') return false;
    }
    return true;
  }

  function activeLink() {
    if (!nav) return null;
    const serverActive = nav.querySelector('.admin-nav__link[aria-current="page"]') ||
      nav.querySelector('.admin-nav__link.active');
    if (!serverActive) return null;
    // Collapsed rail hides nested subs via display:none: point the bar at
    // the visible parent group button so it stays beside a real row.
    if (isVisibleLink(serverActive)) return serverActive;
    const group = serverActive.closest('.admin-nav[data-collapsible]');
    if (group) {
      const headerBtn = group.querySelector(':scope > .admin-nav__link');
      if (headerBtn && isVisibleLink(headerBtn)) return headerBtn;
    }
    // Hover preview / expanded will reveal the child; keep the bar hidden
    // until then rather than pointing at empty space.
    if (!isCollapsed() || isHovering()) return serverActive;
    const firstVisible = nav.querySelector('.admin-nav > .admin-nav__link, .admin-nav__link[href]');
    if (firstVisible && isVisibleLink(firstVisible) && firstVisible === serverActive) return serverActive;
    return group ? (group.querySelector(':scope > .admin-nav__link') || serverActive) : serverActive;
  }

  function moveIndicator(animate) {
    if (!nav || !indicator) return;
    const link = activeLink();
    if (!link || !isVisibleLink(link)) {
      indicator.classList.remove('is-visible');
      return;
    }
    // Collapsed rail now uses display:none for hidden rows, so only visible
    // rows measure; open groups are server-rendered, hover restores layout.
    const navRect = nav.getBoundingClientRect();
    const linkRect = link.getBoundingClientRect();
    const top = linkRect.top - navRect.top + (nav.scrollTop || 0);
    const left = linkRect.left - navRect.left;
    if (!animate || prefersReducedMotion) {
      const previous = indicator.style.transition;
      indicator.style.transition = 'none';
      indicator.style.transform = 'translate(' + left + 'px, ' + top + 'px)';
      indicator.style.height = linkRect.height + 'px';
      void indicator.offsetHeight;
      indicator.style.transition = previous;
    } else {
      indicator.style.transform = 'translate(' + left + 'px, ' + top + 'px)';
      indicator.style.height = linkRect.height + 'px';
    }
    indicator.classList.add('is-visible');
  }

  function refreshIndicator() {
    if (prefersReducedMotion) {
      moveIndicator(false);
      return;
    }
    window.requestAnimationFrame(function () { moveIndicator(true); });
  }

  function syncToggles() {
    const mobile = isMobile();
    const collapsed = isCollapsed();
    const hovering = isHovering();
    const drawerOpen = shell.classList.contains('sidebar-open');
    const expanded = mobile ? drawerOpen : (!collapsed || hovering);
    const pinned = !collapsed;
    const label = mobile
      ? (drawerOpen ? 'Close navigation menu' : 'Open navigation menu')
      : (pinned ? 'Collapse sidebar' : 'Expand sidebar');
    sidebarToggles.forEach(function (btn) {
      btn.setAttribute('data-state', expanded ? 'expanded' : 'collapsed');
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
    });
  }

  function setHovering(expanded) {
    // Temporary preview only: never engage while pinned open or on mobile.
    if (!isCollapsed() || isMobile()) expanded = false;
    if (expanded === isHovering()) return;
    shell.classList.toggle('sidebar-hovering', expanded);
    syncToggles();
    refreshIndicator();
  }

  function setCollapsed(collapsed, persist) {
    clearHoverTimers();
    shell.classList.toggle('sidebar-collapsed', collapsed);
    if (collapsed) {
      // Back to the rail; keep previewing only if the pointer is already
      // over the panel (mirrors rider-sidebar.js unpin behavior).
      if (hoverCapable && !isMobile() && sidebar.matches(':hover')) {
        shell.classList.add('sidebar-hovering');
      } else {
        shell.classList.remove('sidebar-hovering');
      }
    } else {
      // Pinned open: full width + content margin follow together.
      shell.classList.remove('sidebar-hovering');
    }
    if (persist !== false) {
      try {
        localStorage.setItem('pedalya_sidebar', collapsed ? 'collapsed' : 'expanded');
      } catch (e) { /* private mode: session-only */ }
    }
    syncToggles();
    refreshIndicator();
  }

  function openDrawer() {
    shell.classList.add('sidebar-open');
    shell.classList.add('sidebar-drawer-open');
    document.body.classList.add('sidebar-drawer-open');
    if (overlay) {
      overlay.classList.add('show');
      overlay.setAttribute('aria-hidden', 'false');
    }
    syncToggles();
    refreshIndicator();
  }

  function closeDrawer() {
    if (!shell.classList.contains('sidebar-open')) {
      syncToggles();
      return;
    }
    shell.classList.remove('sidebar-open');
    shell.classList.remove('sidebar-drawer-open');
    document.body.classList.remove('sidebar-drawer-open');
    if (overlay) {
      overlay.classList.remove('show');
      overlay.setAttribute('aria-hidden', 'true');
    }
    syncToggles();
  }

  function handleToggle() {
    // Mobile: hamburger opens/closes the drawer.
    // Desktop: hamburger pins/unpins the expanded sidebar (persistent).
    if (isMobile()) {
      if (shell.classList.contains('sidebar-open')) closeDrawer();
      else openDrawer();
    } else {
      setCollapsed(!isCollapsed());
    }
  }

  sidebarToggles.forEach(function (btn) {
    // Avoid double-binding if admin.js is evaluated twice.
    if (btn.dataset.sidebarToggleReady === '1') return;
    btn.dataset.sidebarToggleReady = '1';
    btn.addEventListener('click', handleToggle);
  });
  if (overlay && overlay.dataset.overlayReady !== '1') {
    overlay.dataset.overlayReady = '1';
    overlay.addEventListener('click', closeDrawer);
  }

  // Mobile drawer: close after choosing a destination (regular full-page
  // navigation still performs the route change).
  if (nav) {
    $$('.admin-nav__link[href]', nav).forEach(function (link) {
      link.addEventListener('click', function () {
        if (isMobile()) closeDrawer();
      });
    });
  }

  // Escape closes the drawer and returns focus to the toggle.
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isMobile() && shell.classList.contains('sidebar-open')) {
      closeDrawer();
      const opener = document.querySelector('[data-sidebar-toggle]');
      if (opener) opener.focus();
    }
  });

  // Hover-to-expand (fine pointers only). mouseenter/mouseleave do not
  // bubble, so gliding between menu items cannot flicker. Short delays keep
  // accidental pass-overs from popping the panel open. Pinned sidebar
  // ignores mouse-leave: the persistent state always wins over hover.
  if (hoverCapable && sidebar) {
    sidebar.addEventListener('mouseenter', function () {
      if (isMobile() || !isCollapsed()) return;
      if (hoverCloseTimer) window.clearTimeout(hoverCloseTimer);
      hoverCloseTimer = null;
      if (isHovering()) return;
      hoverOpenTimer = window.setTimeout(function () {
        setHovering(true);
      }, HOVER_OPEN_DELAY);
    });
    sidebar.addEventListener('mouseleave', function () {
      if (hoverOpenTimer) window.clearTimeout(hoverOpenTimer);
      hoverOpenTimer = null;
      if (!isCollapsed() || !isHovering()) return;
      hoverCloseTimer = window.setTimeout(function () {
        setHovering(false);
      }, HOVER_CLOSE_DELAY);
    });
    // Keyboard users get the same expansion when tabbing through (only when
    // collapsed to the rail).
    sidebar.addEventListener('focusin', function () {
      if (isMobile() || !isCollapsed()) return;
      clearHoverTimers();
      if (!isHovering()) setHovering(true);
    });
    sidebar.addEventListener('focusout', function () {
      window.setTimeout(function () {
        if (!isCollapsed() || !isHovering()) return;
        if (sidebar.contains(document.activeElement)) return;
        if (sidebar.matches(':hover')) return;
        setHovering(false);
      }, 0);
    });
  }

  /* Expandable nav groups — only the group header button toggles.
     Preserves every existing module, route and permission: just opens the
     group. When the rail is collapsed, the first click pins the sidebar
     open so nested menus stay usable (same as before). */
  $$('.admin-nav[data-collapsible] > .admin-nav__link').forEach(btn => {
    if (btn.dataset.collapsibleReady === '1') return;
    btn.dataset.collapsibleReady = '1';
    btn.addEventListener('click', () => {
      const parent = btn.closest('.admin-nav');
      if (isCollapsed() && !isMobile()) setCollapsed(false);
      parent.classList.toggle('admin-nav--open');
      refreshIndicator();
    });
  });

  /* Active link follows the actual current route.
     Server-rendered aria-current is the source of truth (covers direct URL
     access and browser back/forward on full page loads, plus role-based
     query/section sibling logic in admin.blade.php). The URL fallback below
     only applies when the server marked nothing active, so existing
     permissions and page-specific behavior are never overridden. */
  function syncActiveFromUrl() {
    if (!nav) return false;
    const links = $$('.admin-nav__link[href]', nav);
    if (!links.length) return false;
    // Server already resolved the route (including query/section siblings):
    // keep it and just ensure its group is open.
    const serverActive = nav.querySelector('.admin-nav__link[aria-current="page"]') ||
      nav.querySelector('.admin-nav__link.active');
    if (serverActive) {
      const parent = serverActive.closest('.admin-nav[data-collapsible]');
      if (parent) parent.classList.add('admin-nav--open');
      return true;
    }
    const cur = location.pathname + location.search;
    let match = null;
    for (const l of links) {
      try {
        const u = new URL(l.href);
        if ((u.pathname + u.search) === cur) { match = l; break; }
      } catch (e) { /* ignore unparsable hrefs */ }
    }
    if (!match) return false;
    links.forEach(l => {
      const on = l === match;
      l.classList.toggle('active', on);
      l.setAttribute('aria-current', on ? 'page' : 'false');
    });
    const parent = match.closest('.admin-nav[data-collapsible]');
    if (parent) parent.classList.add('admin-nav--open');
    return true;
  }
  syncActiveFromUrl();
  moveIndicator(false);
  window.addEventListener('hashchange', function () {
    syncActiveFromUrl();
    refreshIndicator();
  });
  // popstate covers back/forward for same-document (hash/query) navigation;
  // full document loads re-render the server active link and snap on init.
  window.addEventListener('popstate', function () {
    syncActiveFromUrl();
    refreshIndicator();
  });

  // Initial state: restore the user's pinned choice on desktop (default
  // expanded, preserving existing behavior); drawer closed on mobile.
  if (sidebar) {
    try {
      const stored = localStorage.getItem('pedalya_sidebar');
      if (!isMobile()) {
        setCollapsed(stored === 'collapsed', false);
        closeDrawer();
      } else {
        clearHoverTimers();
        shell.classList.remove('sidebar-hovering');
        closeDrawer();
      }
    } catch (e) {
      if (isMobile()) closeDrawer();
    }
  }

  // Crossing the breakpoint cleans up drawer/hover state and re-syncs.
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    if (resizeTimer) window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      clearHoverTimers();
      shell.classList.remove('sidebar-hovering');
      if (isMobile()) {
        if (!shell.classList.contains('sidebar-open')) closeDrawer();
        else syncToggles();
      } else {
        // Re-apply the user's pinned choice; never reset it.
        let stored = null;
        try { stored = localStorage.getItem('pedalya_sidebar'); } catch (e) {}
        setCollapsed(stored === 'collapsed', false);
        closeDrawer();
      }
      refreshIndicator();
    }, 120);
  });

  // Sidebar width animation shifts horizontal geometry; re-pin the bar once
  // it settles. Fonts/images loading late can also move rows.
  if (sidebar) {
    sidebar.addEventListener('transitionend', function (event) {
      if (event.target === sidebar && (event.propertyName === 'width' || event.propertyName === 'transform')) {
        moveIndicator(false);
      }
    });
  }
  window.addEventListener('load', function () { moveIndicator(false); });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { moveIndicator(false); });
  }
  // First paint: snap to the active route with no slide-in.
  moveIndicator(false);
  syncToggles();

  /* ---------- Live clock ---------- */
  const clock = $('#adminClock');
  if (clock) {
    function tick() {
      const now = new Date();
      const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
      clock.textContent = now.toLocaleDateString('en-US', opts).replace(',', ' •');
    }
    tick();
    setInterval(tick, 1000);
  }

  /* ---------- Connection indicators (IoT/GPS only; WS visual removed) ---------- */
  const indicators = {
    iot: $('#connIoT'),
    gps: $('#connGPS'),
  };
  function setConn(key, online) {
    const el = indicators[key];
    if (!el) return;
    el.classList.toggle('online', online);
    el.classList.toggle('offline', !online);
    const dot = el.querySelector('.dot');
    const label = el.querySelector('.label');
    if (label) label.textContent = online ? (el.dataset.on || 'Online') : (el.dataset.off || 'Offline');
    void dot; // dot animation controlled by CSS
  }
  if (window.PedalyaStatus) {
    if (window.PedalyaStatus.iot !== undefined) setConn('iot', window.PedalyaStatus.iot);
    if (window.PedalyaStatus.gps !== undefined) setConn('gps', window.PedalyaStatus.gps);
  }

  /* ---------- Global search ---------- */
  const searchInput = $('#adminSearchInput');
  const searchResults = $('#adminSearchResults');
  if (searchInput && searchResults) {
    const navLinks = $$('.admin-nav__link[href]').map(a => ({
      href: a.href,
      label: a.querySelector('span') ? a.querySelector('span').textContent.trim() : a.dataset.tooltip,
      group: (a.closest('.admin-navgroup__label') ? a.closest('.admin-navgroup__label').textContent.trim() : '') || 'Navigation',
    }));

    function render(filter) {
      const q = (filter || '').toLowerCase();
      searchResults.innerHTML = '';
      const matches = navLinks.filter(i => !q || i.label.toLowerCase().includes(q));
      if (!matches.length) {
        searchResults.innerHTML = '<div class="admin-search__item"><i class="bi bi-search"></i>No results found</div>';
      }
      matches.slice(0, 12).forEach(i => {
        const a = document.createElement('a');
        a.className = 'admin-search__item';
        a.href = i.href;
        a.innerHTML = '<i class="bi bi-arrow-up-right"></i><span>' + i.label + '</span><span class="k">' + i.group + '</span>';
        a.addEventListener('click', () => { hide(); searchInput.value = ''; });
        searchResults.appendChild(a);
      });
      searchResults.classList.add('show');
    }
    function hide() { searchResults.classList.remove('show'); }
    searchInput.addEventListener('input', (e) => {
      if (e.target.value.trim()) render(e.target.value);
      else hide();
    });
    searchInput.addEventListener('focus', () => { if (searchInput.value.trim()) render(searchInput.value); });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.admin-search')) hide();
    });
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        searchInput.focus();
      }
      if (e.key === 'Escape') hide();
    });
  }

  /* ---------- Toast system ----------
     Compact dark overlay in the top-right corner. The stack is
     position:fixed, so a toast never shifts page content. Same
     PedalyaToast.success/error/warning/info API as before. */
  const toasts = $('#adminToasts');
  const TOAST_TYPES = ['success', 'error', 'warning', 'info'];
  function toast(title, msg, type) {
    if (!toasts) return;
    const variant = TOAST_TYPES.includes(type) ? type : 'info';
    const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
    const t = document.createElement('div');
    t.className = 'admin-toast admin-toast--' + variant;
    t.setAttribute('role', 'status');
    t.setAttribute('aria-live', 'polite');

    const iconWrap = document.createElement('span');
    iconWrap.className = 'admin-toast__iconwrap';
    const icon = document.createElement('i');
    icon.className = 'bi ' + (icons[variant] || icons.info) + ' admin-toast__icon';
    icon.setAttribute('aria-hidden', 'true');
    iconWrap.appendChild(icon);

    const body = document.createElement('div');
    body.className = 'admin-toast__body';
    const titleEl = document.createElement('div');
    titleEl.className = 'admin-toast__title';
    titleEl.textContent = title || '';
    const msgEl = document.createElement('div');
    msgEl.className = 'admin-toast__msg';
    msgEl.textContent = msg || '';
    body.appendChild(titleEl);
    body.appendChild(msgEl);

    const close = document.createElement('button');
    close.className = 'admin-toast__close';
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    const closeIcon = document.createElement('i');
    closeIcon.className = 'bi bi-x-lg';
    closeIcon.setAttribute('aria-hidden', 'true');
    close.appendChild(closeIcon);

    t.appendChild(iconWrap);
    t.appendChild(body);
    t.appendChild(close);
    toasts.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    let dismissed = false;
    const remove = () => {
      if (dismissed) return;
      dismissed = true;
      clearTimeout(timer);
      t.classList.remove('show');
      setTimeout(() => t.remove(), 320);
    };
    close.addEventListener('click', remove);
    const timer = setTimeout(remove, 5000);
  }
  window.PedalyaToast = { toast, success: (m, t) => toast(t || 'Success', m, 'success'), error: (m, t) => toast(t || 'Error', m, 'error'), warning: (m, t) => toast(t || 'Warning', m, 'warning'), info: (m, t) => toast(t || 'Info', m, 'info') };

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-payment-verify]');
    if (!form) return;

    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (!button || button.disabled) return;

    const originalContent = button.innerHTML;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Verifying...';

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
      });
      const result = await response.json().catch(() => null);

      if (!result) throw new Error('The server returned an invalid response. Please try again.');
      if (!response.ok) throw new Error(result.message || 'Payment verification failed.');
      if (typeof result.status !== 'string' || result.status.length === 0) {
        throw new Error('The server did not return a payment status. Please try again.');
      }

      toast('Payment status verified', 'Current status: ' + result.status, 'success');
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      toast('Payment verification failed', error instanceof Error ? error.message : 'Please try again.', 'error');
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
      button.innerHTML = originalContent;
      form.removeAttribute('data-submitting');
    }
  }, true);

  /* ---------- Confirm dialog ---------- */
  const confirmModal = $('#adminConfirm');
  const confirmBackdrop = confirmModal && $('.admin-modal__backdrop', confirmModal);
  let confirmAction = null;

  window.PedalyaConfirm = function (options) {
    if (!confirmModal) return Promise.resolve();
    return new Promise((resolve) => {
      $('#adminConfirmTitle').textContent = options.title || 'Are you sure?';
      $('#adminConfirmMsg').textContent = options.message || 'This action cannot be undone.';
      $('#adminConfirmOk').textContent = options.confirmText || 'Confirm';
      $('#adminConfirmOk').className = 'btn-admin ' + (options.danger ? 'btn-admin--danger' : 'btn-admin--primary');
      confirmModal.classList.add('open');
      confirmAction = resolve;
    });
  };
  function closeConfirm() {
    confirmModal.classList.remove('open');
    if (confirmAction) { const cb = confirmAction; confirmAction = null; cb(false); }
  }
  if (confirmModal) {
    $('#adminConfirmOk').addEventListener('click', () => {
      confirmModal.classList.remove('open');
      if (confirmAction) { const cb = confirmAction; confirmAction = null; cb(true); }
    });
    $('#adminConfirmCancel').addEventListener('click', closeConfirm);
    if (confirmBackdrop) confirmBackdrop.addEventListener('click', closeConfirm);
  }

  /* ---------- Duplicate-submit guard ----------
     A confirmation dialog can be opened more than once, and a user can click
     again while a request is still in flight. Both would post the same action
     twice (double-ending a ride, double-charging a rental, ...), so every form
     is locked the moment it is submitted and stays locked for the page. */
  function lockFormForSubmit(form) {
    if (!form || form.dataset.submitting === '1') return false;
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach((btn) => {
      btn.disabled = true;
      btn.classList.add('is-loading');
    });
    return true;
  }
  document.addEventListener('submit', (e) => lockFormForSubmit(e.target), true);

  /* Confirm on [data-confirm] links/buttons */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el) return;
    // Already awaiting an answer for this control: ignore the extra click so a
    // single gesture can never queue two confirmations.
    if (el.dataset.confirmPending === '1') return;
    e.preventDefault();
    const form = el.closest('form');
    el.dataset.confirmPending = '1';
    PedalyaConfirm({
      title: el.dataset.confirmTitle || 'Are you sure?',
      message: el.dataset.confirm || 'This action cannot be undone.',
      confirmText: el.dataset.confirmOk || 'Confirm',
      danger: el.dataset.confirmDanger !== 'false',
    }).then(ok => {
      delete el.dataset.confirmPending;
      if (!ok) return;
      if (form) {
        // requestSubmit() (not form.submit()) so the capture-phase guard above
        // still runs and native constraint validation still applies.
        if (form.dataset.submitting === '1') return;
        form.requestSubmit();
      } else {
        window.location = el.href;
      }
    });
  });

  /* ---------- Modal / drawer helpers ---------- */
  window.PedalyaModal = {
    open: (id) => { const m = document.getElementById(id); if (m) m.classList.add('open'); },
    close: (id) => { const m = document.getElementById(id); if (m) m.classList.remove('open'); },
  };
  window.PedalyaDrawer = {
    open: (id) => { const d = document.getElementById(id); if (d) d.classList.add('open'); },
    close: (id) => { const d = document.getElementById(id); if (d) d.classList.remove('open'); },
  };
  document.addEventListener('click', (e) => {
    const close = e.target.closest('[data-modal-close]');
    if (close) { const modal = close.closest('.admin-modal'); if (modal) modal.classList.remove('open'); }
    const drawer = e.target.closest('[data-drawer-close]');
    if (drawer) { const d = drawer.closest('.admin-drawer'); if (d) d.classList.remove('open'); }
  });

  /* ---------- Shared End Ride dialog ----------
     Opener carries only the rental it acts on; the dialog itself lives once
     per page. Pointing the form at the rental and filling in the context keeps
     the markup out of every table row, and reusing one dialog means the action
     is identical wherever it is offered. */
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-end-ride]');
    if (!opener) return;
    const modal = document.getElementById('endRideModal');
    const form = document.getElementById('endRideForm');
    if (!modal || !form) return;

    const id = opener.dataset.rentalId;
    if (!id) return;
    if (!form.dataset.endRideUrlTemplate) {
      form.dataset.endRideUrlTemplate = form.getAttribute('action') || '';
    }

    // Reset per-rental state so a second open never shows the previous note.
    form.dataset.submitting = '';
    form.removeAttribute('data-submitting');
    form.querySelectorAll('button[type="submit"]').forEach((b) => {
      b.disabled = false;
      b.classList.remove('is-loading');
    });
    form.action = form.dataset.endRideUrlTemplate.replace(/__RENTAL_ID__/g, id);

    const set = (elId, value) => { const node = document.getElementById(elId); if (node) node.textContent = value || ''; };
    set('endRideCode', opener.dataset.rentalCode);
    set('endRideBike', opener.dataset.bike);
    set('endRideRider', opener.dataset.rider);

    const condition = document.getElementById('endRideCondition');
    if (condition) condition.value = 'good';
    const note = document.getElementById('endRideNote');
    if (note) note.value = '';

    modal.classList.add('open');
    if (condition) setTimeout(() => condition.focus(), 40);
  });

  /* ---------- Tabs ---------- */
  document.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');
    if (!tab) return;
    const scope = tab.closest('[data-tabscope]') || document;
    $$('[data-tab]', scope).forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    $$('[data-tabpanel]', scope).forEach(p => p.classList.toggle('active', p.dataset.tabpanel === tab.dataset.tab));
  });

  /* ---------- Table enhancements ---------- */
  function initTables(scope) {
    $$('.admin-table', scope).forEach(table => {
      const wrap = table.closest('.admin-table-wrap');
      if (!wrap) return;

      /* Select all + row selection */
      const selectAll = table.querySelector('thead input.admin-check');
      const checkboxes = $$('tbody input.admin-check', table);
      const bulk = wrap.querySelector('.admin-bulk');
      const bulkCount = bulk && bulk.querySelector('.admin-bulk__count');
      if (selectAll) {
        selectAll.addEventListener('change', () => {
          checkboxes.forEach(c => { c.checked = selectAll.checked; c.closest('tr').classList.toggle('selected', c.checked); });
          updateBulk();
        });
      }
      checkboxes.forEach(c => c.addEventListener('change', () => {
        c.closest('tr').classList.toggle('selected', c.checked);
        if (selectAll) selectAll.checked = checkboxes.every(x => x.checked);
        updateBulk();
      }));
      function updateBulk() {
        const n = checkboxes.filter(c => c.checked).length;
        if (bulk && bulkCount) {
          bulkCount.textContent = n + ' selected';
          bulk.classList.toggle('show', n > 0);
        }
      }
      if (bulk) {
        const clear = bulk.querySelector('[data-bulk-clear]');
        if (clear) clear.addEventListener('click', () => {
          checkboxes.forEach(c => { c.checked = false; c.closest('tr').classList.remove('selected'); });
          if (selectAll) selectAll.checked = false;
          updateBulk();
        });
      }

      /* Client-side search */
      const search = wrap.querySelector('[data-table-search]');
      if (search) {
        search.addEventListener('input', () => {
          const q = search.value.toLowerCase();
          $$('tbody tr', table).forEach(tr => {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
          });
        });
      }

      /* Client-side sort */
      $$('thead th.sortable', table).forEach(th => {
        th.addEventListener('click', () => {
          const idx = $$('thead th', table).indexOf(th);
          const rows = $$('tbody tr', table);
          const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
          $$('thead th', table).forEach(h => { h.dataset.dir = ''; h.querySelector('.sort-ind').textContent = ''; });
          th.dataset.dir = dir;
          th.querySelector('.sort-ind').textContent = dir === 'asc' ? '↑' : '↓';
          rows.sort((a, b) => {
            const av = $$('td', a)[idx] ? $$('td', a)[idx].textContent.trim() : '';
            const bv = $$('td', b)[idx] ? $$('td', b)[idx].textContent.trim() : '';
            const num = /^-?\d[\d.,]*$/.test(av) && /^-?\d[\d.,]*$/.test(bv);
            const cmp = num ? parseFloat(av) - parseFloat(bv) : av.localeCompare(bv);
            return dir === 'asc' ? cmp : -cmp;
          });
          rows.forEach(r => table.querySelector('tbody').appendChild(r));
        });
      });

      /* Pagination */
      const pager = wrap.querySelector('[data-pager]');
      const pageSizeInput = wrap.querySelector('[data-page-size]');
      if (pager) {
        const size = pageSizeInput ? parseInt(pageSizeInput.value, 10) || 10 : 10;
        let page = 0;
        function renderPager() {
          const rows = $$('tbody tr', table).filter(r => r.style.display !== 'none');
          const pages = Math.max(1, Math.ceil(rows.length / size));
          page = Math.min(page, pages - 1);
          rows.forEach((r, i) => { r.style.display = (i >= page * size && i < (page + 1) * size) ? '' : 'none'; });
          pager.innerHTML = '';
          const btn = (label, disabled, active, cb) => {
            const b = document.createElement('button');
            b.innerHTML = label;
            b.disabled = !!disabled;
            if (active) b.className = 'active';
            b.addEventListener('click', cb);
            pager.appendChild(b);
          };
          btn('«', page === 0, false, () => { page = 0; renderPager(); });
          btn('‹', page === 0, false, () => { page--; renderPager(); });
          for (let i = 0; i < pages; i++) {
            if (pages > 7 && i !== 0 && i !== pages - 1 && Math.abs(i - page) > 1) {
              if (Math.abs(i - page) === 2) btn('…', true, false, () => {});
              continue;
            }
            btn(String(i + 1), false, i === page, () => { page = i; renderPager(); });
          }
          btn('›', page >= pages - 1, false, () => { page++; renderPager(); });
          btn('»', page >= pages - 1, false, () => { page = pages - 1; renderPager(); });
        }
        renderPager();
        if (pageSizeInput) pageSizeInput.addEventListener('change', () => renderPager());
      }
    });
  }
  initTables(document);
  window.PedalyaTableInit = initTables;

  /* ---------- Row actions menu (three dots) ----------
     A menu inside a table cell is positioned by Popper, but every container
     between the cell and the card hides its overflow (the cell for
     truncation, the scroll wrapper, the rounded card), so an absolutely
     positioned menu was cut off after the first few items. Creating each menu
     with Popper's "fixed" strategy places it against the viewport instead: it
     opens above or below the row depending on the room it has, stays inside the
     window, and no ancestor can clip it. Bootstrap keeps owning the behaviour -
     the button toggles only its own menu, a click outside closes it and opening
     another row's menu closes the previous one. */
  function initRowMenus(root) {
    if (!window.bootstrap || !window.bootstrap.Dropdown) return;
    $$('[data-row-menu]', root).forEach((el) => {
      if (el.dataset.rowMenuReady === '1') return;
      el.dataset.rowMenuReady = '1';
      el.pedalyaMenu = window.bootstrap.Dropdown.getOrCreateInstance(el, {
        popperConfig: (defaults) => ({
          ...defaults,
          strategy: 'fixed',
          // Keep the menu inside the window; the card is not a valid boundary
          // because the menu is meant to extend past it.
          modifiers: (defaults.modifiers || []).map((modifier) => (
            modifier.name === 'preventOverflow'
              ? { ...modifier, options: { ...modifier.options, boundary: 'viewport', rootBoundary: 'viewport' } }
              : modifier
          )),
        }),
      });
      el.addEventListener('show.bs.dropdown', () => {
        const table = el.closest('table') || document;
        $$('[data-row-menu].show', table).forEach((other) => {
          if (other !== el && other.pedalyaMenu) other.pedalyaMenu.hide();
        });
      });
    });
  }
  initRowMenus(document);
  window.PedalyaRowMenuInit = initRowMenus;

  /* A viewport-positioned menu would drift away from its button while the page
     or the table scrolls under it, so any open menu is re-placed on the next
     frame instead. Captured so a scrolling table container counts too. */
  let rowMenuFrame = 0;
  const repositionRowMenus = () => {
    rowMenuFrame = 0;
    $$('[data-row-menu].show').forEach((el) => {
      if (el.pedalyaMenu) el.pedalyaMenu.update();
    });
  };
  const scheduleRowMenuUpdate = () => {
    if (!rowMenuFrame) rowMenuFrame = window.requestAnimationFrame(repositionRowMenus);
  };
  window.addEventListener('scroll', scheduleRowMenuUpdate, { passive: true, capture: true });
  window.addEventListener('resize', scheduleRowMenuUpdate, { passive: true });

  /* ---------- Auto-dismiss alerts ---------- */
  setTimeout(() => {
    $$('.alert-pedalya').forEach(a => a.classList.add('fade'));
  }, 5000);
})();
