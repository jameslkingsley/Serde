<?php

declare(strict_types=1);

namespace Crell\Serde\Formatter;

use Crell\Serde\Attributes\ClassSettings;
use Crell\Serde\Attributes\DictionaryField;
use Crell\Serde\Attributes\Field;
use Crell\Serde\Attributes\SequenceField;
use Crell\Serde\Attributes\XmlFormat;
use Crell\Serde\Deserializer;
use Crell\Serde\GenericXmlParser;
use Crell\Serde\SerdeError;
use Crell\Serde\TypeCategory;
use Crell\Serde\XmlElement;
use function Crell\fp\firstValue;
use function Crell\fp\reduceWithKeys;

class XmlParserDeformatter implements Deformatter, SupportsCollecting
{
    public function __construct(
        private GenericXmlParser $parser = new GenericXmlParser(),
    ) {}

    public function format(): string
    {
        return 'xml';
    }

    public function rootField(Deserializer $deserializer, string $targetType): Field
    {
        $shortName = substr(strrchr($targetType, '\\'), 1);
        return Field::create(serializedName: $shortName, phpType: $targetType);
    }

    /**
     * @param string $serialized
     * @param Field $rootField
     */
    public function deserializeInitialize(mixed $serialized, Field $rootField): XmlElement
    {
        return $this->parser->parseXml($serialized) ?? new XmlElement(name: $rootField->serializedName);
    }

    /**
     * @param XmlElement $decoded
     */
    public function deserializeInt(mixed $decoded, Field $field): int|SerdeError
    {
        $value = $this->getValueFromElement($decoded, $field);

        // @todo Still not sure what to do with this.
        if (!is_numeric($value)) {
            return SerdeError::FormatError;
        }

        return (int)$value;
    }

    /**
     * @param XmlElement $decoded
     */
    public function deserializeFloat(mixed $decoded, Field $field): float|SerdeError
    {
        $value = $this->getValueFromElement($decoded, $field);

        // @todo Still not sure what to do with this.
        if (!is_numeric($value)) {
            return SerdeError::FormatError;
        }

        return (float)$value;
    }

    /**
     * @param XmlElement $decoded
     */
    public function deserializeBool(mixed $decoded, Field $field): bool|SerdeError
    {
        $value = $this->getValueFromElement($decoded, $field);

        // @todo Still not sure what to do with this.
        if (!is_numeric($value)) {
            return SerdeError::FormatError;
        }

        return (bool)$value;
    }

    /**
     * @param XmlElement|XmlElement[] $decoded
     */
    public function deserializeString(mixed $decoded, Field $field): string|SerdeError
    {
        // If the field has been imploded, $decoded may be an array of one element
        // instead of just one element. That's because when the data is passed forward
        // from deserializeObject(), it doesn't know that it's going to be exploded later.
        // That distinction needs to get handled here, unfortunately.
        if (is_array($decoded)) {
            $decoded = $decoded[0];
        }

        return $this->getValueFromElement($decoded, $field);
    }

    /**
     * @param XmlElement[] $decoded
     */
    public function deserializeSequence(mixed $decoded, Field $field, Deserializer $deserializer): array|SerdeError
    {
        if (empty($decoded)) {
            return SerdeError::Missing;
        }

        $class = $field?->typeField?->arrayType ?? null;

        $upcast = function(array $ret, XmlElement $v, int|string $k) use ($deserializer, $class) {
            $map = $class ? $deserializer->typeMapper->typeMapForClass($class) : null;
            // @todo This will need to get more robust once we support attribute-based values.
            $arrayType = $map?->findClass($v[$map?->keyField()]) ?? $class ?? get_debug_type($v->content);
            $f = Field::create(serializedName: $v->name, phpType: $arrayType);
            $ret[$k] = $deserializer->deserialize($v, $f);
            return $ret;
        };

        return reduceWithKeys([], $upcast)($decoded);
    }

