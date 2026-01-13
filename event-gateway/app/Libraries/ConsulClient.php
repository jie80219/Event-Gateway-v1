<?php

namespace App\Libraries;

class ConsulClient
{
    private string $baseUrl;
    private ?string $datacenter;

    public function __construct(string $host, int $port, string $scheme = 'http', ?string $datacenter = null)
    {
        $host = rtrim($host, '/');
        $scheme = $scheme ?: 'http';
        $this->baseUrl = "{$scheme}://{$host}:{$port}";
        $this->datacenter = $datacenter ?: null;
    }

    public function registerService(array $payload): bool
    {
        [$status] = $this->request('PUT', '/v1/agent/service/register', $payload);
        return $status >= 200 && $status < 300;
    }

    public function deregisterService(string $serviceId): bool
    {
        $serviceId = rawurlencode($serviceId);
        [$status] = $this->request('PUT', "/v1/agent/service/deregister/{$serviceId}");
        return $status >= 200 && $status < 300;
    }

    public function discoverService(string $serviceName, bool $passingOnly = true): array
    {
        $serviceName = rawurlencode($serviceName);
        $query = [];
        if ($passingOnly) {
            $query['passing'] = '1';
        }
        if ($this->datacenter) {
            $query['dc'] = $this->datacenter;
        }
        [$status, $body] = $this->request('GET', "/v1/health/service/{$serviceName}", null, $query);
        if ($status !== 200 || $body === '') {
            return [];
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    public function buildHealthUrl(string $serviceName, bool $passingOnly = true): string
    {
        $serviceName = rawurlencode($serviceName);
        $query = [];
        if ($passingOnly) {
            $query['passing'] = '1';
        }
        if ($this->datacenter) {
            $query['dc'] = $this->datacenter;
        }
        return $this->buildUrl("/v1/health/service/{$serviceName}", $query);
    }

    public function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseUrl . $path;
        if (!$query) {
            return $url;
        }

        $queryString = http_build_query($query);
        return $queryString !== '' ? "{$url}?{$queryString}" : $url;
    }

    private function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        $headers = "Content-Type: application/json\r\n";

        $options = [
            'http' => [
                'method' => $method,
                'header' => $headers,
                'ignore_errors' => true,
                'timeout' => 3,
            ],
        ];

        if ($payload !== null) {
            $options['http']['content'] = json_encode($payload);
        }

        $context = stream_context_create($options);
        $body = @file_get_contents($url, false, $context);
        $status = $this->extractStatusCode($http_response_header ?? []);

        return [$status, $body !== false ? $body : ''];
    }

    private function extractStatusCode(array $headers): int
    {
        if (!isset($headers[0])) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
