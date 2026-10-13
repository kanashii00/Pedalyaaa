@extends('layouts.app')

@php
    use App\Services\NotificationService;
    use App\Support\NotificationType;

    $notificationService = app(NotificationService::class);
    $riderUnreadCount = auth()->check() ? $notificationService->getUnreadCount(auth()->id()) : 0;
    $riderRecentNotifs = auth()->check() ? $notificationService->getRecentForUser(auth()->id()) : collect();

    $riderNav = [
        ['section' => 'Menu'],
        [
            'label' => 'Dashboard',
            'icon' => 'bi-grid-1x2-fill',
            'url' => route('rider.dashboard'),
            'active' => request()->routeIs('rider.dashboard'),
        ],
        [
            'label' => 'Rent Bicycle',
            'icon' => 'bi-bicycle',
            'url' => route('rider.rentals.create'),
            'active' => request()->routeIs('rider.rentals.create', 'rider.rentals.extend', 'rider.rent.store'),
        ],
        [
            'label' => 'My Rentals',
            'icon' => 'bi-key-fill',
            'url' => route('rider.rentals.index'),
            'active' => request()->routeIs('rider.rentals.index', 'rider.rentals.active-status'),
        ],
        [
            'label' => 'Notifications',
            'icon' => 'bi-bell-fill',
            'url' => route('rider.notifications.index'),
            'active' => request()->routeIs('rider.notifications.*'),
            'badge' => true,
        ],
        ['section' => 'Account'],
        [
            'label' => 'Profile',
            'icon' => 'bi-person-fill',
            'url' => route('rider.profile.index'),
            'active' => request()->routeIs('rider.profile.*'),
        ],
    ];
@endphp

@section('body')
<div>
    <div id="pedalyaToasts" class="pedalya-toast-stack" aria-live="polite" aria-atomic="true"></div>

    <!-- Sidebar backdrop (mobile drawer) -->
    <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

    <!-- Sidebar: Classroom-style rail/drawer, Pedalya green branding -->
    <aside class="sidebar" id="sidebar" aria-label="Renter sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo-wrapper" aria-hidden="true">
                <img src="{{ asset('assets/img/Logo.png') }}" alt="Pedalya" class="sidebar-logo-img">
            </div>
            <span class="sidebar-brand-text"><span>Peda</span>lya</span>
        </div>
        <nav class="sidebar-nav" aria-label="Renter navigation">
            {{-- Single sliding active indicator (positioned by rider-sidebar.js
                  from the server-rendered aria-current link). --}}
            <span class="nav-indicator" aria-hidden="true"></span>
            @foreach($riderNav as $item)
                @if(isset($item['section']))
                    <div class="nav-section" aria-hidden="true">{{ $item['section'] }}</div>
                @else
                    <div class="nav-item">
                        <a href="{{ $item['url'] }}"
                           class="nav-link {{ $item['active'] ? 'active' : '' }}"
                           data-label="{{ $item['label'] }}"
                           aria-label="{{ $item['label'] }}"
                           @if($item['active']) aria-current="page" @endif>
                            <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                            <span>{{ $item['label'] }}</span>
                            @if(!empty($item['badge']))
                                <span id="sidebarNotifBadge" class="notification-nav-badge" style="{{ $riderUnreadCount > 0 ? '' : 'display:none;' }}">{{ $riderUnreadCount > 99 ? '99+' : $riderUnreadCount }}</span>
                            @endif
                        </a>
                    </div>
                @endif
            @endforeach
        </nav>
    </aside>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                {{-- Dual-mode hamburger: click pins/unpins the expanded
                      sidebar on desktop, opens the drawer on mobile. --}}
                <button class="sidebar-toggle sidebar-toggle--topbar" type="button" data-sidebar-toggle
                        aria-controls="sidebar" aria-expanded="false" aria-label="Expand sidebar" title="Expand sidebar" data-state="collapsed">
                    <i class="bi bi-list" aria-hidden="true"></i>
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
                    <div class="user-avatar" aria-hidden="true">
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
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Logout
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

{{-- Sidebar behavior lives in public/js/rider-sidebar.js (shared by every
      renter page) so there is exactly one toggle handler per page load. --}}
<script src="{{ asset('js/rider-sidebar.js') }}"></script>

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
