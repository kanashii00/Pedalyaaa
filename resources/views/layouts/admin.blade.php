@extends('layouts.app')

@section('title', ($currentPage['title'] ?? 'Dashboard') . ' — Pedalya Admin')
@section('bodyClass', 'admin-shell')

@php
    use App\Models\Accident;
    use App\Models\Bicycle;
    use App\Models\MaintenanceRecord;
    use App\Models\Rental;
    use App\Models\User;
    use App\Services\NotificationService;
    use App\Support\NotificationType;

    $notificationService = app(NotificationService::class);
    $unreadNotifs = $notificationService->getUnreadCount(auth()->id());
    // Same query the Active Rentals page lists from, so the badge is the queue
    // depth and nothing else. It used to count unread `rental_started`
    // notifications, which is why it read 49 next to a single rental row.
    $activeRentalsCount = Rental::activeListCount();
    $recentNotifs = $notificationService->getRecentForUser(auth()->id());
    $unackTheft = Accident::where('type', 'theft')->where('acknowledged', false)->count();
    $unackAccidents = Accident::whereIn('type', ['accident', 'impact_detected'])->where('acknowledged', false)->count();
    $pendingMaint = MaintenanceRecord::whereIn('status', ['scheduled', 'in_progress'])->count();
    $blacklistedCount = User::where('role', User::ROLE_RIDER)
        ->whereIn('status', [User::STATUS_BLACKLISTED, User::STATUS_SUSPENDED])
        ->count();

    $iotOnline = Bicycle::where('lastHeartbeat', '>=', now()->subMinutes(5))->count();
    $gpsOnline = Bicycle::where('lastGpsUpdate', '>=', now()->subMinutes(5))->count();

    $currentSection = in_array(request()->query('section'), ['gps', 'locks', 'devices'], true)
        ? request()->query('section')
        : 'gps';

    $navGroups = [
        [
            'label' => 'Dashboard',
            'icon' => 'bi-grid-1x2',
            'items' => [
                ['title' => 'Dashboard Overview', 'icon' => 'bi-grid-1x2', 'route' => 'admin.dashboard', 'active' => ['admin.dashboard']],
                ['title' => 'Analytics', 'icon' => 'bi-graph-up-arrow', 'url' => route('admin.dashboard') . '#analytics', 'active' => []],
            ],
        ],
        [
            'label' => 'Customer Management',
            'icon' => 'bi-people',
            'items' => [
                ['title' => 'Customer List', 'icon' => 'bi-person-lines-fill', 'route' => 'admin.riders.index', 'active' => ['admin.riders.index']],
                ['title' => 'Register Customer', 'icon' => 'bi-person-plus', 'route' => 'admin.riders.create', 'active' => ['admin.riders.create']],
                ['title' => 'Verified Customers', 'icon' => 'bi-patch-check', 'route' => 'admin.riders.verified', 'active' => ['admin.riders.verified']],
                ['title' => 'Blacklisted Customers', 'icon' => 'bi-x-octagon', 'route' => 'admin.riders.blacklisted', 'active' => ['admin.riders.blacklisted'], 'badge' => $blacklistedCount, 'badgeType' => 'danger'],
            ],
        ],
        [
            'label' => 'Bicycle Management',
            'icon' => 'bi-bicycle',
            'items' => [
                ['title' => 'Bicycle Inventory', 'icon' => 'bi-bicycle', 'route' => 'admin.bicycles.index', 'active' => ['admin.bicycles.index']],
                ['title' => 'Add Bicycle', 'icon' => 'bi-plus-circle', 'route' => 'admin.bicycles.create', 'active' => ['admin.bicycles.create']],
                ['title' => 'Bicycle Status', 'icon' => 'bi-speedometer', 'route' => 'admin.bicycles.status', 'active' => ['admin.bicycles.status']],
                ['title' => 'Maintenance Schedule', 'icon' => 'bi-tools', 'route' => 'admin.maintenance.index', 'active' => ['admin.maintenance.*'], 'badge' => $pendingMaint],
            ],
        ],
        [
            'label' => 'Rental Management',
            'icon' => 'bi-key',
            'items' => [
                ['title' => 'Active Rentals', 'icon' => 'bi-play-circle', 'route' => 'admin.rentals.index', 'active' => ['admin.rentals.index'], 'badge' => $activeRentalsCount, 'badgeId' => 'sidebarActiveRentalsBadge', 'badgeType' => 'warning'],
                ['title' => 'Rental History', 'icon' => 'bi-clock-history', 'route' => 'admin.rentals.history', 'active' => ['admin.rentals.history']],
                ['title' => 'Returns', 'icon' => 'bi-arrow-return-left', 'route' => 'admin.rentals.returns', 'active' => ['admin.rentals.returns']],
                ['title' => 'Rental Requests', 'icon' => 'bi-inbox', 'route' => 'admin.rentals.index', 'active' => ['admin.rentals.index'], 'query' => '?filter=pending'],
            ],
        ],
        [
            'label' => 'Incidents & Alerts',
            'icon' => 'bi-shield-check',
            'items' => [
                ['title' => 'Accident Monitoring', 'icon' => 'bi-activity', 'route' => 'admin.accidents.index', 'active' => ['admin.accidents.*'], 'badge' => $unackAccidents, 'badgeType' => 'warning'],
                ['title' => 'Notifications', 'icon' => 'bi-bell', 'route' => 'admin.notifications.index', 'active' => ['admin.notifications.*'], 'badge' => $unreadNotifs, 'badgeId' => 'sidebarNotifBadge'],
                [
                    'label' => 'Monitoring',
                    'title' => 'Monitoring',
                    'icon' => 'bi-grid',
                    'active' => ['admin.monitoring.*', 'admin.geofence.*', 'admin.theft-alerts.*'],
                    'items' => [
                        ['title' => 'Live GPS Tracking', 'icon' => 'bi-geo-alt', 'route' => 'admin.monitoring.index', 'section' => 'gps', 'sub' => 'Real-time positions', 'active' => ['admin.monitoring.index']],
                        ['title' => 'Circular Geofence', 'icon' => 'bi-bounding-box-circles', 'route' => 'admin.geofence.index', 'sub' => 'Azuela Cove riding zone', 'active' => ['admin.geofence.*']],
                        ['title' => 'Smart Lock Control', 'icon' => 'bi-lock', 'route' => 'admin.monitoring.index', 'section' => 'locks', 'query' => '?section=locks', 'sub' => 'Remote lock / unlock', 'active' => ['admin.monitoring.index']],
                        ['title' => 'IoT Device Monitoring', 'icon' => 'bi-cpu', 'route' => 'admin.monitoring.index', 'section' => 'devices', 'query' => '?section=devices', 'sub' => 'Firmware & telemetry', 'active' => ['admin.monitoring.index']],
                        ['title' => 'Theft Detection', 'icon' => 'bi-shield-exclamation', 'route' => 'admin.theft-alerts.index', 'sub' => 'Alerts & incident log', 'badge' => $unackTheft, 'badgeType' => 'danger', 'active' => ['admin.theft-alerts.*']],
                    ],
                ],
            ],
        ],
        [
            'label' => 'Reports',
            'icon' => 'bi-file-earmark-bar-graph',
            'items' => [
                ['title' => 'Customer Reports', 'icon' => 'bi-people', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=customer'],
                ['title' => 'Rental Reports', 'icon' => 'bi-bicycle', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=rental'],
                ['title' => 'Bicycle Reports', 'icon' => 'bi-speedometer', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=bicycle'],
                ['title' => 'Theft Reports', 'icon' => 'bi-shield-exclamation', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=theft'],
                ['title' => 'Accident Reports', 'icon' => 'bi-activity', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=accident', 'badge' => $unackAccidents, 'badgeType' => 'warning'],
                ['title' => 'Revenue Reports', 'icon' => 'bi-currency-dollar', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=revenue'],
                ['title' => 'Export Reports', 'icon' => 'bi-download', 'route' => 'admin.reports.index', 'active' => ['admin.reports.*'], 'query' => '?tab=export'],
            ],
        ],
        [
            'label' => 'Administration',
            'icon' => 'bi-gear',
            'items' => [
                ['title' => 'User Management', 'icon' => 'bi-person-gear', 'route' => 'admin.riders.index', 'active' => ['admin.riders.index'], 'query' => '?tab=users'],
                ['title' => 'Roles and Permissions', 'icon' => 'bi-shield-lock', 'route' => 'admin.settings.index', 'active' => ['admin.settings.*'], 'query' => '?tab=roles'],
                ['title' => 'Audit Logs', 'icon' => 'bi-journal-text', 'route' => 'admin.audit-log.index', 'active' => ['admin.audit-log.index']],
                ['title' => 'Activity Logs', 'icon' => 'bi-activity', 'route' => 'admin.audit-log.index', 'active' => ['admin.audit-log.index'], 'query' => '?tab=activity'],
                ['title' => 'System Settings', 'icon' => 'bi-gear-wide-connected', 'route' => 'admin.settings.index', 'active' => ['admin.settings.*']],
            ],
        ],
    ];

    // Flatten all leaf nav links for sibling detection (excludes parent group items)
    $allNavLinks = [];
    foreach ($navGroups as $group) {
        foreach ($group['items'] as $item) {
            if (isset($item['items'])) {
                foreach ($item['items'] as $subItem) { $allNavLinks[] = $subItem; }
            } else {
                $allNavLinks[] = $item;
            }
        }
    }

    $isActive = function ($item) use ($currentSection, $allNavLinks) {
        if (!isset($item['active'])) return false;

        $routeMatch = collect($item['active'])->contains(fn ($p) => request()->routeIs($p));
        if (!$routeMatch) return false;

        // Section check (monitoring sub-items: map / gps / locks / devices)
        if (isset($item['section']) && $currentSection !== $item['section']) return false;

        // Item declares specific query params → every key-value must match
        if (isset($item['query'])) {
            $expected = [];
            parse_str(ltrim($item['query'], '?'), $expected);
            foreach ($expected as $k => $v) {
                if ((string) request()->query($k) !== (string) $v) return false;
            }
            return true;
        }

        // Default item (no query) → active only when NO sibling with the same
        // route patterns has its query params satisfied by the current request.
        $patterns = $item['active'];
        foreach ($allNavLinks as $sibling) {
            if ($sibling === $item) continue;
            if (!isset($sibling['query'])) continue;
            if (($sibling['active'] ?? []) !== $patterns) continue;
            $siblingParams = [];
            parse_str(ltrim($sibling['query'], '?'), $siblingParams);
            $allMatch = true;
            foreach ($siblingParams as $k => $v) {
                if ((string) request()->query($k) !== (string) $v) { $allMatch = false; break; }
            }
            if ($allMatch) return false;
        }
        return true;
    };

    $currentPage = null;
    $currentGroup = null;
    foreach ($navGroups as $group) {
        foreach ($group['items'] as $item) {
            // Check top-level items
            if ($isActive($item)) { $currentPage = $item; $currentGroup = $group['label']; break 2; }
            // Check nested items (sub-groups)
            if (isset($item['items'])) {
                foreach ($item['items'] as $subItem) {
                    if ($isActive($subItem)) { $currentPage = $subItem; $currentGroup = $group['label']; break 3; }
                }
            }
        }
    }
    if (!$currentPage) { $currentPage = ['title' => 'Dashboard', 'icon' => 'bi-grid-1x2']; $currentGroup = 'Dashboard'; }
@endphp

@section('body')

{{-- Mobile drawer overlay / backdrop (touch devices, no hover) --}}
<div class="sidebar-overlay" id="adminSidebarOverlay" aria-hidden="true"></div>

{{-- SIDEBAR --}}
<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin sidebar">
    <div class="admin-sidebar__brand">
        <div class="admin-sidebar__logo" aria-hidden="true">
            <img src="{{ asset('assets/img/Logo.png') }}" alt="Pedalya" class="admin-sidebar__logo-img">
        </div>
        <div class="admin-sidebar__name">Peda<span>lya</span></div>
    </div>

    <nav class="admin-sidebar__nav" id="adminSidebarNav" aria-label="Admin navigation">
        {{-- Single sliding active indicator (positioned by admin.js from the
              server-rendered aria-current link, same pattern as rider-sidebar.js). --}}
        <span class="admin-nav-indicator" aria-hidden="true"></span>
        @foreach ($navGroups as $group)
            @php $groupLabel = $group['label']; @endphp
            <div class="admin-navgroup__label">{{ $groupLabel }}</div>

            @php
                $hasNested = false;
                foreach ($group['items'] as $item) {
                    if (isset($item['items'])) { $hasNested = true; break; }
                }
                $isSingleItem = count($group['items']) === 1 && !$hasNested;
            @endphp

            @if($hasNested)
                {{-- Group with nested sub-groups (e.g., Incidents & Alerts with Monitoring) --}}
                @foreach ($group['items'] as $item)
                    @if(isset($item['items']))
                        {{-- Nested sub-group --}}
                        @php $subGroupOpen = collect($item['items'])->contains($isActive); @endphp
                        <div class="admin-nav {{ $subGroupOpen ? 'admin-nav--open' : '' }}" data-collapsible>
                            <button type="button" class="admin-nav__link" data-tooltip="{{ $item['title'] }}">
                                <i class="bi {{ $item['icon'] }}"></i>
                                <span>{{ $item['title'] }}</span>
                                <i class="bi bi-chevron-down admin-nav__toggle"></i>
                            </button>
                            <div class="admin-nav__sub">
                                @foreach ($item['items'] as $subItem)
                                    @php
                                        $subHref = isset($subItem['route']) ? route($subItem['route']) : ($subItem['url'] ?? '#');
                                        if (isset($subItem['query'])) $subHref .= $subItem['query'];
                                    @endphp
                                    <a href="{{ $subHref }}"
                                       class="admin-nav__link {{ $isActive($subItem) ? 'active' : '' }}"
                                       data-tooltip="{{ $subItem['title'] }}"
                                       aria-current="{{ $isActive($subItem) ? 'page' : 'false' }}">
                                        <i class="bi {{ $subItem['icon'] }}"></i>
                                        <span>{{ $subItem['title'] }}</span>
                                        @if(isset($subItem['badge']))
                                            <span id="{{ $subItem['badgeId'] ?? '' }}" class="admin-nav__badge {{ isset($subItem['badgeType']) ? 'admin-nav__badge--' . $subItem['badgeType'] : '' }}" style="{{ $subItem['badge'] > 0 ? '' : 'display:none;' }}">{{ $subItem['badge'] > 99 ? '99+' : $subItem['badge'] }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        {{-- Top-level item in a group that has nested sub-groups --}}
                        @php
                            $href = isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#');
                            if (isset($item['query'])) $href .= $item['query'];
                        @endphp
                        <a href="{{ $href }}"
                           class="admin-nav__link {{ $isActive($item) ? 'active' : '' }}"
                           data-tooltip="{{ $item['title'] }}"
                           aria-current="{{ $isActive($item) ? 'page' : 'false' }}">
                            <i class="bi {{ $item['icon'] }}"></i>
                            <span>{{ $item['title'] }}</span>
                            @if(isset($item['badge']))
                                <span id="{{ $item['badgeId'] ?? '' }}" class="admin-nav__badge {{ isset($item['badgeType']) ? 'admin-nav__badge--' . $item['badgeType'] : '' }}" style="{{ $item['badge'] > 0 ? '' : 'display:none;' }}">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                            @endif
                        </a>
                    @endif
                @endforeach
            @elseif($isSingleItem)
                {{-- Single item group --}}
                @php
                    $singleItem = $group['items'][0];
                    $singleHref = isset($singleItem['route']) ? route($singleItem['route']) : ($singleItem['url'] ?? '#');
                    if (isset($singleItem['query'])) $singleHref .= $singleItem['query'];
                @endphp
                <a href="{{ $singleHref }}"
                   class="admin-nav__link {{ $isActive($singleItem) ? 'active' : '' }}"
                   data-tooltip="{{ $singleItem['title'] }}"
                   aria-current="{{ $isActive($singleItem) ? 'page' : 'false' }}">
                    <i class="bi {{ $singleItem['icon'] }}"></i>
                    <span>{{ $singleItem['title'] }}</span>
                    @if(isset($singleItem['badge']))
                        <span id="{{ $singleItem['badgeId'] ?? '' }}" class="admin-nav__badge {{ isset($singleItem['badgeType']) ? 'admin-nav__badge--' . $singleItem['badgeType'] : '' }}" style="{{ $singleItem['badge'] > 0 ? '' : 'display:none;' }}">{{ $singleItem['badge'] > 99 ? '99+' : $singleItem['badge'] }}</span>
                    @endif
                </a>
            @else
                {{-- Regular collapsible group --}}
                @php
                    $groupOpen = collect($group['items'])->contains($isActive);
                    // Aggregate badge for the collapsed icon rail: the per-item
                    // pills hide with their submenu there, so the group header
                    // surfaces their total on the icon corner instead.
                    $groupBadge = collect($group['items'])->sum(fn ($item) => isset($item['badge']) ? (int) $item['badge'] : 0);
                @endphp
                <div class="admin-nav {{ $groupOpen ? 'admin-nav--open' : '' }}" data-collapsible>
                    <button type="button" class="admin-nav__link" data-tooltip="{{ $group['label'] }}">
                        <i class="bi {{ $group['icon'] }}"></i>
                        <span>{{ $group['label'] }}</span>
                        <i class="bi bi-chevron-down admin-nav__toggle"></i>
                        @if($groupBadge > 0)
                            <span class="admin-nav__badge admin-nav__group-badge" aria-hidden="true">{{ $groupBadge > 99 ? '99+' : $groupBadge }}</span>
                        @endif
                    </button>
                    <div class="admin-nav__sub">
                        @foreach ($group['items'] as $item)
                            @php
                                $href = isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#');
                                if (isset($item['query'])) $href .= $item['query'];
                            @endphp
                            <a href="{{ $href }}"
                               class="admin-nav__link {{ $isActive($item) ? 'active' : '' }}"
                               data-tooltip="{{ $item['title'] }}"
                               aria-current="{{ $isActive($item) ? 'page' : 'false' }}">
                                <i class="bi {{ $item['icon'] }}"></i>
                                <span>{{ $item['title'] }}</span>
@if(isset($item['badge']))
                                <span id="{{ $item['badgeId'] ?? '' }}" class="admin-nav__badge {{ isset($item['badgeType']) ? 'admin-nav__badge--' . $item['badgeType'] : '' }}" style="{{ $item['badge'] > 0 ? '' : 'display:none;' }}">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
                            @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </nav>
</aside>

{{-- MAIN --}}
<div class="admin-main">

    {{-- TOPBAR --}}
    <header class="admin-topbar">
        <div class="admin-topbar__left">
            {{-- Dual-mode hamburger: click pins/unpins the expanded sidebar on
                  desktop, opens the drawer on mobile. Always visible, same as
                  the renter topbar toggle. --}}
            <button class="admin-icon-btn" id="sidebarToggle" type="button" data-sidebar-toggle
                    aria-controls="adminSidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar" data-state="expanded">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <div class="admin-topbar__title">
                <h1 id="adminPageTitle">{{ $currentPage['title'] }}</h1>
                <div class="admin-topbar__crumb">
                    <a href="{{ route('admin.dashboard') }}"><i class="bi bi-house-door"></i></a>
                    <span class="sep">/</span>
                    <span>{{ $currentGroup }}</span>
                    @if($currentGroup !== $currentPage['title'])
                        <span class="sep">/</span>
                        <span>{{ $currentPage['title'] }}</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Global search --}}
        <div class="admin-search">
            <i class="bi bi-search"></i>
            <input type="text" id="adminSearchInput" placeholder="Search modules..." autocomplete="off">
            <kbd>Ctrl K</kbd>
            <div class="admin-search__results" id="adminSearchResults"></div>
        </div>

        <div class="d-flex align-items-center gap-2 ms-auto">
            {{-- Connection indicators --}}
            <div class="admin-conn online" id="connIoT" data-on="IoT Online" data-off="IoT Offline" title="IoT Connection">
                <span class="dot"></span><span class="label">IoT</span>
            </div>
            <div class="admin-conn online" id="connGPS" data-on="GPS Online" data-off="GPS Offline" title="GPS Connection">
                <span class="dot"></span><span class="label">GPS</span>
            </div>

            <div class="admin-clock" id="adminClock"></div>

            {{-- Theme toggle --}}
            <button class="admin-icon-btn" id="themeToggle" type="button" aria-label="Toggle theme">
                <i class="bi bi-moon"></i>
            </button>

            {{-- Notifications --}}
            <div class="admin-dropdown">
                <button class="admin-icon-btn" type="button" data-dropdown-toggle aria-label="Notifications">
                    <i class="bi bi-bell"></i>
                    <span id="topbarNotifBadge" data-notif-badge class="admin-nav__badge" style="position:absolute;top:-4px;right:-4px;{{ $unreadNotifs > 0 ? '' : 'display:none;' }}">{{ $unreadNotifs > 99 ? '99+' : $unreadNotifs }}</span>
                </button>
                <div class="admin-dropdown__menu">
                    <div class="admin-dropdown__head">
                        <span>Notifications</span>
                        <span class="badge-admin badge-admin--neutral badge-admin--plain" id="notifUnreadLabel" data-notif-unread-label>{{ $unreadNotifs }} unread</span>
                    </div>
                    <div class="admin-dropdown__body">
                        @forelse($recentNotifs as $n)
                            <a href="{{ route('admin.notifications.index') }}" class="admin-notif {{ !$n->read ? 'unread' : '' }}">
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
                        <a href="{{ route('admin.notifications.index') }}" class="btn-admin btn-admin--secondary btn-admin--sm">View all notifications</a>
                    </div>
                </div>
            </div>

            {{-- Profile --}}
            <div class="admin-dropdown">
                <div class="admin-profile" data-dropdown-toggle>
                    <div class="admin-avatar">{{ auth()->user()->initials }}</div>
                    <div class="admin-profile__meta">
                        <div class="admin-profile__name">{{ auth()->user()->name }}</div>
                        <div class="admin-profile__role">Administrator</div>
                    </div>
                    <i class="bi bi-chevron-down admin-profile__caret"></i>
                </div>
                <div class="admin-dropdown__menu">
                    <div class="admin-dropdown__head">{{ auth()->user()->email }}</div>
                    <div class="admin-dropdown__body" style="padding:8px;">
                        <a class="admin-search__item" href="{{ route('admin.settings.index') }}"><i class="bi bi-person"></i>Profile settings</a>
                        <a class="admin-search__item" href="{{ route('admin.settings.index') }}"><i class="bi bi-gear"></i>System settings</a>
                        <a class="admin-search__item" href="{{ route('admin.audit-log.index') }}"><i class="bi bi-journal-text"></i>Audit log</a>
                    </div>
                    <div class="admin-dropdown__foot">
                        <form method="POST" action="{{ route('logout') }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn-admin btn-admin--danger btn-admin--block">
                                <i class="bi bi-box-arrow-right"></i> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- CONTENT --}}
    <main class="admin-content">
        {{-- The success banner is skipped when a structured toast is present
             (e.g. a confirmed bicycle return): the compact top-right toast
             already carries the same message, so rendering both would notify
             twice. A page may also explicitly suppress just its redundant
             banner while retaining the standard success toast. --}}
        @if(session('success') && !session('toast') && !session('suppressSuccessBanner'))
            <div class="alert alert-pedalya alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        {{-- A structured error toast already carries the first failure. Keep
             the validation errors in the session for form handling, but do not
             paint the same failure twice (banner + top-right toast). --}}
        @if(session('error') && !session('toast'))
            <div class="alert alert-pedalya alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if($errors->any() && !session('toast'))
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

        @hasSection('page-header')
            <div class="admin-pagehead">
                <div class="admin-pagehead__title">@yield('page-header')</div>
                @hasSection('actions')
                    <div class="admin-pagehead__actions">@yield('actions')</div>
                @endif
            </div>
        @else
            <div class="admin-pagehead">
                <div class="admin-pagehead__title">
                    <h1>{{ $currentPage['title'] }}</h1>
                </div>
                @hasSection('actions')
                    <div class="admin-pagehead__actions">@yield('actions')</div>
                @endif
            </div>
        @endif

        @yield('content')
    </main>
</div>

{{-- Toast stack --}}
<div class="admin-toasts" id="adminToasts"></div>

{{-- Confirm dialog --}}
<div class="admin-modal" id="adminConfirm">
    <div class="admin-modal__backdrop"></div>
    <div class="admin-modal__dialog">
        <div class="admin-modal__head">
            <h3 id="adminConfirmTitle">Are you sure?</h3>
            <button class="admin-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="admin-modal__body">
            <p id="adminConfirmMsg" style="color: var(--text-2); margin:0;">This action cannot be undone.</p>
        </div>
        <div class="admin-modal__foot">
            <button class="btn-admin btn-admin--secondary" id="adminConfirmCancel">Cancel</button>
            <button class="btn-admin btn-admin--danger" id="adminConfirmOk">Confirm</button>
        </div>
    </div>
</div>

<script>
    window.PedalyaStatus = { iot: {{ $iotOnline > 0 ? 'true' : 'false' }}, gps: {{ $gpsOnline > 0 ? 'true' : 'false' }} };
    window.PedalyaChannels = { notifications: {!! json_encode('private-App.Models.User.' . auth()->id()) !!} };
    window.PedalyaSettings = {
        unreadCountUrl: @json(route('admin.notifications.unread-count')),
        markAllReadUrl: @json(route('admin.notifications.mark-all-read')),
        markReadUrl: @json(route('admin.notifications.mark-read', ['id' => '__ID__'])),
    };
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="{{ asset('js/notifications.js') }}"></script>
<script src="{{ asset('js/admin.js') }}"></script>
{{-- Rental live sync: pages opt in via window.PedalyaRentalLivePage (set in
     their content section, which renders before this tag). --}}
<script src="{{ asset('js/rental-live.js') }}"></script>

{{-- Flash messages surface as compact top-right toasts (fixed overlay, never
     shifts page content). Structured session('toast') wins so the return
     flow can set its own title, and it replaces the competing success or
     error banner above so each action notifies exactly once; plain
     success/error flashes fall back to generic titles. --}}
@if(session('toast') || session('success') || session('error') || $errors->any())
<script>
(function () {
    var flashToast = @json(session('toast'));
    var flashSuccess = @json(session('success'));
    var flashError = @json(session('error') ?? ($errors->any() ? $errors->first() : null));

    function showFlash() {
        if (!window.PedalyaToast) return false;
        // Structured toast (e.g. confirmed bicycle return) carries its own
        // title/message/type populated from the actual response.
        if (flashToast && (flashToast.message || flashToast.title)) {
            var type = ['success', 'error', 'warning', 'info'].includes(flashToast.type)
                ? flashToast.type
                : 'success';
            window.PedalyaToast[type](flashToast.message || '', flashToast.title || 'Notification');
            return true;
        }
        if (flashSuccess) {
            window.PedalyaToast.success(flashSuccess, 'Success');
            return true;
        }
        if (flashError) {
            window.PedalyaToast.error(flashError, 'Error');
            return true;
        }
        return false;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showFlash);
    } else {
        showFlash();
    }
})();
</script>
@endif
@endsection
