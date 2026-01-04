<?php

namespace App\Libraries;

use Config\ServiceDiscovery as ServiceDiscoveryConfig;

class ServiceDiscovery
{
    private ServiceDiscoveryConfig $config;
    private ConsulClient $client;
    private array $cache = [];
    private array $cacheExpires = [];

    public function __construct(?ServiceDiscoveryConfig $config = null, ?ConsulClient $client = null)
    {
        $this->config = $config ?? new ServiceDiscoveryConfig();
        $host = getenv('CONSUL_HOST') ?: $this->config->consulHost;
        $port = getenv('CONSUL_PORT') ?: $this->config->consulPort;

        $this->client = $client ?? new ConsulClient((string) $host, (int) $port);
    }

    public function registerGateway(): bool
    {
        $serviceName = getenv('GATEWAY_SERVICE_NAME') ?: $this->config->gatewayServiceName;
        $serviceId = getenv('GATEWAY_SERVICE_ID') ?: $this->config->gatewayServiceId;
        $address = getenv('GATEWAY_ADDRESS') ?: $this->config->gatewayAddress;
        $port = getenv('GATEWAY_PORT') ?: $this->config->gatewayPort;
        $checkPath = getenv('GATEWAY_CHECK_PATH') ?: $this->config->gatewayHealthPath;
        $interval = getenv('GATEWAY_CHECK_INTERVAL') ?: $this->config->gatewayHealthInterval;
        $timeout = getenv('GATEWAY_CHECK_TIMEOUT') ?: $this->config->gatewayHealthTimeout;
        $tags = $this->config->gatewayTags;

        $payload = [
            'ID' => (string) $serviceId,
            'Name' => (string) $serviceName,
            'Address' => (string) $address,
            'Port' => (int) $port,
            'Tags' => is_array($tags) ? $tags : [],
            'Check' => [
                'HTTP' => "http://{$address}:{$port}{$checkPath}",
                'Interval' => (string) $interval,
                'Timeout' => (string) $timeout,
            ],
        ];

        return $this->client->registerService($payload);
    }

    public function deregisterGateway(): bool
    {
        $serviceId = getenv('GATEWAY_SERVICE_ID') ?: $this->config->gatewayServiceId;
        return $this->client->deregisterService((string) $serviceId);
    }

    public function discover(string $serviceName, bool $passingOnly = true): ?array
    {
        $ttl = (int) $this->config->discoveryCacheTime;
        $now = time();

        if (isset($this->cache[$serviceName], $this->cacheExpires[$serviceName])) {
            if ($this->cacheExpires[$serviceName] >= $now) {
                return $this->cache[$serviceName];
            }
        }

        $entries = $this->client->discoverService($serviceName, $passingOnly);
        if (!$entries) {
            return null;
        }

        $entry = $entries[0];
        if (!is_array($entry)) {
            return null;
        }

        $service = $entry['Service'] ?? [];
        $node = $entry['Node'] ?? [];
        if (!is_array($service) || !is_array($node)) {
            return null;
        }

        $address = $service['Address'] ?? $node['Address'] ?? null;
        $port = $service['Port'] ?? null;
        if (!$address || !$port) {
            return null;
        }

        $tags = $service['Tags'] ?? [];
        $meta = $service['Meta'] ?? [];
        $isHttps = false;

        if (is_array($tags) && in_array('https', $tags, true)) {
            $isHttps = true;
        }

        if (is_array($meta) && isset($meta['https'])) {
            $isHttps = filter_var($meta['https'], FILTER_VALIDATE_BOOLEAN);
        }

        $result = [
            'address' => (string) $address,
            'port' => (int) $port,
            'is_https' => (bool) $isHttps,
        ];

        $this->cache[$serviceName] = $result;
        $this->cacheExpires[$serviceName] = $now + $ttl;

        return $result;
    }
}
