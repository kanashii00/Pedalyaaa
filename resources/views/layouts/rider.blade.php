@extends('layouts.app')

@php
    use App\Services\NotificationService;
    use App\Support\NotificationType;

    $notificationService = app(NotificationService::class);
    $riderUnreadCount = auth()->check() ? $notificationService->getUnreadCount(auth()->id()) : 0;
    $riderRecentNotifs = auth()->check() ? $notificationService->getRecentForUser(auth()->id()) : collect();
@endphp

@section('body')
<div>
    <div id="pedalyaToasts" class="pedalya-toast-stack" aria-live="polite" aria-atomic="true"></div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo-wrapper">
                <img src="{{ asset('assets/img/Logo.png') }}" alt="Pedalya" class="sidebar-logo-img">
            </div>
            <span class="sidebar-brand-text"><span>Peda</span>lya</span>
            <button class="sidebar-toggle sidebar-toggle--panel" type="button" data-sidebar-toggle
                    aria-controls="sidebar" aria-expanded="true" aria-label="Collapse sidebar" data-state="expanded">
                <i class="bi bi-chevron-left icon-collapse"></i>
                <i class="bi bi-chevron-right icon-expand"></i>
            </button>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Menu</div>
            <div class="nav-item">
                <a href="{{ route('rider.dashboard') }}" class="nav-link {{ request()->routeIs('rider.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="{{ route('rider.rentals.create') }}" class="nav-link {{ request()->routeIs('rider.rentals.create') ? 'active' : '' }}">
                    <i class="bi bi-bicycle"></i>
                    <span>Rent Bicycle</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="{{ route('rider.rentals.index') }}" class="nav-link {{ request()->routeIs('rider.rentals.index') ? 'active' : '' }}">
                    <i class="bi bi-key-fill"></i>
                    <span>My Rentals</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="{{ route('rider.notifications.index') }}" class="nav-link {{ request()->routeIs('rider.notifications.*') ? 'active' : '' }}">
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>
                    <span id="sidebarNotifBadge" class="notification-nav-badge" style="{{ $riderUnreadCount > 0 ? '' : 'display:none;' }}">{{ $riderUnreadCount > 99 ? '99+' : $riderUnreadCount }}</span>
                </a>
            </div>

            <div class="nav-section">Account</div>
            <div class="nav-item">
                <a href="{{ route('rider.profile.index') }}" class="nav-link {{ request()->routeIs('rider.profile.*') ? 'active' : '' }}">
                    <i class="bi bi-person-fill"></i>
                    <span>Profile</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle sidebar-toggle--mobile" type="button" data-sidebar-toggle
                        aria-controls="sidebar" aria-expanded="false" aria-label="Open sidebar" data-state="collapsed">
                    <i class="bi bi-list"></i>
                </button>
            </div>
            <div class="topbar-right">
                <div class="admin-dropdown">
                    <button class="admin-icon-btn" type="button" data-dropdown-toggle aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                        <span id="topbarNotifBadge" data-notif-badge class="notification-badge-corner" style="{{ $riderUnreadCount > 0 ? '' : 'display:none;' }}">{{ $riderUnreadCount > 99 ? '99+' : $riderUnreadCount }}</span>
                    </button>
                    <div class="admin-dropdown__menu">
                        <div class="admin-dropdown__head">
                            <span>Notifications</span>
                            <span class="badge-admin badge-admin--neutral badge-admin--plain" id="notifUnreadLabel" data-notif-unread-label>{{ $riderUnreadCount }} unread</span>
                        </div>
                        <div class="admin-dropdown__body">
                            @forelse($riderRecentNotifs as $n)
                                <a href="{{ route('rider.notifications.index') }}" class="admin-notif {{ !$n->read ? 'unread' : '' }}">
                                    <div class="admin-notif__icon" style="background: {{ NotificationType::accent($n->type) }}22; color: {{ NotificationType::accent($n->type) }};">
                                        <i class="bi {{ NotificationType::icon($n->type) }}"></i>
                                    </div>
                                    <div>
                                        <div class="admin-notif__title">{{ $n->title }}</div>
                                        <div class="admin-notif__msg">{{ \Illuminate\Support\Str::limit($n->message, 60) }}</div>
                                        <div class="admin-notif__time">{{ $n->created_at->diffForHumans() }}</div>
                                    </div>
                                </a>
                            @empty
                                <div class="admin-empty">
                                    <i class="bi bi-bell-slash"></i>
                                    <h4>All caught up</h4>
                                    <p>No notifications yet.</p>
                                </div>
                            @endforelse
                        </div>
                        <div class="admin-dropdown__foot" style="display:flex;gap:8px;">
                            <button type="button" class="btn-admin btn-admin--secondary btn-admin--sm" data-mark-all-read>Mark all read</button>
                            <a href="{{ route('rider.notifications.index') }}" class="btn-admin btn-admin--secondary btn-admin--sm">View all notifications</a>
                        </div>
                    </div>
                </div>
                <div class="user-info">
                    <div class="user-avatar">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <div>
                        <div class="user-name">{{ auth()->user()->name }}</div>
                        <div class="user-role">Rider</div>
                    </div>
                </div>
                <form action="{{ route('logout') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn-logout">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </button>
                </form>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            @if(session('error'))
                <div class="alert alert-pedalya alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-pedalya alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <strong>Please correct the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @yield('content')
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const overlay = document.getElementById('sidebarOverlay');
        const toggles = document.querySelectorAll('[data-sidebar-toggle]');

        function isMobile() {
            return window.innerWidth <= 991;
        }

        function syncToggles() {
            const collapsed = isMobile()
                ? !sidebar.classList.contains('show')
                : sidebar.classList.contains('collapsed');

            toggles.forEach(function(btn) {
                btn.setAttribute('data-state', collapsed ? 'collapsed' : 'expanded');
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                btn.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
            });
        }

        function toggleSidebar() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            syncToggles();
        }

        function collapseSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            syncToggles();
        }

        toggles.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (isMobile()) {
                    toggleSidebar();
                } else {
                    collapseSidebar();
                }
            });
        });

        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
                syncToggles();
            });
        }

        if (localStorage.getItem('sidebarCollapsed') === 'true' && !isMobile()) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        syncToggles();

        window.addEventListener('resize', syncToggles);
    });
</script>

<script>
    window.PedalyaChannels = { notifications: {!! json_encode('private-App.Models.User.' . auth()->id()) !!} };
    window.PedalyaSettings = {
        unreadCountUrl: @json(route('rider.notifications.unread-count')),
        markAllReadUrl: @json(route('rider.notifications.mark-all-read')),
        markReadUrl: @json(route('rider.notifications.mark-read', ['id' => '__ID__'])),
    };
</script>
<script src="{{ asset('js/notifications.js') }}"></script>
{{-- Success flashes surface as an auto-dismissing toast (bottom-right,
      bottom-center on mobile) instead of a full-width banner, so the message
      never pushes or resizes page content. The shared toast runtime renders
      the success icon, the slide/fade animation and the close button; the
      message is JSON-encoded and injected as text, never as HTML. --}}
@if(session('success'))
<script>
    (function () {
        function showSuccessToast() {
            if (window.PedalyaNotifications && window.PedalyaNotifications.toast) {
                window.PedalyaNotifications.toast(@json(session('success')), 'success', 'Success');
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showSuccessToast);
        } else {
            showSuccessToast();
        }
    })();
</script>
@endif
@endsection
