<?php 
namespace App\ServiceDiscovery\LoadBalance;

use App\ServiceDiscovery\Exception\LoadBalanceException;

class LoadBalance
{
    public static $strategyMap = [
        'random'  => \App\ServiceDiscovery\LoadBalance\Random::class,
        'rr'      => \App\ServiceDiscovery\LoadBalance\RoundRobin::class,
        'dynamic' => \App\ServiceDiscovery\LoadBalance\DynamicLoadBalancer::class,
        'least'   => \App\ServiceDiscovery\LoadBalance\LeastConn::class,
        'ip'      => \App\ServiceDiscovery\LoadBalance\IP_hash::class

    ];

    /**
     * 選定的負載策略
     *
     * @var object
     */
    public static $strategy;

    /**
     * 設定負載策略
     *
     * @param string $strategy
     * @return void
     */
    public static function setStrategy($strategy)
    {
        $strategy = strtolower(trim((string) $strategy));
        if ($strategy === '') {
            $strategy = 'random';
        }
        if (!isset(static::$strategyMap[$strategy])) {
            throw LoadBalanceException::forStrategyNotFound($strategy);
        }
        
        $class = static::$strategyMap[$strategy];
        static::$strategy = new $class();
    }


    /**
     * 執行負載策略
     *
     * @return array
     */
    public static function do(array $services, array $context = []): array
    {
        return static::pick('default', $services, $context);
    }

    public static function pick(string $serviceName, array $services, array $context = []): array
    {
        if (!static::$strategy) {
            static::setStrategy('random');
        }

        try {
            return static::$strategy->do($services, $context);
        } catch (\Throwable $e) {
            error_log("[LoadBalance] Strategy failed for {$serviceName}: " . $e->getMessage());
            $fallbackClass = static::$strategyMap['random'];
            $fallback = new $fallbackClass();
            return $fallback->do($services, $context);
        }
    }
}