    /**
     * @param XmlElement|XmlElement[] $decoded
     */
    public function deserializeDictionary(mixed $decoded, Field $field, Deserializer $deserializer): array|SerdeError
    {
        if (empty($decoded)) {
            return SerdeError::Missing;
        }

        $class = $field?->typeField?->arrayType ?? null;

        // When dealing with native serialized objects, $decoded will be an XmlElement, not an
        // array of them.
        if (is_array($decoded)) {
            $decoded = $decoded[0];
        }

        $data = $this->groupedChildren($decoded);

        $map = $class ? $deserializer->typeMapper->typeMapForClass($class) : null;
        // @todo This will need to get more robust once we support attribute-based values.
        // @todo Skipping the type map for the moment.
//        $arrayType = $map?->findClass($v[$map?->keyField()]) ?? $type ?? get_debug_type($v->content);

        // This monstrosity is begging to be refactored, but for now at least it works.
        $ret = [];
        foreach ($data as $name => $elements) {
            if (count($elements) > 1) {
                // Must be a nested sequence.
                $f = Field::create(serializedName: $name, phpType: 'array');
                $value = $deserializer->deserialize($elements, $f);
            } else {
                $e = $elements[0];
                if (count($e->children)) {
                    if ($class) {
                        // This is a dictionary of objects.
                        $f = Field::create(serializedName: $e->name, phpType: $class);
                        $value = $deserializer->deserialize($e, $f);
                    } else {
                        // This is probably a nested dictionary?
                        $f = Field::create(serializedName: $e->name, phpType: 'array')
                            ->with(typeField: new DictionaryField());
                        $value = $deserializer->deserialize([$e], $f);
                    }
                } else {
                    // A nested primitive, probably.
                    $elementType = $class ?? $this->elementType($e);
                    $f = Field::create(serializedName: $e->name, phpType: $elementType);
                    $value = $deserializer->deserialize($e, $f);
                }
            }
            $ret[$name] = $value;
        }
        return $ret;

        /*
        $arrayType = $class ?? get_debug_type($data[0]->content);
        return pipe($data,
            keyedMap(
                values: static fn ($k, XmlElement $e) => $deserializer->deserialize($e, Field::create(serializedName: "$e->name", phpType: $arrayType)),
                keys: static fn ($k, XmlElement $e) => $e->name,
            )
        );
        */
    }

    /**
     * @param XmlElement $decoded
     */
    public function deserializeObject(mixed $decoded, Field $field, Deserializer $deserializer): array|SerdeError
    {
        if (! isset($decoded->name) || !in_array($decoded->name, [$field->serializedName, ...$field->alias], true)) {
            return SerdeError::Missing;
        }

        $data = $this->groupedChildren($decoded);
        // @todo This is going to break on typemapped fields, but deal with that later.

        $properties = $this->propertyList($deserializer, $field, $data);

        $usedNames = [];
        $collectingArray = null;
        /** @var Field[] $collectingObjects */
        $collectingObjects = [];

        $ret = [];

        // First pull out the properties we know about.
        /** @var Field $propField */
        foreach ($properties as $propField) {
            $usedNames[] = $propField->serializedName;
            if ($propField->flatten && $propField->typeCategory === TypeCategory::Array) {
                $collectingArray = $propField;
            } elseif ($propField->flatten && $propField->typeCategory === TypeCategory::Object) {
                $collectingObjects[] = $propField;
            } elseif ($propField->typeCategory === TypeCategory::Array) {
                $valueElements = $this->getFieldData($propField, $data);
                if (!$valueElements) {
                    $ret[$propField->serializedName] = SerdeError::Missing;
                } elseif ($propField?->typeField instanceof SequenceField || $this->isSequence($valueElements)) {
                    $ret[$propField->serializedName] = $deserializer->deserialize($valueElements, $propField);
                } else {
                    if (!$propField->typeField) {
                        $propField = $propField->with(typeField: new DictionaryField());
                    }
                    $ret[$propField->serializedName] = $deserializer->deserialize($valueElements, $propField);
                }
            } elseif ($propField->typeCategory === TypeCategory::Object || $propField->typeCategory->isEnum()) {
                $valueElements = $this->getFieldData($propField, $data);
                $ret[$propField->serializedName] = $valueElements
                    ? $deserializer->deserialize($valueElements[0], $propField)
                    : SerdeError::Missing;
            } else {
                // @todo This needs to be enhanced to deal with attribute-based values, I think?
                // per-type deserialize methods also deal with that, but since the same element
                // may need to get passed multiple times to account for multiple attributes
                // on one element, I think it's necessary here, too.
                $valueElements = $this->getFieldData($propField, $data);
                $ret[$propField->serializedName] = $valueElements
                    ? $deserializer->deserialize($valueElements[0], $propField)
                    : SerdeError::Missing;
            }
        }

        $remaining = $this->getRemainingData($data, $usedNames);

/*
        // First upcast any values that will become properties of a collecting object.
        $remaining = $this->getRemainingData($data, $usedNames);
        foreach ($collectingObjects as $collectingField) {
            $remaining = $this->getRemainingData($remaining, $usedNames);
            $nestedProps = $this->propertyList($deserializer, $collectingField, $remaining);
            foreach ($nestedProps as $propField) {
                $ret[$propField->serializedName] = ($propField->typeCategory->isEnum() || $propField->typeCategory->isCompound())
                    ? $deserializer->deserialize($data, $propField)
                    : $remaining[$propField->serializedName] ?? SerdeError::Missing;
                $usedNames[] = $propField->serializedName;
            }
        }
*/

        // Then IF the remaining data is going to be collected to an array,
        // and that array has a type map, upcast all elements of that array to
        // the appropriate type.
        $remaining = $this->getRemainingData($remaining, $usedNames);
        if ($collectingArray && $map = $deserializer->typeMapper->typeMapForField($collectingArray)) {
            foreach ($remaining as $k => $v) {
                $class = $map->findClass($v[$map->keyField()]);
                $ret[$k] = $deserializer->deserialize($remaining, Field::create(serializedName: "$k", phpType: $class));
            }
        } else {
            // Otherwise, just tack on whatever is left to the processed data.
            foreach ($remaining as $k => $v) {
                if (count($v) === 1) {
                    $elementType = $this->elementType($v[0]);
                    $ret[$k] = $deserializer->deserialize($v[0], Field::create(serializedName: "$k", phpType: $elementType));
                }
            }
        }

        return $ret;
    }

