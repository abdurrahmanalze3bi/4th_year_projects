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
    public function reverseGeocode(float $lat, float $lng): string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse';
        $params = [
            'lat'    => $lat,
            'lon'    => $lng,
            'format' => 'json',
            'accept-language' => 'ar',
        ];

        $resp = Http::withHeaders([
            'User-Agent' => 'MyApp/1.0 (you@domain.com)'
        ])
            ->withoutVerifying()
            ->timeout(10)
            ->get($url, $params);

        if (! $resp->successful()) {
            throw new \Exception("Reverse geocoding failed: HTTP {$resp->status()}");
        }

        $body = $resp->json();
        return $body['display_name'] ?? "{$lat},{$lng}";
    }

    /**
     * Retrieve route details (distance, duration, geometry) between two points.
     *
     * Returns [
     *   'distance' => meters (float),
     *   'duration' => seconds (float),
     *   'geometry' => [ [lng,lat], [lng,lat], ... ]  // LineString coordinates
     * ]
     */
    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        Log::info('Requesting route details from OpenRouteService.', [
            'origin_coords'      => $origin,
            'destination_coords' => $destination,
        ]);

        // Prevent identical‐point requests
        if (
            abs($origin['lat'] - $destination['lat']) < 0.00001 &&
            abs($origin['lng'] - $destination['lng']) < 0.00001
        ) {
            Log::warning('getRouteDetails called with identical origin and destination.', [
                'origin'      => $origin,
                'destination' => $destination,
            ]);
            throw new \InvalidArgumentException("Origin and destination cannot be the same.");
        }

        $cacheKey   = "ors_route:v4:" . md5(serialize([$origin, $destination, 'v2/driving-car/json']));
        $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $requestUrl) {
            try {
                $payload = [
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']],       // [lng, lat]
                        [$destination['lng'], $destination['lat']],
                    ],
                    'instructions'      => false,      // no step-by-step instructions
                    'geometry'          => true,       // request full LineString geometry
                    'geometry_simplify' => false,
                    'preference'        => 'fastest',
                    // 'units' => 'm',                // meters by default
                ];

                Log::debug('OpenRouteService Directions API REQUEST payload:', $payload);

                $response = Http::withOptions([
                    'verify'          => $this->sslVerify,
                    'timeout'         => $this->timeout,
                    'connect_timeout' => 10,
                ])
                    ->withHeaders([
                        'Authorization' => $this->apiKey,
                        'Accept'        => 'application/json; charset=utf-8',
                        'Content-Type'  => 'application/json; charset=utf-8',
                    ])
                    ->retry(1, 700, function ($exception) {
                        // Retry only on connection exceptions
                        return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                    })
                    ->post($requestUrl, $payload);

                Log::debug("OpenRouteService Routing API RAW RESPONSE:", [
                    'status'        => $response->status(),
                    'body_snippet'  => substr($response->body(), 0, 1000),
                    'effective_uri' => (string)$response->effectiveUri(),
                ]);

                return $this->handleRouteResponse($response, $origin, $destination);

            } catch (RequestException $e) {
                Log::error("Routing HTTP Request failed.", [
                    'url'             => $requestUrl,
                    'origin'          => $origin,
                    'destination'     => $destination,
                    'error'           => $e->getMessage(),
                    'response_status' => optional($e->response)->status(),
                    'response_body'   => optional($e->response)->body(),
                ]);
                throw new \Exception("Routing service request error: " . $e->getMessage(), 0, $e);
            } catch (\InvalidArgumentException $e) {
                throw $e; // rethrow for identical‐point check
            } catch (\Exception $e) {
                Log::error("Routing processing failed unexpectedly.", [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]);
                throw new \Exception("Routing service unavailable: " . $e->getMessage(), 0, $e);
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

    public function setSslVerify(bool $sslVerify): self
    {
        $this->sslVerify = $sslVerify;
        return $this;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        return $this;
    }

    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setCacheTtl(int $cacheTtl): self
    {
        $this->cacheTtl = $cacheTtl;
        return $this;
    }
}
