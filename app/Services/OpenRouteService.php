<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Interfaces\GeocodingServiceInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;

class OpenRouteService implements GeocodingServiceInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.openrouteservice.org/';

    protected int $cacheTtl;
    protected bool $sslVerify;
    protected float $timeout;

    public function __construct(
        string $apiKey,
        int $cacheTtl = 3600,    // Default TTL in seconds
        bool $sslVerify = false, // Disable SSL verify locally, enable in production
        float $timeout = 30.0    // Request timeout in seconds
    ) {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('OpenRouteService API key is required.');
        }
        $this->apiKey    = $apiKey;
        $this->cacheTtl  = $cacheTtl;
        $this->sslVerify = $sslVerify;
        $this->timeout   = $timeout;

        Log::debug('OpenRouteService instantiated.', [
            'cacheTtl'  => $this->cacheTtl,
            'sslVerify' => $this->sslVerify,
            'timeout'   => $this->timeout,
        ]);
    }

    /**
     * Geocode a human-readable address into ['lat' => float, 'lng' => float, 'label' => string].
     */
    public function geocodeAddress(string $address): array
    {
        $url = 'https://nominatim.openstreetmap.org/search';

        $params = [
            'q'               => $address,
            'format'          => 'json',
            'limit'           => 1,
            'addressdetails'  => 1,
            'accept-language' => 'ar',
            'countrycodes'    => 'sy',  // restrict to Syria
            'bounded'         => 1,     // hard‐limit to the country box
        ];

        $response = Http::withHeaders([
            'User-Agent' => 'YourApp/1.0 (contact@youremail.com)'
        ])
            ->withoutVerifying()
            ->timeout(10)
            ->retry(2, 500)
            ->get($url, $params);

        if (! $response->successful()) {
            throw new \Exception("Geocoding failed: HTTP {$response->status()}");
        }

        $data = $response->json();
        if (empty($data)) {
            throw new \Exception("No location found for “{$address}”");
        }

        return [
            'label' => $data[0]['display_name'],
            'lat'   => (float)$data[0]['lat'],
            'lng'   => (float)$data[0]['lon'],
        ];
    }

    /**
     * Autocomplete partial text with up to 6 results from Nominatim.
     */
    public function autocomplete(string $partial): array
    {
        $url = 'https://nominatim.openstreetmap.org/search';

        $params = [
            'q'               => $partial,
            'format'          => 'json',
            'limit'           => 6,
            'addressdetails'  => 1,
            'accept-language' => 'ar',
            'viewbox'         => '35.5,37.5,42.0,32.0', // left, top, right, bottom
            'bounded'         => 1,
        ];

        $resp = Http::withHeaders([
            'User-Agent' => 'YourAppName/1.0 (contact@yourdomain.com)'
        ])
            ->withoutVerifying()
            ->timeout(10)
            ->retry(2, 500)
            ->get($url, $params);

        if (! $resp->successful()) {
            throw new \Exception("Nominatim autocomplete failed: HTTP {$resp->status()}");
        }

        return collect($resp->json())
            ->map(fn($f) => [
                'label' => $f['display_name'],
                'lat'   => $f['lat'],
                'lng'   => $f['lon'],
            ])
            ->all();
    }

    /**
     * Reverse‐geocode lat & lng into a human‐readable address (label).
     */
    /**
     * Reverse-geocode lat & lng into a human-readable address using Nominatim (Alternative)
     */
    public function reverseGeocode(float $lat, float $lng): string
    {
        $cacheKey = "nominatim_reverse:v1:" . md5("{$lat},{$lng}");

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($lat, $lng) {
            $url = 'https://nominatim.openstreetmap.org/reverse';

            $params = [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
                'accept-language' => 'en,ar',
                'addressdetails' => 1,
                'zoom' => 18,
                'extratags' => 1
            ];

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'SyRide-App/1.0 (contact@syride.com)',
                    'Accept' => 'application/json',
                    'Referer' => env('APP_URL', 'http://localhost:8000')
                ])
                    ->withoutVerifying() // Only for development - enable SSL in production
                    ->timeout($this->timeout)
                    ->retry(2, 1000) // Retry twice with 1 second delay
                    ->get($url, $params);

                if (!$response->successful()) {
                    Log::warning('Nominatim reverse geocoding failed', [
                        'status' => $response->status(),
                        'lat' => $lat,
                        'lng' => $lng,
                        'response_headers' => $response->headers(),
                        'response_body' => substr($response->body(), 0, 500)
                    ]);

                    // Fallback to coordinate string
                    return "Location: {$lat}, {$lng}";
                }

                $data = $response->json();

                if (isset($data['display_name'])) {
                    return $data['display_name'];
                }

                // Try to construct address from components
                if (isset($data['address'])) {
                    $address = $data['address'];
                    $parts = [];

                    // Build address in logical order
                    if (isset($address['house_number'])) $parts[] = $address['house_number'];
                    if (isset($address['road'])) $parts[] = $address['road'];
                    if (isset($address['neighbourhood'])) $parts[] = $address['neighbourhood'];
                    if (isset($address['suburb'])) $parts[] = $address['suburb'];
                    if (isset($address['city'])) $parts[] = $address['city'];
                    if (isset($address['state'])) $parts[] = $address['state'];
                    if (isset($address['country'])) $parts[] = $address['country'];

                    if (!empty($parts)) {
                        return implode(', ', array_unique($parts));
                    }
                }

                // Final fallback
                return "Location: {$lat}, {$lng}";

            } catch (\Exception $e) {
                Log::error('Nominatim reverse geocoding exception', [
                    'error' => $e->getMessage(),
                    'lat' => $lat,
                    'lng' => $lng,
                    'trace' => $e->getTraceAsString()
                ]);

                // Return coordinate fallback
                return "Location: {$lat}, {$lng}";
            }
        });
    }
    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        $cacheKey = "ors_route:v4:" . md5(serialize([$origin, $destination, 'v2/driving-car/json']));
        $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $requestUrl) {
            $payload = [
                'coordinates' => [
                    [$origin['lng'], $origin['lat']],
                    [$destination['lng'], $destination['lat']],
                ],
                'instructions' => false,
                'geometry' => true,
            ];

            try {
                $response = Http::withOptions(['verify' => false])
                    ->withHeaders(['Authorization' => $this->apiKey])
                    ->timeout($this->timeout)
                    ->post($requestUrl, $payload);

                return $this->handleRouteResponse($response, $origin, $destination);
            } catch (\Exception $e) {
                // Fallback to straight-line distance calculation
                $distance = $this->calculateHaversineDistance($origin, $destination);
                return [
                    'distance' => $distance,
                    'duration' => $distance / 1000 * 60, // 1km ≈ 60 seconds
                    'geometry' => [
                        [$origin['lng'], $origin['lat']],
                        [$destination['lng'], $destination['lat']]
                    ]
                ];
            }
        });
    }

    /**
     * Handle the HTTP response from ORS and extract distance, duration, and geometry.
     */
    private function handleRouteResponse(HttpResponse $response, ?array $origin = null, ?array $destination = null): array
    {
        Log::debug("Entering handleRouteResponse. HTTP status: {$response->status()}", [
            'origin'      => $origin,
            'destination' => $destination,
            'body_snippet'=> substr($response->body(), 0, 500),
        ]);

        if (! $response->successful()) {
            $this->logApiError('Routing', $response, ['origin' => $origin, 'destination' => $destination]);
            $orsError = $response->json('error.message');
            $errorMessage = "Routing API Error: HTTP {$response->status()}";
            if ($orsError) {
                $errorMessage .= " - ORS Message: {$orsError}";
            } else {
                $body = $response->body();
                $errorMessage .= " - Body: " . (strlen($body) > 250 ? substr($body, 0, 250) . '...' : $body);
            }
            throw new \Exception($errorMessage);
        }

        $data = $response->json();
        if (is_null($data)) {
            Log::error("OpenRouteService routing response was not valid JSON.", [
                'origin'       => $origin,
                'destination'  => $destination,
                'status'       => $response->status(),
                'raw_body'     => $response->body(),
            ]);
            throw new \Exception("Routing service returned non-JSON response. Status: {$response->status()}");
        }

        Log::info('OpenRouteService successful routing response (parsed JSON).', [
            'origin'       => $origin,
            'destination'  => $destination,
            'parsed_snip'  => substr(json_encode($data), 0, 500),
        ]);

        if (
            empty($data['routes']) ||
            !isset($data['routes'][0]) ||
            !is_array($data['routes'][0]['summary'])
        ) {
            $respPreview = json_encode($data);
            Log::warning("Invalid route response format or missing summary.", [
                'origin'      => $origin,
                'destination' => $destination,
                'body_preview'=> strlen($respPreview) > 500
                    ? substr($respPreview, 0, 497) . "..."
                    : $respPreview,
            ]);
            throw new \Exception("Invalid route response from ORS (missing summary).");
        }

        $route0  = $data['routes'][0];
        $summary = $route0['summary'];
        if (
            !isset($summary['distance']) || !is_numeric($summary['distance']) ||
            !isset($summary['duration']) || !is_numeric($summary['duration'])
        ) {
            $respPreview = json_encode($data);
            Log::warning("Route summary missing or invalid.", [
                'origin'       => $origin,
                'destination'  => $destination,
                'summary'      => $summary,
                'body_preview' => strlen($respPreview) > 500
                    ? substr($respPreview, 0, 497) . "..."
                    : $respPreview,
            ]);
            throw new \Exception("Route summary incomplete or invalid (distance/duration).");
        }

        // Extract geometry if present
        $geometry = null;
        if (
            isset($route0['geometry']) &&
            isset($route0['geometry']['coordinates']) &&
            is_array($route0['geometry']['coordinates'])
        ) {
            $geometry = $route0['geometry']['coordinates']; // [ [lng,lat], … ]
        } else {
            Log::warning("No 'geometry.coordinates' found despite requesting geometry.", [
                'origin'      => $origin,
                'destination' => $destination,
                'parsed_data' => $data,
            ]);
        }

        $result = [
            'distance' => (float)$summary['distance'],   // meters
            'duration' => (float)$summary['duration'],   // seconds
            'geometry' => $geometry,                     // LineString coords or null
        ];

        Log::debug("handleRouteResponse returning:", $result);
        return $result;
    }

    /**
     * Ensure the coordinates array has valid 'lat' and 'lng' keys within ranges.
     */
    private function validateCoordinates(array $coordinates, string $label = 'Coordinates'): void
    {
        $requiredKeys = ['lat', 'lng'];
        foreach ($requiredKeys as $key) {
            if (!isset($coordinates[$key])) {
                throw new \InvalidArgumentException("{$label}: Missing key '{$key}'.");
            }
            if (!is_numeric($coordinates[$key])) {
                throw new \InvalidArgumentException("{$label}: '{$key}' must be numeric.");
            }
        }
        if ($coordinates['lat'] < -90 || $coordinates['lat'] > 90) {
            throw new \InvalidArgumentException("{$label}: Latitude out of range (-90 to 90).");
        }
        if ($coordinates['lng'] < -180 || $coordinates['lng'] > 180) {
            throw new \InvalidArgumentException("{$label}: Longitude out of range (-180 to 180).");
        }
    }

    /**
     * Log detailed information when API errors occur.
     */
    private function logApiError(string $type, HttpResponse $response, array $context = []): void
    {
        Log::error("OpenRouteService {$type} API Error", array_merge([
            'status'      => $response->status(),
            'headers'     => $response->headers(),
            'body'        => $response->body(),
            'request_url' => (string)$response->effectiveUri(),
        ], $context));
    }

    // Optional fluent setters if you need to override settings at runtime:





    /**
     * Get multiple route alternatives between two points
     */
    /**
     * Get multiple route alternatives between two points
     */


    /**
     * Get multiple route alternatives between two points
     * Fixed version that works with OpenRouteService API
     */

    /**
     * Get multiple route alternatives between two points
     * Fixed version that properly uses OpenRouteService alternative routes
     */
    public function getRouteAlternatives(array $origin, array $destination, int $maxAlternatives = 3): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        // First try to get alternatives directly from OpenRouteService
        $orsRoutes = $this->getOpenRouteServiceAlternatives($origin, $destination, $maxAlternatives);

        if (!empty($orsRoutes)) {
            Log::info('Successfully retrieved routes from OpenRouteService', [
                'route_count' => count($orsRoutes),
                'distances' => array_column($orsRoutes, 'distance')
            ]);
            return $orsRoutes;
        }

        // Fallback to waypoint-based alternatives if ORS doesn't provide alternatives
        Log::warning('OpenRouteService alternatives not available, generating waypoint-based routes');
        return $this->generateWaypointBasedAlternatives($origin, $destination, $maxAlternatives);
    }

    /**
     * Get alternatives directly from OpenRouteService API
     */
    private function getOpenRouteServiceAlternatives(array $origin, array $destination, int $maxAlternatives): array
    {
        $cacheKey = "ors_alternatives:v1:" . md5(serialize([$origin, $destination, $maxAlternatives]));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $maxAlternatives) {
            $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

            $payload = [
                'coordinates' => [
                    [$origin['lng'], $origin['lat']],
                    [$destination['lng'], $destination['lat']]
                ],
                'instructions' => false,
                'geometry' => true,
                'alternative_routes' => [
                    'target_count' => min($maxAlternatives, 3), // ORS supports max 3 alternatives
                    'weight_factor' => 1.4,
                    'share_factor' => 0.6
                ]
            ];

            try {
                Log::debug('Requesting alternatives from OpenRouteService', [
                    'url' => $requestUrl,
                    'payload' => $payload
                ]);

                $response = Http::withOptions(['verify' => $this->sslVerify])
                    ->withHeaders(['Authorization' => $this->apiKey])
                    ->timeout($this->timeout)
                    ->post($requestUrl, $payload);

                if ($response->successful()) {
                    $data = $response->json();

                    Log::debug('OpenRouteService response received', [
                        'status' => $response->status(),
                        'routes_count' => isset($data['routes']) ? count($data['routes']) : 0,
                        'response_keys' => array_keys($data ?? [])
                    ]);

                    if (isset($data['routes']) && is_array($data['routes']) && !empty($data['routes'])) {
                        $routes = [];

                        foreach ($data['routes'] as $index => $route) {
                            if (isset($route['summary']['distance']) && isset($route['summary']['duration'])) {
                                $routes[] = [
                                    'distance' => (float)$route['summary']['distance'],
                                    'duration' => (float)$route['summary']['duration'],
                                    'geometry' => $route['geometry']['coordinates'] ?? [],
                                    'type' => $index === 0 ? 'main_route' : "alternative_route_$index",
                                    'route_index' => $index
                                ];
                            }
                        }

                        if (!empty($routes)) {
                            Log::info('Successfully parsed OpenRouteService alternatives', [
                                'total_routes' => count($routes),
                                'distances' => array_column($routes, 'distance'),
                                'durations' => array_column($routes, 'duration')
                            ]);
                            return $routes;
                        }
                    }
                }

                Log::warning('OpenRouteService alternatives request failed or returned no routes', [
                    'status' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ]);

            } catch (\Exception $e) {
                Log::error('OpenRouteService alternatives request exception', [
                    'error' => $e->getMessage(),
                    'origin' => $origin,
                    'destination' => $destination
                ]);
            }

            return [];
        });
    }

    /**
     * Generate alternative routes using waypoints when ORS alternatives aren't available
     */
    private function generateWaypointBasedAlternatives(array $origin, array $destination, int $maxAlternatives): array
    {
        $routes = [];

        // First get the direct route
        try {
            $mainRoute = $this->getRouteDetails($origin, $destination);
            $routes[] = array_merge($mainRoute, [
                'type' => 'main_route',
                'route_index' => 0
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to get main route for waypoint alternatives', [
                'error' => $e->getMessage()
            ]);
        }

        // Generate alternative routes with strategic waypoints
        if ($maxAlternatives > 1) {
            $alternativeRoutes = $this->generateStrategicWaypointRoutes($origin, $destination, $maxAlternatives - 1);
            $routes = array_merge($routes, $alternativeRoutes);
        }

        // If we still don't have routes, return fallback
        if (empty($routes)) {
            return $this->generateFallbackRoute($origin, $destination);
        }

        return array_slice($routes, 0, $maxAlternatives);
    }

    /**
     * Generate alternative routes using strategic waypoints
     */
    private function generateStrategicWaypointRoutes(array $origin, array $destination, int $count): array
    {
        $alternatives = [];
        $bearing = $this->calculateBearing($origin, $destination);
        $distance = $this->calculateHaversineDistance($origin, $destination);

        // Create waypoints at different positions and bearings
        $waypointStrategies = [
            ['position' => 0.3, 'bearing_offset' => 45, 'distance_factor' => 0.2],
            ['position' => 0.7, 'bearing_offset' => -45, 'distance_factor' => 0.15],
            ['position' => 0.5, 'bearing_offset' => 90, 'distance_factor' => 0.25],
            ['position' => 0.4, 'bearing_offset' => -90, 'distance_factor' => 0.18],
        ];

        for ($i = 0; $i < min($count, count($waypointStrategies)); $i++) {
            $strategy = $waypointStrategies[$i];

            // Calculate waypoint position
            $waypoint = $this->calculateWaypointFromStrategy($origin, $destination, $strategy, $distance);

            try {
                $route = $this->getRouteWithWaypoint($origin, $waypoint, $destination, $i + 1);
                if ($route) {
                    $alternatives[] = $route;
                }
            } catch (\Exception $e) {
                Log::debug("Failed to generate alternative route $i", [
                    'error' => $e->getMessage(),
                    'waypoint' => $waypoint
                ]);
            }
        }

        return $alternatives;
    }

    /**
     * Calculate waypoint based on strategy
     */
    private function calculateWaypointFromStrategy(array $origin, array $destination, array $strategy, float $distance): array
    {
        // Calculate intermediate point along the route
        $lat1 = deg2rad($origin['lat']);
        $lng1 = deg2rad($origin['lng']);
        $lat2 = deg2rad($destination['lat']);
        $lng2 = deg2rad($destination['lng']);

        $f = $strategy['position'];
        $A = sin((1-$f) * $distance/6371000) / sin($distance/6371000);
        $B = sin($f * $distance/6371000) / sin($distance/6371000);

        $x = $A * cos($lat1) * cos($lng1) + $B * cos($lat2) * cos($lng2);
        $y = $A * cos($lat1) * sin($lng1) + $B * cos($lat2) * sin($lng2);
        $z = $A * sin($lat1) + $B * sin($lat2);

        $lat = atan2($z, sqrt($x*$x + $y*$y));
        $lng = atan2($y, $x);

        // Apply offset based on bearing
        $offsetDistance = $distance * $strategy['distance_factor'];
        $offsetBearing = $this->calculateBearing($origin, $destination) + deg2rad($strategy['bearing_offset']);

        $lat += ($offsetDistance / 6371000) * cos($offsetBearing);
        $lng += ($offsetDistance / 6371000) * sin($offsetBearing) / cos($lat);

        return [
            'lat' => rad2deg($lat),
            'lng' => rad2deg($lng)
        ];
    }

    /**
     * Calculate bearing between two points
     */
    private function calculateBearing(array $origin, array $destination): float
    {
        $lat1 = deg2rad($origin['lat']);
        $lat2 = deg2rad($destination['lat']);
        $deltaLng = deg2rad($destination['lng'] - $origin['lng']);

        $y = sin($deltaLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLng);

        return atan2($y, $x);
    }

    /**
     * Get route with a waypoint (updated version)
     */
    private function getRouteWithWaypoint(array $origin, array $waypoint, array $destination, int $routeNumber): ?array
    {
        $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

        $payload = [
            'coordinates' => [
                [$origin['lng'], $origin['lat']],
                [$waypoint['lng'], $waypoint['lat']],
                [$destination['lng'], $destination['lat']]
            ],
            'instructions' => false,
            'geometry' => true,
        ];

        try {
            $response = Http::withOptions(['verify' => $this->sslVerify])
                ->withHeaders(['Authorization' => $this->apiKey])
                ->timeout($this->timeout)
                ->post($requestUrl, $payload);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['routes'][0])) {
                    $route = $data['routes'][0];
                    return [
                        'distance' => (float)($route['summary']['distance'] ?? 0),
                        'duration' => (float)($route['summary']['duration'] ?? 0),
                        'geometry' => $route['geometry']['coordinates'] ?? [],
                        'type' => "alternative_route_$routeNumber",
                        'route_index' => $routeNumber,
                        'waypoint_used' => $waypoint
                    ];
                }
            }

            Log::debug("Alternative route $routeNumber request failed", [
                'status' => $response->status(),
                'waypoint' => $waypoint,
                'response_preview' => substr($response->body(), 0, 200)
            ]);

        } catch (\Exception $e) {
            Log::debug("Alternative route $routeNumber exception", [
                'error' => $e->getMessage(),
                'waypoint' => $waypoint
            ]);
        }

        return null;
    }
    /**
     * Get the main route using OpenRouteService
     */
    private function getMainRoute(array $origin, array $destination): ?array
    {
        $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

        $payload = [
            'coordinates' => [
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']]
            ],
            'instructions' => false,
            'geometry' => true,
        ];

        try {
            $response = Http::withOptions(['verify' => $this->sslVerify])
                ->withHeaders(['Authorization' => $this->apiKey])
                ->timeout($this->timeout)
                ->post($requestUrl, $payload);

            Log::debug('OpenRouteService Main Route Request', [
                'endpoint' => $requestUrl,
                'payload' => $payload,
                'status' => $response->status(),
                'response_preview' => substr($response->body(), 0, 500)
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['routes'][0])) {
                    $route = $data['routes'][0];
                    return [
                        'distance' => $route['summary']['distance'] ?? 0,
                        'duration' => $route['summary']['duration'] ?? 0,
                        'geometry' => $route['geometry']['coordinates'] ?? [],
                        'type' => 'main_route'
                    ];
                }
            }

            Log::warning('Main route request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Main route request exception', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Generate alternative routes by creating waypoints
     */
    private function generateAlternativeRoutes(array $origin, array $destination, int $count): array
    {
        $alternatives = [];

        // Calculate the direct distance and create offset waypoints
        $midLat = ($origin['lat'] + $destination['lat']) / 2;
        $midLng = ($origin['lng'] + $destination['lng']) / 2;

        // Generate waypoints with different offsets to create alternative routes
        $offsets = [
            ['lat' => 0.01, 'lng' => 0.01],   // Northeast offset
            ['lat' => -0.01, 'lng' => 0.01],  // Southeast offset
            ['lat' => 0.01, 'lng' => -0.01],  // Northwest offset
            ['lat' => -0.01, 'lng' => -0.01], // Southwest offset
        ];

        for ($i = 0; $i < min($count, count($offsets)); $i++) {
            $waypoint = [
                'lat' => $midLat + $offsets[$i]['lat'],
                'lng' => $midLng + $offsets[$i]['lng']
            ];

            $alternativeRoute = $this->getRouteWithWaypoint($origin, $waypoint, $destination, $i + 1);
            if ($alternativeRoute) {
                $alternatives[] = $alternativeRoute;
            }
        }

        return $alternatives;
    }

    /**
     * Get route with a waypoint to create alternative path
     */


    /**
     * Generate fallback routes when API is unavailable
     */
    private function generateFallbackRoute(array $origin, array $destination): array
    {
        $distance = $this->calculateHaversineDistance($origin, $destination);
        $drivingDistance = $distance * 1.3; // Add 30% for realistic driving distance
        $drivingTime = ($drivingDistance / 1000) * 72; // Assume 50 km/h average speed

        // Create multiple fallback alternatives with slight variations
        $routes = [];

        // Main fallback route
        $routes[] = [
            'distance' => $drivingDistance,
            'duration' => $drivingTime,
            'geometry' => $this->calculateIntermediatePoints(
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
                8
            ),
            'type' => 'fallback_main',
            'is_fallback' => true,
            'warning' => 'Route service unavailable - showing estimated direct path'
        ];

        // Alternative fallback routes with different estimated times/distances
        $routes[] = [
            'distance' => $drivingDistance * 1.15,
            'duration' => $drivingTime * 1.2,
            'geometry' => $this->calculateIntermediatePoints(
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
                10,
                0.002 // Small offset for variation
            ),
            'type' => 'fallback_alternative_1',
            'is_fallback' => true,
            'warning' => 'Route service unavailable - showing estimated alternative path'
        ];

        $routes[] = [
            'distance' => $drivingDistance * 1.25,
            'duration' => $drivingTime * 1.35,
            'geometry' => $this->calculateIntermediatePoints(
                [$origin['lng'], $origin['lat']],
                [$destination['lng'], $destination['lat']],
                12,
                -0.002 // Opposite offset
            ),
            'type' => 'fallback_alternative_2',
            'is_fallback' => true,
            'warning' => 'Route service unavailable - showing estimated scenic route'
        ];

        return $routes;
    }

    /**
     * Calculate intermediate points with optional offset for route variation
     */
    private function calculateIntermediatePoints(array $start, array $end, int $segments, float $offset = 0): array
    {
        $points = [$start];

        $latStep = ($end[1] - $start[1]) / ($segments + 1);
        $lngStep = ($end[0] - $start[0]) / ($segments + 1);

        for ($i = 1; $i <= $segments; $i++) {
            $randomLat = $offset !== 0 ? (rand(-100, 100) / 100000) + $offset : (rand(-50, 50) / 100000);
            $randomLng = $offset !== 0 ? (rand(-100, 100) / 100000) + $offset : (rand(-50, 50) / 100000);

            $points[] = [
                $start[0] + ($lngStep * $i) + $randomLng,
                $start[1] + ($latStep * $i) + $randomLat
            ];
        }

        $points[] = $end;
        return $points;
    }
    private function calculateHaversineDistance(array $point1, array $point2): float
    {
        $lat1 = deg2rad($point1['lat']);
        $lon1 = deg2rad($point1['lng']);
        $lat2 = deg2rad($point2['lat']);
        $lon2 = deg2rad($point2['lng']);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat/2) * sin($deltaLat/2) +
            cos($lat1) * cos($lat2) *
            sin($deltaLon/2) * sin($deltaLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return 6371000 * $c; // Earth radius in meters
    }
    /**
     * Calculate haversine distance between two points
     */
    private function decodePolyline(string $polyline): array
    {
        $points = [];
        $index = $lat = $lng = 0;
        $len = strlen($polyline);

        while ($index < $len) {
            // Latitude
            $shift = $result = 0;
            do {
                $b = ord($polyline[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lat += $dlat;

            // Longitude
            $shift = $result = 0;
            do {
                $b = ord($polyline[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lng += $dlng;

            $points[] = [$lng * 1e-5, $lat * 1e-5];
        }

        return $points;
    }


    /**
     * Estimate travel time based on distance
     */
    private function calculateEstimatedTime(array $origin, array $destination): float
    {
        $distance = $this->calculateHaversineDistance($origin, $destination);
        $averageSpeed = 50; // km/h
        return ($distance / 1000) / $averageSpeed * 3600; // seconds
    }

    /**
     * Handle route alternatives response
     */
    private function handleRouteAlternativesResponse(HttpResponse $response): array
    {
        if (!$response->successful()) {
            throw new \Exception("Routing API Error: HTTP {$response->status()}");
        }

        $data = $response->json();
        if (empty($data['routes'])) {
            throw new \Exception("No routes found in response");
        }

        $routes = [];
        foreach ($data['routes'] as $route) {
            $routes[] = [
                'distance' => $route['summary']['distance'],
                'duration' => $route['summary']['duration'],
                'geometry' => $route['geometry']['coordinates'],
            ];
        }

        return $routes;
    }
}
