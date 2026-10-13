<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    protected $fillable = [
        'action',
        'category',
        'userId',
        'details',
        'timestamp',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (!$log->timestamp) {
                $log->timestamp = now();
            }
            if (!$log->category) {
                $log->category = AuditLogger::categoryFor((string) $log->action);
            }
            $details = $log->details ?? [];
            if (is_string($details)) {
                $decoded = json_decode($details, true);
                $details = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($details)) {
                $details = [];
            }
            // Request context fills gaps only: anything the operation recorded
            // explicitly (actor email, outcome, resource ids) always wins.
            $log->details = AuditLogger::scrub($details + AuditLogger::requestContext());
        });

        // Application-level append-only enforcement: audit rows may be
        // created and read, never edited or removed through the app. This is
        // deliberately narrow — true storage-level immutability would require
        // database GRANTs outside the application's control, which are not
        // claimed here.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public static function record(string $action, ?int $userId = null, array $details = []): self
    {
        return self::create([
            'action' => $action,
            'userId' => $userId ?? auth()->id(),
            'details' => $details,
        ]);
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'timestamp' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
