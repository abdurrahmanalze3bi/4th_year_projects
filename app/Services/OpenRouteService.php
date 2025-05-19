<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Interfaces\GeocodingServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OpenRouteService implements GeocodingServiceInterface
{
    protected string $apiKey;
    protected int $cacheTtl;
    protected string $caBundlePath;

    public function __construct(string $apiKey, int $cacheTtl)
    {
        $this->apiKey = $apiKey;
        $this->cacheTtl = $cacheTtl;
        $this->caBundlePath = env('CURL_CA_BUNDLE', true);
    }

    public function geocodeAddress(string $address): array
    {
        return Cache::remember("geocode:{$address}", $this->cacheTtl, function() use ($address) {
            $response = Http::withOptions(['verify' => $this->caBundlePath])
                ->timeout(15)
                ->retry(2, 500)
                ->get(config('services.openroute.geocode_endpoint'), [
                    'api_key' => $this->apiKey,
                    'text' => $address
                ]);

            $this->validateGeocodingResponse($response);
            return $this->parseGeocodingResponse($response->json());
        });
    }

    public function getRouteDetails(array $origin, array $destination): array
    {
        $this->validateCoordinates($origin);
        $this->validateCoordinates($destination);

        $cacheKey = md5("route:{$origin['lat']},{$origin['lng']}:{$destination['lat']},{$destination['lng']}");

        return Cache::remember($cacheKey, $this->cacheTtl, function() use ($origin, $destination) {
            $response = Http::withOptions(['verify' => $this->caBundlePath])
                ->timeout(20)
                ->retry(2, 500)
                ->withHeaders(['Authorization' => $this->apiKey])
                ->post(config('services.openroute.directions_endpoint'), [
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']],
                        [$destination['lng'], $destination['lat']]
                    ]
                ]);

            $this->validateRoutingResponse($response);
            return $this->formatRouteData($response->json());
        });
    }

    private function validateCoordinates(array $coordinates): void
    {
        $lat = $coordinates['lat'];
        $lng = $coordinates['lng'];

        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new \Exception("Invalid coordinate format: lat=$lat, lng=$lng");
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \Exception("Coordinates out of range: lat=$lat, lng=$lng");
        }
    }

    private function validateGeocodingResponse($response): void
    {
        if ($response->failed()) {
            $this->logError('Geocoding', $response);
            throw new \Exception("Geocoding failed: " . $this->extractApiError($response));
        }

        $data = $response->json();
        if (empty($data['features'])) {
            throw new \Exception("No results found for this address");
        }
    }

    private function validateRoutingResponse($response): void
    {
        if ($response->failed()) {
            $this->logError('Routing', $response);
            throw new \Exception("Routing failed: " . $this->extractApiError($response));
        }

        $data = $response->json();
        if (empty($data['routes'])) {
            throw new \Exception("No route found between these points");
        }
    }

    private function logError(string $type, $response): void
    {
        Log::error("OpenRouteService {$type} Error", [
            'status' => $response->status(),
            'error' => $response->json()['error'] ?? null,
            'request' => $response->effectiveUri()->__toString()
        ]);
    }

    private function extractApiError($response): string
    {
        $error = $response->json()['error'] ?? [];
        return $error['message'] ?? 'Unknown API error';
    }

    private function parseGeocodingResponse(array $data): array
    {
        $coordinates = $data['features'][0]['geometry']['coordinates'];
        return [
            'lng' => (float)$coordinates[0],
            'lat' => (float)$coordinates[1]
        ];
    }

    private function formatRouteData(array $data): array
    {
        return [
            'distance' => $data['routes'][0]['summary']['distance'] ?? 0,
            'duration' => $data['routes'][0]['summary']['duration'] ?? 0,
            'geometry' => $data['routes'][0]['geometry'] ?? '',
            'waypoints' => $data['routes'][0]['way_points'] ?? []
        ];
    }
}
