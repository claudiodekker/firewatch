<?php

namespace ClaudioDekker\Firewatch;

use Laravel\Nightwatch\Contracts\Ingest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

/**
 * @internal
 */
class IngestReplacer
{
    /**
     * The ingest interface's method signatures that NullIngest implements.
     */
    protected const EXPECTED_SIGNATURES = [
        'write(array $record): void',
        'writeNow(array $record): void',
        'ping(): void',
        'shouldDigest(bool $bool = ?): void',
        'shouldDigestWhenBufferIsFull(bool $bool = ?): void',
        'digest(): void',
        'flush(): void',
    ];

    /**
     * Replace the ingest on Nightwatch's core with one that transmits nothing.
     *
     * @throws RuntimeException when the interface or the property is not what NullIngest was built against
     */
    public function replace(object $core): void
    {
        // NullIngest is only loaded after this check: a class that no longer fits its interface is a fatal error.
        if (! interface_exists(Ingest::class) || $this->signatures() !== static::EXPECTED_SIGNATURES) {
            throw new RuntimeException('its ingest interface changed');
        }

        if (! $this->isAssignable($core)) {
            throw new RuntimeException('its core has no assignable ingest property');
        }

        (new ReflectionProperty($core, 'ingest'))->setValue($core, new NullIngest);
    }

    /**
     * Get the signatures of the ingest interface's methods.
     *
     * @return list<string>
     */
    protected function signatures(): array
    {
        $methods = (new ReflectionClass(Ingest::class))->getMethods();

        return array_map($this->signature(...), $methods);
    }

    /**
     * Get a method's signature as its name, parameters and return type.
     */
    protected function signature(ReflectionMethod $method): string
    {
        $parameters = array_map($this->parameter(...), $method->getParameters());

        return $method->getName().'('.implode(', ', $parameters).'): '.$method->getReturnType();
    }

    /**
     * Get a parameter as its type, name and whether it is optional.
     */
    protected function parameter(ReflectionParameter $parameter): string
    {
        $variadic = $parameter->isVariadic() ? '...' : '';
        $optional = $parameter->isOptional() && ! $parameter->isVariadic() ? ' = ?' : '';

        return trim($parameter->getType().' '.$variadic.'$'.$parameter->getName()).$optional;
    }

    /**
     * Determine if the core holds a public, writable ingest property typed as the ingest interface.
     */
    protected function isAssignable(object $core): bool
    {
        if (! property_exists($core, 'ingest')) {
            return false;
        }

        $property = new ReflectionProperty($core, 'ingest');
        $type = $property->getType();

        return $property->isPublic()
            && ! $property->isStatic()
            && ! $property->isReadOnly()
            && $type instanceof ReflectionNamedType
            && $type->getName() === Ingest::class;
    }
}
