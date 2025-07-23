<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Booking extends Model
{
    // Status constants
    const PENDING = 'pending';
    const CONFIRMED = 'confirmed';
    const CANCELLED = 'cancelled';
    const NO_SHOW = 'no_show';
    const COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'ride_id',
        'seats',
        'status',
        'communication_number',

        'completed_at',
        'passenger_confirmed_at'
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'passenger_confirmed_at' => 'datetime',
        'total_price' => 'decimal:2'
    ];

    /**
     * Get all possible status values
     */
    public static function getStatusOptions(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::CANCELLED,
            self::NO_SHOW,
            self::COMPLETED
        ];
    }

    /**
     * Check if booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::PENDING, self::CONFIRMED]);
    }

    /**
     * Check if booking can be confirmed by driver
     */
    public function canBeConfirmed(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Check if booking is active (not cancelled or no-show)
     */
    public function isActive(): bool
    {
        return !in_array($this->status, [self::CANCELLED, self::NO_SHOW]);
    }

    /**
     * Mark booking as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::COMPLETED,
            'completed_at' => now()
        ]);
    }

    /**
     * Mark passenger as confirmed
     */
    public function markPassengerConfirmed(): void
    {
        $this->update([
            'passenger_confirmed_at' => now()
        ]);
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    // Accessors
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function getIsPassengerConfirmedAttribute(): bool
    {
        return !is_null($this->passenger_confirmed_at);
    }
}
