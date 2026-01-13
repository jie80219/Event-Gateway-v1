<?php

namespace App\ServiceDiscovery;

use App\Libraries\ConsulClient;
use Config\ServiceDiscovery as ServiceDiscoveryConfig;
use App\ServiceDiscovery\LoadBalance\LoadBalance;
use App\ServiceDiscovery\Exception\ServiceNotFoundException;
use SDPMlab\Anser\Service\ServiceList;
use SDPMlab\Anser\Service\ServiceSettings;

class ServiceDiscovery
{
    private ServiceDiscoveryConfig $config;
    private ConsulClient $client;
    private array $localServices = [];
    private array $localVerifyServices = [];
    private array $defaultServiceGroup = [];
    private int $reloadTime;

    public function __construct(?ServiceDiscoveryConfig $config = null, ?ConsulClient $client = null)
    {
        $this->config = $config ?? new ServiceDiscoveryConfig();
        $host = getenv('CONSUL_HOST') ?: $this->config->consulHost;
        $port = getenv('CONSUL_PORT') ?: $this->config->consulPort;
        $scheme = getenv('CONSUL_SCHEME') ?: $this->config->consulScheme;
        $datacenter = getenv('CONSUL_DATACENTER') ?: $this->config->consulDataCenter;

        $this->client = $client ?? new ConsulClient(
            (string) $host,
            (int) $port,
            (string) $scheme,
            $datacenter !== '' ? (string) $datacenter : null
        );

        $this->reloadTime = (int) (getenv('servicediscovery.reloadTime') ?: $this->config->reloadTime);
        $strategy = (string) (getenv('servicediscovery.lbStrategy') ?: $this->config->lbStrategy);
        try {
            LoadBalance::setStrategy($strategy);
        } catch (\Throwable $e) {
            error_log('[ServiceDiscovery] Invalid load balance strategy, fallback to random.');
            LoadBalance::setStrategy('random');
        }

        $this->defaultServiceGroup = $this->parseDefaultServiceGroup();
    }

    public function getReloadTime(): int
    {
        return max(1, $this->reloadTime);
    }

    public function doServiceDiscovery(): bool
    {
        if (!$this->defaultServiceGroup) {
            return false;
        }

        $found = $this->doFoundServices($this->defaultServiceGroup);
        $next = $this->buildServiceMap($found);
        $changed = $this->isDifferent($next, $this->localVerifyServices);

        $this->localServices = $next;
        if ($changed) {
            $this->localVerifyServices = $next;
        }

        return $changed;
    }

