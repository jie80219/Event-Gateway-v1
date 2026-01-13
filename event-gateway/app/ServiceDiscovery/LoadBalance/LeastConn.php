<?php
namespace App\ServiceDiscovery\LoadBalance;

use App\ServiceDiscovery\LoadBalance\LoadBalanceInterface;
use Redis;

class LeastConn implements LoadBalanceInterface
{
    protected Redis $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $timeout = (float) (getenv('REDIS_TIMEOUT') ?: 1.0);
        if (!$this->redis->connect($host, $port, $timeout)) {
            throw new \RuntimeException("Redis connection failed: {$host}:{$port}");
        }
        $db = getenv('REDIS_DB');
        if ($db !== false && $db !== '') {
            $this->redis->select((int) $db);
        }
    }

    /**
     * 根據最少連線數選擇服務
     *
     * @param array $services [['address' => '10.1.1.x', ...], ...]
     * @return array
     * @throws \Exception
     */
    public function do(array $services, array $context = []): array
    {
        $serviceCount = count($services);
        if ($serviceCount === 0) {
            throw new \RuntimeException("服務列表為空");
        }

        $minConn = PHP_INT_MAX;
        $selected = null;

        // 選擇連線數最少的服務
        foreach ($services as $service) {
            if (!isset($service['address'])) {
                error_log("[LeastConn] Service is missing 'address' key");
                continue; // 跳過缺少 'address' 鍵的服務
            }

            $address = $service['address']; // 使用 address 欄位
            $port = $service['port'] ?? null;
            $key = $port ? "conn:{$address}:{$port}" : "conn:{$address}";

            // 取得當前的連線數
            $conn = (int) $this->redis->get($key);
            if ($conn < $minConn) {
                $minConn = $conn;
                $selected = $service;
            }
        }

        if (!$selected) {
            throw new \RuntimeException("無法選擇最少連線數的服務");
        }

        // 選擇到伺服器後，將其連線數 +1
        $selectedPort = $selected['port'] ?? null;
        $selectedKey = $selectedPort ? "conn:{$selected['address']}:{$selectedPort}" : "conn:{$selected['address']}";
        $this->redis->incr($selectedKey);

        return $selected;
    }

    /**
     * 當請求完成後，將伺服器的連線數 -1
     *
     * @param string $address
     */
    public function decrementConn(string $address, ?int $port = null): void
    {
        $key = $port ? "conn:{$address}:{$port}" : "conn:{$address}";
        $this->redis->decr($key);
    }
}
