<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Ride extends Model
{
    use HasFactory;

    /**
     * Fillable: DO NOT include old pickup_lat/pickup_lng or destination_lat/destination_lng here.
     * Instead, include only driver_id, pickup_address, destination_address, distance, etc.
     */
    protected $fillable = [
        'driver_id',
        'pickup_address',
        'destination_address',
        // (no pickup_lat/pickup_lng!)
        // (no destination_lat/destination_lng!)
        'distance',
        'duration',
        'route_geometry',       // JSON
        'departure_time',
        'available_seats',
        'price_per_seat',
        'vehicle_type',
        'payment_method',
        'notes',
        'finished_at' => 'datetime',
        // We will write to pickup_location and destination_location via the setter
    ];

    /**
     * Cast route_geometry (JSON) into a PHP array automatically.
     */
    protected $casts = [
        'departure_time' => 'datetime',
        'pickup_location' => 'array',
        'destination_location' => 'array',
        'route_geometry' => 'array',
        'status' => 'string',
          'driver_confirmed_at' => 'datetime',
        'payment_method' => 'string'
    ];

    //------------------------------------------------------------------------//
    // Relationships
    //------------------------------------------------------------------------//

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    //------------------------------------------------------------------------//
    // Custom Accessors for pickup_location / destination_location
    //------------------------------------------------------------------------//

    /**
     * Return pickup_location as an array [ 'lat' => ..., 'lng' => ... ].
     * The database column is a POINT; here we run a raw SELECT ST_AsText(...) to parse it.
     */
    public function getPickupLocationAttribute(): ?array
    {
        if (!isset($this->attributes['id'])) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT ST_AsText(`pickup_location`) AS wkt FROM `rides` WHERE `id` = ?',
            [$this->attributes['id']]
        );

        if (! $row || ! isset($row->wkt)) {
            return null;
        }

        // wkt looks like "POINT(<lng> <lat>)"
        sscanf($row->wkt, 'POINT(%f %f)', $lng, $lat);

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * Return destination_location as an array [ 'lat' => ..., 'lng' => ... ].
     */
    public function getDestinationLocationAttribute(): ?array
    {
        if (!isset($this->attributes['id'])) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT ST_AsText(`destination_location`) AS wkt FROM `rides` WHERE `id` = ?',
            [$this->attributes['id']]
        );

        if (! $row || ! isset($row->wkt)) {
            return null;
        }

        sscanf($row->wkt, 'POINT(%f %f)', $lng, $lat);

        return ['lat' => $lat, 'lng' => $lng];
    }

    //------------------------------------------------------------------------//
    // Custom Mutators (Setters) for pickup_location / destination_location
    //------------------------------------------------------------------------//

    /**
     * Expect $coords = [ 'lat' => float, 'lng' => float ].
     * We convert it into a MySQL POINT(...) with SRID 4326 when saving.
     */
    public function setPickupLocationAttribute(array $coords)
    {
        if (isset($coords['lat'], $coords['lng'])) {
            $lat = (float) $coords['lat'];
            $lng = (float) $coords['lng'];
            $this->attributes['pickup_location'] = DB::raw(
                sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $lng, $lat)
            );
        }
    }

    /**
     * Same for destination_location.
     */
    public function setDestinationLocationAttribute(array $coords)
    {
        if (isset($coords['lat'], $coords['lng'])) {
            $lat = (float) $coords['lat'];
            $lng = (float) $coords['lng'];
            $this->attributes['destination_location'] = DB::raw(
                sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $lng, $lat)
            );
        }
    }

    //------------------------------------------------------------------------//
    // (Optional) Scope for “nearby” searching by pickup location
    //------------------------------------------------------------------------//

    /**
     * Scope to find all rides whose pickup_location is within $radiusKm kilometers.
     * You can use this in your repository or controllers if needed.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, int $radiusKm = 10): void
    {
        $radiusMeters = $radiusKm * 1000;
        $query->whereRaw(
            "ST_Distance_Sphere(
                `pickup_location`,
                ST_GeomFromText('POINT(? ?)', 4326)
            ) <= ?",
            [$longitude, $latitude, $radiusMeters]
        );
    }
}
