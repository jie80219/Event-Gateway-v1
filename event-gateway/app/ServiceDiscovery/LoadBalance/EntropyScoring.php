<?php
namespace App\ServiceDiscovery\LoadBalance;

use Redis;

class EntropyScoring
{
    private Redis $redis;
    private array $metricKeys = ['cpu', 'mem', 'latency', 'load', 'disk_io_total', 'network_io_total', 'cpu_cores', 'mem_total'];
    private array $positiveKeys = ['cpu_cores', 'mem_total'];

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

    public function recalculateScores(): void
    {
        $hosts = [];
        foreach ($this->redis->keys('metrics:*') as $key) {
            $ttl = $this->redis->ttl($key);
            if ($ttl > 0 || $ttl === -1) {
                $hosts[] = str_replace('metrics:', '', $key);
            }
        }

        if (empty($hosts)) {
            echo "[EntropyScoring] No valid metrics found.\n";
            return;
        }

        $services = [];
        foreach ($hosts as $host) {
            $metrics = $this->redis->hGetAll("metrics:$host");

            if (
                isset($metrics['cpu'], $metrics['mem'], $metrics['latency'], $metrics['load'],
                      $metrics['disk_io'], $metrics['network_io'], $metrics['cpu_cores'], $metrics['mem_total'])
            ) {
                $diskIO = json_decode($metrics['disk_io'], true);
                $netIO = json_decode($metrics['network_io'], true);
                if (!is_array($diskIO) || !is_array($netIO)) continue;

                $services[] = [
                    'host' => $host,
                    'metrics' => [
                        'cpu' => (float)$metrics['cpu'],
                        'mem' => (float)$metrics['mem'],
                        'latency' => (float)$metrics['latency'],
                        'load' => (float)$metrics['load'],
                        'disk_io_total' => ($diskIO['read_kb'] ?? 0) + ($diskIO['write_kb'] ?? 0),
                        'network_io_total' => ($netIO['bytes_in_kbps'] ?? 0) + ($netIO['bytes_out_kbps'] ?? 0),
                        'cpu_cores' => (float)$metrics['cpu_cores'],
                        'mem_total' => (float)$metrics['mem_total'],
                    ]
                ];
            }
        }

        if (empty($services)) {
            echo "[EntropyScoring] No valid service metrics after parsing.\n";
            return;
        }

        $matrix = [];
        foreach ($this->metricKeys as $j => $key) {
            $col = array_column(array_column($services, 'metrics'), $key);
            $min = min($col);
            $max = max($col);

            foreach ($col as $i => $val) {
                if (in_array($key, $this->positiveKeys)) {
                    $matrix[$i][$j] = ($max - $min > 0) ? (($val - $min) / ($max - $min)) : 0;
                } else {
                    $matrix[$i][$j] = ($max - $min > 0) ? 1 - (($val - $min) / ($max - $min)) : 0;
                }
            }
        }

        $m = count($matrix);
        if ($m <= 1) {
            echo "[EntropyScoring] Not enough hosts to score.\n";
            return;
        }
        $n = count($this->metricKeys);
        $k = 1 / log($m);
        $entropy = [];

        for ($j = 0; $j < $n; $j++) {
            $sum = 0;
            for ($i = 0; $i < $m; $i++) {
                $p = $matrix[$i][$j];
                if ($p > 0) $sum += $p * log($p);
            }
            $entropy[$j] = -$k * $sum;
        }

        $diff = array_map(fn($e) => 1 - $e, $entropy);
        $weightSum = array_sum($diff);
        if ($weightSum <= 0) {
            echo "[EntropyScoring] Invalid weight sum, skipping.\n";
            return;
        }
        $weights = array_map(fn($d) => $d / $weightSum, $diff);

        echo "[EntropyScoring] Weights: " . json_encode($weights) . "\n";

        $scoreMap = [];
        foreach ($matrix as $i => $row) {
            $score = array_sum(array_map(fn($v, $w) => $v * $w, $row, $weights));
            $host = $services[$i]['host'];
            $scoreMap[$host] = round($score, 6); // 建議四捨五入防止浮點誤差
        }

        // 寫入 Redis
        $this->redis->multi();
        $scoreKey = getenv('SERVICEDISCOVERY_SCORE_KEY') ?: 'metrics:anser-gateway';
        $this->redis->del($scoreKey);
        $this->redis->hMSet($scoreKey, $scoreMap);
        $this->redis->exec();

        echo "[EntropyScoring] Scores saved to Redis.\n";

        // 更新本地快取
        CachedScoreManager::getInstance()->updateCacheDirectly($scoreMap);
    }

    public function recalculate(): void
    {
        $this->recalculateScores();
    }
}
