<div class="notification-list" id="riderNotifList">
    @forelse($notifications as $notification)
        @php
            $type = $notification->type;
            $tile = \App\Support\NotificationType::tileColors($type);
        @endphp
        <div class="notification-item {{ $notification->readAt ? '' : 'unread' }}"
             data-type="{{ $type }}"
             data-group="{{ \App\Support\NotificationType::group($type) }}">
            <div class="notification-icon" style="background: {{ $tile['background'] }}; color: {{ $tile['color'] }};">
                <i class="bi {{ \App\Support\NotificationType::icon($type) }}"></i>
            </div>
            <div class="notification-content">
                <div class="notification-head">
                    <span class="notification-title">{{ $notification->title }}</span>
                    <x-admin.badge :type="\App\Support\NotificationType::badge($type)"
                                   :label="\App\Support\NotificationType::label($type)" plain />
                </div>
                <div class="notification-text">{{ $notification->message }}</div>
                <div class="notification-time">{{ $notification->created_at->diffForHumans() }}</div>
            </div>
            <div class="notification-actions">
                @if(!$notification->readAt)
                    <form action="{{ route('rider.notifications.mark-read', $notification->id) }}" method="POST" class="d-inline" data-notifications-form>
                        @csrf
                        <button type="submit" class="btn btn-sm btn-link" title="Mark as read">
                            <i class="bi bi-envelope-open"></i>
                        </button>
                    </form>
                @else
                    <span class="btn btn-sm btn-link text-muted" title="Read"><i class="bi bi-envelope"></i></span>
                @endif
            </div>
        </div>
    @empty
        <div class="text-center py-5">
            <div class="empty-state">
                <i class="bi bi-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
        </div>
    @endforelse
</div>