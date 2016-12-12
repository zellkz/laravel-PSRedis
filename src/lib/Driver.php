<?php namespace Indatus\LaravelPSRedis;

use PSRedis\Client as PSRedisClient;
use PSRedis\Client;
use PSRedis\MasterDiscovery;
use PSRedis\HAClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use PSRedis\MasterDiscovery\BackoffStrategy\Incremental;

/**
 * Class Driver
 *
 * @copyright  Indatus 2014
 * @author     Damien Russell <drussell@indatus.com>
 */
class Driver
{
    /** @var MasterDiscovery $masterDiscovery The mechanism for determining the master */
    protected $masterDiscovery;

    /** @var HAClient $HAClient is the highly available client which handles the auto-failover. */
    protected $HAClient;


    /**
     * Constructor
     *
     * @return void
     */
    public function __construct()
    {
        $this->setUpMasterDiscovery();

        $this->addSentinels();

        $this->HAClient = App::make(
            'PSRedis\HAClient',
            [$this->masterDiscovery]
        );
    }


    /**
     * Get the config values for the redis database.
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'cluster' => Config::get('database.redis.cluster'),
            'default' => [
                'host' => $this->HAClient->getIpAddress(),
                'port' => $this->HAClient->getPort(),
                'password' => $this->HAClient->getPassword()
            ]
        ];
    }

    public function getBackOffStrategy()
    {
        /** @var array $backOffConfig */
        $backOffConfig = Config::get('database.redis.backoff-strategy');

        /** @var Incremental $incrementalBackOff */
        $incrementalBackOff = App::make(
            'PSRedis\MasterDiscovery\BackoffStrategy\Incremental', [
                $backOffConfig['wait-time'],
                $backOffConfig['increment']
            ]
        );

        $incrementalBackOff->setMaxAttempts($backOffConfig['max-attempts']);

        return $incrementalBackOff;
    }

    public function setUpMasterDiscovery()
    {
        $this->masterDiscovery = App::make(
            'PSRedis\MasterDiscovery',
            [Config::get('database.redis.nodeSetName')]
        );

        $this->masterDiscovery->setBackoffStrategy($this->getBackOffStrategy());
    }

    public function addSentinels()
    {
        $clients = Config::get('database.redis.masters');
        $password = Config::get('database.redis.password');
        foreach($clients as $client) {
            $sentinel = App::make(
                'PSRedis\Client',
                [$client['host'], $client['port'], null, Client::TYPE_SENTINEL, $password]
            );

            $this->masterDiscovery->addSentinel($sentinel);
        }
    }
}