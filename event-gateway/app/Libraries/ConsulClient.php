<?php

namespace App\Libraries;

class ConsulClient
{
    private string $baseUrl;

    public function __construct(string $host, int $port)
    {
        $host = rtrim($host, '/');
        $this->baseUrl = "http://{$host}:{$port}";
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
        $query = $passingOnly ? '?passing=1' : '';
        [$status, $body] = $this->request('GET', "/v1/health/service/{$serviceName}{$query}");
        if ($status !== 200 || $body === '') {
            return [];
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;
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
