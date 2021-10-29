<?php

declare(strict_types=1);

namespace Crell\Serde;

use Attribute;
use Crell\AttributeUtils\FromReflectionClass;
use Crell\AttributeUtils\HasSubAttributes;
use Crell\AttributeUtils\ParseProperties;

#[Attribute(Attribute::TARGET_CLASS)]
class ClassDef implements FromReflectionClass, ParseProperties, HasSubAttributes
{
    /**
     * The type map, if any, that applies to this class.
     */
    public readonly ?TypeMap $typeMap;

    /** @var Field[] */
    public readonly array $properties;

    public readonly string $phpType;

    public function __construct(
        public readonly bool $includeFieldsByDefault = true,
    ) {}

    public function fromReflection(\ReflectionClass $subject): void
    {
        $this->phpType ??= $subject->getName();
    }

    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    public function includePropertiesByDefault(): bool
    {
        return $this->includeFieldsByDefault;
    }

    public function propertyAttribute(): string
    {
        return Field::class;
    }

    public function subAttributes(): array
    {
        return [StaticTypeMap::class => 'fromTypeMap'];
    }

    public function fromTypeMap(?TypeMap $map): void
    {
        // This may assign to null, which is OK as that will
        // evaluate to false when we need it to.
        $this->typeMap = $map;
    }
}
