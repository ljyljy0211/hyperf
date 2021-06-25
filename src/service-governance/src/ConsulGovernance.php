<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ServiceGovernance;

use Hyperf\Consul\AgentInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ServiceGovernance\Register\ConsulAgent;
use Psr\Container\ContainerInterface;

class ConsulGovernance implements ServiceGovernanceInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $registeredServices = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(StdoutLoggerInterface::class);
    }

    public function getNodes(): array
    {
        // TODO: Implement getNodes() method.
    }

    public function register(string $name, string $host, int $port, array $metadata): void
    {
        $nextId = empty($metadata['id']) ? $this->generateId($this->getLastServiceId($name)) : $metadata['id'];
        $protocol = $metadata['protocol'];
        $requestBody = [
            'Name' => $name,
            'ID' => $nextId,
            'Address' => $host,
            'Port' => $port,
            'Meta' => [
                'Protocol' => $protocol,
            ],
        ];
        if ($protocol === 'jsonrpc-http') {
            $requestBody['Check'] = [
                'DeregisterCriticalServiceAfter' => '90m',
                'HTTP' => "http://{$host}:{$port}/",
                'Interval' => '1s',
            ];
        }
        if (in_array($protocol, ['jsonrpc', 'jsonrpc-tcp-length-check'], true)) {
            $requestBody['Check'] = [
                'DeregisterCriticalServiceAfter' => '90m',
                'TCP' => "{$host}:{$port}",
                'Interval' => '1s',
            ];
        }
        $response = $this->client()->registerService($requestBody);
        if ($response->getStatusCode() === 200) {
            $this->registeredServices[$name][$protocol][$host][$port] = true;
            $this->logger->info(sprintf('Service %s:%s register to the consul successfully.', $name, $nextId));
        } else {
            $this->logger->warning(sprintf('Service %s register to the consul failed.', $name));
        }
    }

    public function isRegistered(string $name, string $address, int $port, array $metadata): bool
    {
        $protocol = $metadata['protocol'];
        if (isset($this->registeredServices[$name][$protocol][$address][$port])) {
            return true;
        }
        $client = $this->client();
        $response = $client->services();
        if ($response->getStatusCode() !== 200) {
            $this->logger->warning(sprintf('Service %s register to the consul failed.', $name));
            return false;
        }
        $services = $response->json();
        $glue = ',';
        $tag = implode($glue, [$name, $address, $port, $protocol]);
        foreach ($services as $serviceId => $service) {
            if (! isset($service['Service'], $service['Address'], $service['Port'], $service['Meta']['Protocol'])) {
                continue;
            }
            $currentTag = implode($glue, [
                $service['Service'],
                $service['Address'],
                $service['Port'],
                $service['Meta']['Protocol'],
            ]);
            if ($currentTag === $tag) {
                $this->registeredServices[$name][$protocol][$address][$port] = true;
                return true;
            }
        }
        return false;
    }

    protected function client(): AgentInterface
    {
        return $this->container->get(ConsulAgent::class);
    }

    protected function getLastServiceId(string $name)
    {
        $maxId = -1;
        $lastService = $name;
        $services = $this->client()->services()->json();
        foreach ($services ?? [] as $id => $service) {
            if (isset($service['Service']) && $service['Service'] === $name) {
                $exploded = explode('-', (string) $id);
                $length = count($exploded);
                if ($length > 1 && is_numeric($exploded[$length - 1]) && $maxId < $exploded[$length - 1]) {
                    $maxId = $exploded[$length - 1];
                    $lastService = $service;
                }
            }
        }
        return $lastService['ID'] ?? $name;
    }

    protected function generateId(string $name)
    {
        $exploded = explode('-', $name);
        $length = count($exploded);
        $end = -1;
        if ($length > 1 && is_numeric($exploded[$length - 1])) {
            $end = $exploded[$length - 1];
            unset($exploded[$length - 1]);
        }
        $end = intval($end);
        ++$end;
        $exploded[] = $end;
        return implode('-', $exploded);
    }
}