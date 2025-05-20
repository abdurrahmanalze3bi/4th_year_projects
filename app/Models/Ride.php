<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;

class Ride extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'pickup_address',
        'destination_address',
        'pickup_location',    // Spatial POINT field
        'destination_location', // Spatial POINT field
        'distance',
        'duration',
        'route_geometry',
        'departure_time',
        'available_seats',
        'price_per_seat',
        'vehicle_type',
        'notes'
    ];

    protected $casts = [
        'departure_time' => 'datetime',
        'route_geometry' => 'array',
        // Remove these lines:
        // 'pickup_location' => 'array',
        // 'destination_location' => 'array',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Scope for rides near a specific location
     *
     * @param Builder $query
     * @param float $latitude
     * @param float $longitude
     * @param int $radius (in kilometers)
     */
    public function scopeNearLocation(
        Builder $query,
        float $latitude,
        float $longitude,
        int $radius = 10
    ): void {
        $query->whereRaw(
            "ST_Distance_Sphere(
                pickup_location,
                ST_GeomFromText('POINT(? ?)', 4326)
            ) <= ?",
            [$longitude, $latitude, $radius * 1000] // Convert km to meters
        );
    }

    /**
     * Get formatted pickup coordinates
     */
    public function getPickupCoordinatesAttribute(): array
    {
        return [
            'lat' => $this->pickup_location['lat'] ?? null,
            'lng' => $this->pickup_location['lng'] ?? null,
        ];
    }

    /**
     * Get formatted destination coordinates
     */
    public function getDestinationCoordinatesAttribute(): array
    {
        return [
            'lat' => $this->destination_location['lat'] ?? null,
            'lng' => $this->destination_location['lng'] ?? null,
        ];
    }
    public function setPickupLocationAttribute(array $coordinates)
    {
        $this->attributes['pickup_location'] = DB::raw(
            "ST_GeomFromText('POINT({$coordinates['lng']} {$coordinates['lat']})', 4326)"
        );
    }

    public function setDestinationLocationAttribute(array $coordinates)
    {
        $this->attributes['destination_location'] = DB::raw(
            "ST_GeomFromText('POINT({$coordinates['lng']} {$coordinates['lat']})', 4326)"
        );
    }

// Add proper accessors
    public function getPickupLocationAttribute($value)
    {
        if (!$value) return null;

        $point = \DB::selectOne("
        SELECT ST_X({$value}) AS lng, ST_Y({$value}) AS lat
    ");

        return ['lat' => $point->lat, 'lng' => $point->lng];
    }

    public function getDestinationLocationAttribute($value)
    {
        if (!$value) return null;

        $point = \DB::selectOne("
        SELECT ST_X({$value}) AS lng, ST_Y({$value}) AS lat
    ");

        return ['lat' => $point->lat, 'lng' => $point->lng];
    }
}
