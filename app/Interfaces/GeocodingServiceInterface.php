<?php

namespace App\Interfaces;

interface GeocodingServiceInterface
{
    public function geocodeAddress(string $address): array;
    public function getRouteDetails(array $origin, array $destination): array;
}
