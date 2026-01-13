<?php
namespace App\ServiceDiscovery\LoadBalance;

use Workerman\Timer;

class CachedScoreManager
{
    private static ?CachedScoreManager $instance = null;
    private array $scoreCache = [];

    private function __construct()
    {
        $this->startDebugMonitor();   // 啟動時自動開始監控與同步快取
    }

    public static function getInstance(): CachedScoreManager
    {
        if (self::$instance === null) {
            self::$instance = new CachedScoreManager();
        }
        return self::$instance;
    }

    public function getScore(string $host): ?float
    {
        return $this->scoreCache[$host] ?? null;
    }

    public function getAllScores(): array
    {
        return $this->scoreCache;
    }

    public function updateCacheDirectly(array $scores): void
    {
        $this->scoreCache = $scores;
        error_log("[CachedScoreManager] Cache updated directly: " . json_encode($scores));
    }

    private function startDebugMonitor(): void
    {
        $interval = (int) (getenv('SERVICEDISCOVERY_SCORE_POLL_INTERVAL') ?: 5);
        $interval = max(1, $interval);
        // 每個 Worker 啟動時都會建立自己的定時任務
        Timer::add($interval, function () {
            try {
                $redis = new \Redis();
                $host = getenv('REDIS_HOST') ?: '127.0.0.1';
                $port = (int) (getenv('REDIS_PORT') ?: 6379);
                $timeout = (float) (getenv('REDIS_TIMEOUT') ?: 1.0);
                if (!$redis->connect($host, $port, $timeout)) {
                    throw new \RuntimeException("Redis connection failed: {$host}:{$port}");
                }
                $db = getenv('REDIS_DB');
                if ($db !== false && $db !== '') {
                    $redis->select((int) $db);
                }

                $scoreKey = getenv('SERVICEDISCOVERY_SCORE_KEY') ?: 'metrics:anser-gateway';
                $scores = $redis->hGetAll($scoreKey);

                if (!empty($scores)) {
                    foreach ($scores as $host => $score) {
                        $this->scoreCache[$host] = (float)$score;
                    }
                    error_log("[CachedScoreManager] [" . date('H:i:s') . "] Cache snapshot: " . json_encode($this->scoreCache));
                } else {
                    error_log("[CachedScoreManager] [" . date('H:i:s') . "] Cache snapshot: EMPTY");
                }

                $redis->close();
            } catch (\Exception $e) {
                error_log("[CachedScoreManager] Redis error during monitor: " . $e->getMessage());
            }
        });
    }
}
