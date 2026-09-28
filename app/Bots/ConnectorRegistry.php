<?php

namespace App\Bots;

use InvalidArgumentException;

class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    /**
     * @param  iterable<Connector>  $connectors
     */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors as $connector) {
            $this->connectors[$connector->code()] = $connector;
        }
    }

    public function get(string $code): Connector
    {
        return $this->connectors[$code] ?? throw new InvalidArgumentException("Unknown connector [{$code}].");
    }

    public function has(string $code): bool
    {
        return isset($this->connectors[$code]);
    }

    /**
     * @return array<string, Connector>
     */
    public function all(): array
    {
        return $this->connectors;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_values(array_map(fn (Connector $c) => ['value' => $c->code(), 'label' => $c->label()], $this->connectors));
    }
}
