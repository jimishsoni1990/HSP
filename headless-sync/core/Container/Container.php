<?php

namespace HSP\Core\Container;

use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;
use Exception;

class Container
{
    /**
     * @var array
     */
    protected array $bindings = [];

    /**
     * @var array
     */
    protected array $instances = [];

    /**
     * Bind a concrete implementation to an abstract type.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $shared
     * @return void
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }

    /**
     * Bind a shared (singleton) concrete implementation to an abstract type.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Bind an existing instance to an abstract type in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return void
     */
    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Check if the container has a binding or instance for the abstract type.
     *
     * @param string $abstract
     * @return bool
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);

        if ($concrete instanceof \Closure) {
            $object = $concrete($this, $parameters);
        } elseif (is_string($concrete) && class_exists($concrete)) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $concrete;
        }

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Get the concrete type for a given abstract type.
     *
     * @param string $abstract
     * @return mixed
     */
    protected function getConcrete(string $abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Check if a given abstract type is shared.
     *
     * @param string $abstract
     * @return bool
     */
    protected function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared'] === true;
    }

    /**
     * Build an instance of the concrete type.
     *
     * @param string $concrete
     * @param array $parameters
     * @return mixed
     * @throws Exception
     */
    protected function build(string $concrete, array $parameters = [])
    {
        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new Exception("Class {$concrete} is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete;
        }

        $dependencies = $constructor->getParameters();
        $instances = $this->resolveDependencies($dependencies, $parameters);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve the dependencies for a class constructor.
     *
     * @param ReflectionParameter[] $dependencies
     * @param array $parameters
     * @return array
     * @throws Exception
     */
    protected function resolveDependencies(array $dependencies, array $parameters): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            $name = $dependency->getName();

            if (array_key_exists($name, $parameters)) {
                $results[] = $parameters[$name];
                continue;
            }

            $type = $dependency->getType();

            if ($type === null) {
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } else {
                    throw new Exception("Unresolvable dependency [{$dependency}] in class {$dependency->getDeclaringClass()->getName()}");
                }
                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $results[] = $this->resolve($type->getName());
            } else {
                if ($dependency->isDefaultValueAvailable()) {
                    $results[] = $dependency->getDefaultValue();
                } else {
                    throw new Exception("Unresolvable dependency [{$dependency}] in class {$dependency->getDeclaringClass()->getName()}");
                }
            }
        }

        return $results;
    }
}