    /**
     * Derives the native type equivalent of an element.
     *
     * @param XmlElement $element
     *   The element to derive.
     * @return string
     */
    protected function elementType(XmlElement $element): string
    {
        return match (true) {
            ctype_digit($element->content) => 'int',
            is_numeric($element->content) => 'float',
            is_string($element->content) => 'string',
            default => get_debug_type($element->content),
        };
    }

    protected function propertyList(Deserializer $deserializer, Field $field, array $data): array
    {
        $class = $field->phpType;

        // @todo This will need to change for attribute-based values.
        // Assuming we allow the map key to be an attribute?
        if ($map = $deserializer->typeMapper->typeMapForField($field)) {
            $key = $map->keyField();
            $class = $map->findClass($data[$key][0]->content);
        }

        return $deserializer->analyzer->analyze($class, ClassSettings::class)->properties;
    }

    /**
     * @todo This method is a hack and is probably still buggy.
     *
     * @param XmlElement[] $valueElements
     */
    protected function isSequence(array $valueElements): bool
    {
        if (count($valueElements) > 1) {
            return true;
        }
        $element = $valueElements[0];
        if (count($element->children)) {
            return false;
        }
        if ($element->content) {
            return true;
        }
        return false;
    }

    /**
     * @param Field $field
     * @param array $data
     * @return XmlElement[]
     */
    public function getFieldData(Field $field, array $data): mixed
    {
        return firstValue(fn(string $name): mixed => $data[$name] ?? null)([$field->serializedName, ...$field->alias]);
    }

    protected function groupedChildren(XmlElement $element): array
    {
        $fn = static function (array $collection, XmlElement $child) {
            $name = $child->name;
            $collection[$name] ??= [];
            $collection[$name][] = $child;
            return $collection;
        };

        return array_reduce($element->children, $fn, []);
    }

    protected function getValueFromElement(XmlElement $element, Field $field): mixed
    {
        $atName = ($field->formats[XmlFormat::class] ?? null)?->attributeName;

        return $atName
            ? ($element->attributes[$atName] ?? SerdeError::Missing)
            : $element->content;
    }

    public function deserializeFinalize(mixed $decoded): void
    {
    }

    public function getRemainingData(mixed $source, array $used): mixed
    {
        return array_diff_key($source, array_flip($used));
    }

}