    public function doFoundServices(array $serviceNames): array
    {
        $serviceNames = array_values(array_filter($serviceNames));
        if (!$serviceNames) {
            return [];
        }

        if (!function_exists('curl_multi_init')) {
            return $this->fetchServicesSequentially($serviceNames);
        }

        $multiHandle = curl_multi_init();
        $handles = [];
        $timeout = max(1, $this->config->discoveryCacheTime);

        foreach ($serviceNames as $serviceName) {
            $url = $this->client->buildHealthUrl($serviceName, true);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$serviceName] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status > CURLM_OK) {
                break;
            }
            curl_multi_select($multiHandle, 0.2);
        } while ($running);

        $results = [];
        foreach ($handles as $serviceName => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if ($code !== 200 || $body === false || $body === '') {
                $results[$serviceName] = [];
                continue;
            }

            $data = json_decode($body, true);
            $results[$serviceName] = is_array($data) ? $data : [];
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    public function setService(string $serviceName, array $entries): void
    {
        $this->localServices[$serviceName] = $this->parseServiceEntries($entries);
    }

    public function setServices(array $services): void
    {
        foreach ($services as $serviceName => $entries) {
            $this->setService((string) $serviceName, is_array($entries) ? $entries : []);
        }
    }

    public function serviceDataHandler(string $serviceName): ?ServiceSettings
    {
        if (filter_var($serviceName, FILTER_VALIDATE_URL)) {
            return $this->buildFromUrl($serviceName);
        }

        $localServices = ServiceList::getServiceList();
        if (isset($localServices[$serviceName])) {
            return $localServices[$serviceName];
        }

        if (!isset($this->localServices[$serviceName])) {
            $entries = $this->client->discoverService($serviceName);
            if ($entries) {
                $this->setService($serviceName, $entries);
            }
        }

        if (!isset($this->localServices[$serviceName]) || !$this->localServices[$serviceName]) {
            throw new ServiceNotFoundException("Service not found: {$serviceName}");
        }

        $instances = $this->localServices[$serviceName];
        try {
            $picked = LoadBalance::pick($serviceName, $instances, [
                'ip' => $this->getClientIp(),
            ]);
        } catch (\Throwable $e) {
            throw new ServiceNotFoundException("Service load balance failed: {$serviceName}");
        }

        if ($picked === null) {
            throw new ServiceNotFoundException("Service has no available instance: {$serviceName}");
        }

        return new ServiceSettings(
            $serviceName,
            $picked['address'],
            $picked['port'],
            $picked['is_https']
        );
    }

    public function discover(string $serviceName, bool $passingOnly = true): ?array
    {
        $entries = $this->client->discoverService($serviceName, $passingOnly);
        if (!$entries) {
            return null;
        }

        $parsed = $this->parseServiceEntries($entries);
        return $parsed[0] ?? null;
    }

    private function parseDefaultServiceGroup(): array
    {
        $env = getenv('servicediscovery.defaultServiceGroup');
        if ($env !== false && trim($env) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $env))));
        }

        $legacy = getenv('CONSUL_DISCOVERY_SERVICES');
        if ($legacy !== false && trim($legacy) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $legacy))));
        }

        if (is_array($this->config->defaultServiceGroup)) {
            return array_values(array_filter($this->config->defaultServiceGroup));
        }

        $raw = (string) $this->config->defaultServiceGroup;
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function fetchServicesSequentially(array $serviceNames): array
    {
        $results = [];
        foreach ($serviceNames as $serviceName) {
            $results[$serviceName] = $this->client->discoverService($serviceName);
        }
        return $results;
    }

    private function parseServiceEntries(array $entries): array
    {
        $instances = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $service = $entry['Service'] ?? [];
            $node = $entry['Node'] ?? [];
            if (!is_array($service) || !is_array($node)) {
                continue;
            }

            $address = $service['Address'] ?? $node['Address'] ?? null;
            $port = $service['Port'] ?? null;
            if (!$address || !$port) {
                continue;
            }

            $tags = $service['Tags'] ?? [];
            $meta = $service['Meta'] ?? [];
            $scheme = $this->parseHttpScheme($tags, $meta);

            $instances[] = [
                'address' => (string) $address,
                'host' => (string) ($node['Node'] ?? $service['ID'] ?? $service['Service'] ?? $address),
                'port' => (int) $port,
                'is_https' => $scheme === 'https',
            ];
        }

        usort($instances, static function ($a, $b) {
            $left = $a['address'] . ':' . $a['port'];
            $right = $b['address'] . ':' . $b['port'];
            return $left <=> $right;
        });

        return $instances;
    }

    private function parseHttpScheme(array $tags, array $meta): string
    {
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            if (str_starts_with($tag, 'http_scheme=')) {
                $value = substr($tag, strlen('http_scheme='));
                return strtolower(trim($value));
            }
        }

        if (isset($meta['http_scheme'])) {
            return strtolower((string) $meta['http_scheme']);
        }

        if (isset($meta['https'])) {
            return filter_var($meta['https'], FILTER_VALIDATE_BOOLEAN) ? 'https' : 'http';
        }

        return 'http';
    }

    private function buildServiceMap(array $found): array
    {
        $services = [];
        foreach ($found as $serviceName => $entries) {
            $services[$serviceName] = $this->parseServiceEntries(is_array($entries) ? $entries : []);
        }
        return $services;
    }

    private function isDifferent(array $current, array $previous): bool
    {
        return json_encode($current) !== json_encode($previous);
    }

    private function buildFromUrl(string $url): ?ServiceSettings
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $serviceName = $parts['host'];

        return new ServiceSettings(
            $serviceName,
            $parts['host'],
            (int) $port,
            $scheme === 'https'
        );
    }

    private function getClientIp(): string
    {
        if (PHP_SAPI === 'cli') {
            $ip = getenv('CLIENT_IP');
            return $ip ?: '127.0.0.1';
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
