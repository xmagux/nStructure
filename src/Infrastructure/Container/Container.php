<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container implements ContainerInterface
{
    private array $entries = [];
    private array $resolved = [];

    public function set(string $id, mixed $entry): self
    {
        $this->entries[$id] = $entry;
        unset($this->resolved[$id]);
        return $this;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (array_key_exists($id, $this->entries)) {
            $entry = $this->entries[$id];
            $value = $entry instanceof Closure ? $entry($this) : $entry;
            return $this->resolved[$id] = $value;
        }

        if (class_exists($id)) {
            return $this->resolved[$id] = $this->autowire($id);
        }

        throw new ContainerEntryNotFound(sprintf('Container entry not found: %s', $id));
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries) || array_key_exists($id, $this->resolved) || class_exists($id);
    }

    private function autowire(string $className): object
    {
        $reflection = new ReflectionClass($className);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(sprintf('Class is not instantiable: %s', $className));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new RuntimeException(sprintf(
                'Unable to resolve parameter $%s for %s',
                $parameter->getName(),
                $className,
            ));
        }

        return $reflection->newInstanceArgs($arguments);
    }
}

final class ContainerEntryNotFound extends RuntimeException implements NotFoundExceptionInterface
{
}
