<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\ServiceDiscovery;

class ConsulRegister extends BaseCommand
{
    protected $group       = 'Consul';
    protected $name        = 'consul:register';
    protected $description = 'Register or deregister the gateway in Consul';
    protected $usage       = 'consul:register [-d]';
    protected $options     = [
        '-d' => 'Deregister the gateway service',
    ];

    public function run(array $params)
    {
        $deregister = isset($params['d']) || CLI::getOption('d');
        $discovery = new ServiceDiscovery();

        if ($deregister) {
            $ok = $discovery->deregisterGateway();
            if ($ok) {
                CLI::write('Gateway deregistered from Consul.', 'green');
            } else {
                CLI::error('Failed to deregister gateway from Consul.');
            }
            return;
        }

        $ok = $discovery->registerGateway();
        if ($ok) {
            CLI::write('Gateway registered to Consul.', 'green');
        } else {
            CLI::error('Failed to register gateway to Consul.');
        }
    }
}
