<?php

namespace HSP\Modules\Commerce\CanonicalModels;

class ProductAttribute
{
    protected string $key;
    protected string $label;
    protected string $type;
    protected array $values;
    protected bool $isVisible;
    protected bool $isForVariations;
    protected int $position;

    public function __construct(array $properties = [])
    {
        $this->key             = (string) ($properties['key'] ?? '');
        $this->label           = (string) ($properties['label'] ?? '');
        $this->type            = (string) ($properties['type'] ?? 'custom');
        $this->values          = (array) ($properties['values'] ?? []);
        $this->isVisible       = (bool) ($properties['isVisible'] ?? true);
        $this->isForVariations = (bool) ($properties['isForVariations'] ?? false);
        $this->position        = (int) ($properties['position'] ?? 0);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function isForVariations(): bool
    {
        return $this->isForVariations;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function toArray(): array
    {
        return [
            'key'               => $this->key,
            'label'             => $this->label,
            'type'              => $this->type,
            'values'            => $this->values,
            'isVisible'         => $this->isVisible,
            'isForVariations'   => $this->isForVariations,
            'position'          => $this->position,
        ];
    }
}
