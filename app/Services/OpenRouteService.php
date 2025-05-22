<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Interfaces\GeocodingServiceInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse; // Ensure this is imported

class OpenRouteService implements GeocodingServiceInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.openrouteservice.org/';
    protected int $cacheTtl;
    protected bool $sslVerify;
    protected float $timeout;

    public function __construct(
        string $apiKey,
        int $cacheTtl = 3600,    // Default from GeocodingServiceProvider if configured
        bool $sslVerify = false,  // Default to false for local, true for prod is better
        float $timeout = 30.0    // Default timeout
    ) {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('OpenRouteService API key is required.');
        }
        $this->apiKey = $apiKey;
        $this->cacheTtl = $cacheTtl;
        $this->sslVerify = $sslVerify;
        $this->timeout = $timeout;

        Log::debug('OpenRouteService instantiated.', [
            'cacheTtl' => $this->cacheTtl,
            'sslVerify' => $this->sslVerify,
            'timeout' => $this->timeout,
        ]);
    }

    public function geocodeAddress(string $address): array
    {
        $focusPoint = ['lat' => 33.5138, 'lon' => 36.2765]; // Damascus center
        $boundaryCountry = 'SYR'; // Syria ISO 3166-1 alpha-3

        $queryParams = [
            'text' => $address,
            'size' => 5, // Request more results to inspect if the top one is poor
            'focus.point.lat' => $focusPoint['lat'],
            'focus.point.lon' => $focusPoint['lon'],
            'boundary.country' => $boundaryCountry,
            // 'layers' => 'address,street,venue', // Experiment if needed
            // 'sources' => 'openstreetmap,openaddresses,whosonfirst', // Experiment if needed
        ];

        // More specific cache key
        $cacheKey = "ors_geocode:v5:" . md5($address . serialize($queryParams));
        $requestUrl = $this->baseUrl . 'geocode/search';

        Log::info("Attempting to geocode address '{$address}' using OpenRouteService.", [
            'cache_key' => $cacheKey,
            'url' => $requestUrl,
            'query_params' => $queryParams
        ]);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($address, $requestUrl, $queryParams) {
            try {
                $response = Http::withOptions([
                    'verify' => $this->sslVerify,
                    'timeout' => $this->timeout,
                    'connect_timeout' => 10, // Shorter connect timeout
                ])
                    ->withHeaders([
                        'Authorization' => $this->apiKey,
                        'Accept' => 'application/json; charset=utf-8',
                    ])
                    ->retry(2, 500, function ($exception, $request) {
                        // Retry only on server errors or connection issues
                        return $exception instanceof \Illuminate\Http\Client\ConnectionException || $request->response()->serverError();
                    })
                    ->get($requestUrl, $queryParams);

                // Log the raw response for detailed inspection
                Log::debug("OpenRouteService Geocoding API RAW RESPONSE for '{$address}':", [
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'body' => $response->body(),
                    'effective_uri' => (string) $response->effectiveUri()
                ]);

                return $this->handleGeocodeResponse($response, $address);

            } catch (RequestException $e) {
                Log::error("Geocoding HTTP Request failed for '{$address}'.", [
                    'url' => $requestUrl, 'params' => $queryParams,
                    'error' => $e->getMessage(),
                    'response_status' => optional($e->response)->status(),
                    'response_body' => optional($e->response)->body(),
                ]);
                throw new \Exception("Geocoding service request error for '{$address}': " . $e->getMessage(), 0, $e);
            } catch (\Exception $e) { // Catch any other unexpected errors
                Log::error("Geocoding processing failed unexpectedly for '{$address}'.", [
                    'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()
                ]);
                throw new \Exception("Geocoding service unavailable for '{$address}': " . $e->getMessage(), 0, $e);
            }
        });
    }

    private function handleGeocodeResponse(HttpResponse $response, string $address): array
    {
        if (!$response->successful()) {
            $this->logApiError('Geocoding', $response, ['address' => $address]);
            throw new \Exception("Geocoding API Error: {$response->status()} for address '{$address}'. Check logs for full API response.");
        }

        $data = $response->json();

        if (is_null($data)) {
            Log::error("Geocoding response for '{$address}' was not valid JSON.", [
                'status' => $response->status(), 'body' => $response->body()
            ]);
            throw new \Exception("Geocoding service returned non-JSON response for '{$address}'.");
        }

        if (empty($data['features']) || !is_array($data['features'])) {
            Log::warning("No geocoding results (features array) found for '{$address}' in parsed JSON.", [
                'address' => $address, 'parsed_json_response' => $data
            ]);
            throw new \Exception("No results (features array) found for '{$address}'. Check logs for full API response.");
        }

        // Logic to select the best feature if multiple are returned.
        // For now, we take the first one.
        // You could iterate $data['features'] and apply heuristics (e.g. check 'type', 'confidence').
        $feature = $data['features'][0];

        if (!isset($feature['geometry']['coordinates']) || !is_array($feature['geometry']['coordinates']) || count($feature['geometry']['coordinates']) < 2) {
            Log::error("Geocoding result for '{$address}' (feature 0) missing or malformed geometry.coordinates.", [
                'feature' => $feature, 'full_response_data' => $data
            ]);
            throw new \Exception("Geocoding result for '{$address}' is malformed (missing coordinates).");
        }

        $coordinates = $feature['geometry']['coordinates']; // [lng, lat]
        $label = $feature['properties']['label'] ?? $address;

        Log::info("Successfully geocoded '{$address}' to: '{$label}'.", [
            'lat' => (float)$coordinates[1], 'lng' => (float)$coordinates[0]
        ]);

        return [
            'lat' => (float)$coordinates[1],
            'lng' => (float)$coordinates[0],
            'formatted' => $label,
            'raw_feature' => $feature // Optionally include the raw feature for further inspection if needed
        ];
    }

    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin, 'Origin');
        $this->validateCoordinates($destination, 'Destination');

        Log::info('Requesting route details from OpenRouteService.', [
            'origin_coords' => $origin,
            'destination_coords' => $destination
        ]);

        // Pre-emptive check for identical coordinates
        if (abs($origin['lat'] - $destination['lat']) < 0.00001 && abs($origin['lng'] - $destination['lng']) < 0.00001) {
            Log::warning('OpenRouteService::getRouteDetails called with identical origin and destination coordinates.', [
                'origin' => $origin, 'destination' => $destination
            ]);
            throw new \InvalidArgumentException("Origin and destination are effectively the same point after geocoding. Cannot calculate a route.");
        }

        $cacheKey = "ors_route:v3:" . md5(serialize([$origin, $destination, 'v2/driving-car/json']));
        $requestUrl = $this->baseUrl . 'v2/directions/driving-car/json';

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($origin, $destination, $requestUrl) {
            try {
                $payload = [
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']], // lng, lat order
                        [$destination['lng'], $destination['lat']]
                    ],
                    'instructions' => false, // Set to true if needed
                    'geometry' => false,     // Set to true if needed, will increase response size
                     'preference' => 'fastest', // Default is 'recommended'
                    // 'units' => 'm', // Default
                ];

                Log::debug('OpenRouteService Directions API REQUEST payload:', $payload);

                $response = Http::withOptions([
                    'verify' => $this->sslVerify,
                    'timeout' => $this->timeout,
                    'connect_timeout' => 10,
                ])
                    ->withHeaders([
                        'Authorization' => $this->apiKey,
                        'Accept' => 'application/json; charset=utf-8',
                        'Content-Type' => 'application/json; charset=utf-8',
                    ])
                    ->retry(1, 700, function ($exception, $request) { // Fewer retries for routing
                        return $exception instanceof \Illuminate\Http\Client\ConnectionException || $request->response()->serverError();
                    })
                    ->post($requestUrl, $payload);

                // Log raw routing response for debugging
                Log::debug("OpenRouteService Routing API RAW RESPONSE:", [
                    'status' => $response->status(),
                    'body_snippet' => substr($response->body(), 0, 1000), // Snippet to avoid huge logs
                    'effective_uri' => (string) $response->effectiveUri()
                ]);


                return $this->handleRouteResponse($response, $origin, $destination);

            } catch (RequestException $e) {
                Log::error("Routing HTTP Request failed.", [
                    'url' => $requestUrl, 'origin' => $origin, 'destination' => $destination,
                    'error' => $e->getMessage(),
                    'response_status' => optional($e->response)->status(),
                    'response_body' => optional($e->response)->body(),
                ]);
                throw new \Exception("Routing service request error: " . $e->getMessage(), 0, $e);
            } catch (\InvalidArgumentException $e) { // To catch our pre-emptive check from above
                throw $e; // Re-throw as is
            } catch (\Exception $e) {
                Log::error("Routing processing failed unexpectedly.", [
                    'origin' => $origin, 'destination' => $destination,
                    'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()
                ]);
                throw new \Exception("Routing service unavailable: " . $e->getMessage(), 0, $e);
            }
        });
    }

    private function handleRouteResponse(HttpResponse $response, ?array $origin = null, ?array $destination = null): array
    {
        Log::debug("Entering handleRouteResponse for routing. Status: {$response->status()}", [
            'origin' => $origin, 'destination' => $destination,
            'body_snippet' => substr($response->body(), 0, 500) // Log snippet on entry
        ]);

        if (!$response->successful()) {
            $this->logApiError('Routing', $response, ['origin' => $origin, 'destination' => $destination]);
            // Attempt to get a more specific error from ORS JSON response
            $orsError = $response->json('error.message');
            $errorMessage = "Routing API Error: {$response->status()}";
            if ($orsError) {
                $errorMessage .= " - ORS Message: " . $orsError;
            } else {
                // Fallback if ORS error message isn't in expected JSON format or response isn't JSON
                $body = $response->body();
                $errorMessage .= " - Body: " . (strlen($body) > 250 ? substr($body, 0, 250) . '...' : $body);
            }
            throw new \Exception($errorMessage);
        }

        $data = $response->json();

        if (is_null($data)) {
            Log::error("OpenRouteService routing response was not valid JSON.", [
                'origin' => $origin, 'destination' => $destination,
                'status' => $response->status(), 'raw_body' => $response->body()
            ]);
            throw new \Exception("Routing service returned non-JSON response. Status: " . $response->status());
        }

        Log::info('OpenRouteService successful routing API response (parsed JSON).', [
            'origin' => $origin, 'destination' => $destination, 'parsed_data_snippet' => substr(json_encode($data), 0, 500)
        ]);

        if (empty($data['routes']) || !isset($data['routes'][0]) || !is_array($data['routes'][0]) || empty($data['routes'][0]['summary']) || !is_array($data['routes'][0]['summary'])) {
            $responseBodyForError = json_encode($data);
            Log::warning("Invalid route response format or empty/malformed summary from OpenRouteService.", [
                'origin' => $origin, 'destination' => $destination,
                'response_body_preview' => strlen($responseBodyForError) > 500 ? substr($responseBodyForError, 0, 497) . "..." : $responseBodyForError
            ]);
            $errorDetail = strlen($responseBodyForError) > 200 ? substr($responseBodyForError, 0, 197) . "... (see logs)" : $responseBodyForError;
            throw new \Exception("Invalid route response from ORS (empty/malformed summary): " . $errorDetail);
        }

        $summary = $data['routes'][0]['summary'];
        if (!isset($summary['distance']) || !is_numeric($summary['distance']) || !isset($summary['duration']) || !is_numeric($summary['duration'])) {
            $responseBodyForError = json_encode($data);
            Log::warning("Route summary missing distance/duration or values are not numeric.", [
                'origin' => $origin, 'destination' => $destination, 'summary' => $summary,
                'response_body_preview' => strlen($responseBodyForError) > 500 ? substr($responseBodyForError, 0, 497) . "..." : $responseBodyForError
            ]);
            $errorDetail = strlen($responseBodyForError) > 200 ? substr($responseBodyForError, 0, 197) . "... (see logs)" : $responseBodyForError;
            throw new \Exception("Route summary incomplete or invalid (missing numeric distance/duration). Response: " . $errorDetail);
        }

        $result = [
            'distance' => (float) $summary['distance'], // in meters
            'duration' => (float) $summary['duration'], // in seconds
            // 'geometry' => $data['routes'][0]['geometry'] ?? null, // Add if 'geometry' was requested and needed
        ];

        Log::debug("handleRouteResponse successfully processed and returning valid array.", $result);
        return $result;
    }

    private function validateCoordinates(array $coordinates, string $label = 'Coordinates'): void
    {
        $requiredKeys = ['lat', 'lng'];
        foreach ($requiredKeys as $key) {
            if (!isset($coordinates[$key])) {
                throw new \InvalidArgumentException("{$label}: Missing coordinate key: {$key}.");
            }
            if (!is_numeric($coordinates[$key])) {
                throw new \InvalidArgumentException("{$label}: Invalid coordinate value for {$key}. Must be numeric.");
            }
        }
        if ($coordinates['lat'] < -90 || $coordinates['lat'] > 90) {
            throw new \InvalidArgumentException("{$label}: Latitude out of range (-90 to 90).");
        }
        if ($coordinates['lng'] < -180 || $coordinates['lng'] > 180) {
            throw new \InvalidArgumentException("{$label}: Longitude out of range (-180 to 180).");
        }
    }

    private function logApiError(string $type, HttpResponse $response, array $context = []): void
    {
        Log::error("OpenRouteService {$type} API Error", array_merge([
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(), // Log full body for errors
            'request_url' => (string) $response->effectiveUri(),
        ], $context));
    }

    // Optional: Fluent setters if you need to override config per instance
    public function setSslVerify(bool $sslVerify): self
    {
        $this->sslVerify = $sslVerify;
        return $this;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/').'/';
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
