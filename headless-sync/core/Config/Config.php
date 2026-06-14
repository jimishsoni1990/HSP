<?php

namespace HSP\Core\Config;

class Config
{
    /**
     * @var array
     */
    protected array $items = [];

    /**
     * Config constructor.
     *
     * @param array $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Get the specified configuration value using dot-notation.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $items = $this->items;

        if (isset($items[$key])) {
            return $items[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($items) && array_key_exists($segment, $items)) {
                $items = $items[$segment];
            } else {
                return $default;
            }
        }

        return $items;
    }

    /**
     * Set a given configuration value using dot-notation.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $items = &$this->items;

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($items[$key]) || !is_array($items[$key])) {
                $items[$key] = [];
            }

            $items = &$items[$key];
        }

        $items[array_shift($keys)] = $value;
    }

    /**
     * Get all configuration items.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }
}
