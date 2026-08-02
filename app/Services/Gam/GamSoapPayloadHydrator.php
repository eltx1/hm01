<?php

namespace App\Services\Gam;

use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/** Converts Horus' version-neutral arrays into the generated SOAP value objects. */
final class GamSoapPayloadHydrator
{
    public function object(string $type, array $payload, string $namespace): object
    {
        $object = $this->convert($payload, $namespace.'\\'.$type, $namespace);
        if (! is_object($object)) throw new InvalidArgumentException("Unable to hydrate GAM SOAP {$type}.");

        return $object;
    }

    /** @return list<mixed> */
    public function arguments(object $service, string $method, array $payload, string $namespace): array
    {
        if (! method_exists($service, $method)) {
            throw new InvalidArgumentException("The installed GAM SOAP library does not expose {$method}.");
        }

        $reflection = new ReflectionMethod($service, $method);
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (! array_key_exists($name, $payload)) {
                if ($parameter->isOptional()) {
                    continue;
                }
                throw new InvalidArgumentException("GAM SOAP method {$method} requires {$name}.");
            }

            $type = $this->documentedType($reflection->getDocComment() ?: '', $name)
                ?? (($parameter->getType() instanceof ReflectionNamedType) ? $parameter->getType()->getName() : null);
            $arguments[] = $this->convert($payload[$name], $type, $namespace);
        }

        return $arguments;
    }

    private function convert(mixed $value, ?string $expectedType, string $namespace): mixed
    {
        $expectedType = $this->normalizeType($expectedType, $namespace);
        if (is_string($value) && $expectedType !== null && str_ends_with($expectedType, '\\DateTime')) {
            $dateTime = new DateTimeImmutable($value);
            $value = [
                'date' => ['year' => (int) $dateTime->format('Y'), 'month' => (int) $dateTime->format('n'), 'day' => (int) $dateTime->format('j')],
                'hour' => (int) $dateTime->format('G'), 'minute' => (int) $dateTime->format('i'), 'second' => (int) $dateTime->format('s'),
                'timeZoneId' => $dateTime->getOffset() === 0 ? 'UTC' : $dateTime->getTimezone()->getName(),
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        $declaredType = isset($value['__type']) ? $namespace.'\\'.(string) $value['__type'] : null;
        if ($declaredType !== null) unset($value['__type']);

        if ($declaredType !== null) $expectedType = $declaredType;

        if ($expectedType !== null && str_ends_with($expectedType, '[]')) {
            $itemType = substr($expectedType, 0, -2);

            return array_map(fn (mixed $item) => $this->convert($item, $itemType, $namespace), $value);
        }

        if ($expectedType !== null && class_exists($expectedType)) {
            $reflection = new ReflectionClass($expectedType);
            if ($reflection->isAbstract()) {
                throw new InvalidArgumentException("A concrete __type is required for GAM SOAP {$expectedType}.");
            }
            $object = $reflection->newInstance();
            foreach ($value as $property => $propertyValue) {
                $setter = 'set'.ucfirst((string) $property);
                if (! $reflection->hasMethod($setter)) {
                    throw new InvalidArgumentException("GAM SOAP {$expectedType} does not support {$property}.");
                }
                $setterReflection = $reflection->getMethod($setter);
                $parameter = $setterReflection->getParameters()[0] ?? null;
                $propertyType = $parameter
                    ? $this->documentedType($setterReflection->getDocComment() ?: '', $parameter->getName())
                    : null;
                if ($property === 'assetByteArray' && is_string($propertyValue)) {
                    $decoded = base64_decode($propertyValue, true);
                    if ($decoded === false) throw new InvalidArgumentException('GAM creative asset is not valid base64.');
                    $propertyValue = $decoded;
                }
                $object->{$setter}($this->convert($propertyValue, $propertyType, $namespace));
            }

            return $object;
        }

        return array_map(fn (mixed $item) => $this->convert($item, null, $namespace), $value);
    }

    private function documentedType(string $docComment, string $parameter): ?string
    {
        $quoted = preg_quote($parameter, '/');
        if (preg_match('/@param\s+([^\s]+)\s+\$'.$quoted.'\b/', $docComment, $match) !== 1) {
            return null;
        }

        return explode('|', $match[1])[0] ?: null;
    }

    private function normalizeType(?string $type, string $namespace): ?string
    {
        if ($type === null) return null;
        $type = ltrim($type, '\\');
        if (in_array($type, ['array', 'mixed', 'string', 'int', 'float', 'bool', 'boolean'], true)) return null;
        if (str_ends_with($type, '[]')) {
            $item = substr($type, 0, -2);
            if (in_array($item, ['string', 'int', 'float', 'bool', 'boolean', 'mixed'], true)) return null;

            return (str_contains($item, '\\') ? $item : $namespace.'\\'.$item).'[]';
        }

        return str_contains($type, '\\') ? $type : $namespace.'\\'.$type;
    }
}
