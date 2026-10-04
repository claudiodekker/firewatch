<?php

namespace ClaudioDekker\Firewatch;

use Closure;
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
     * The ingest interface's method signatures that Firewatch's ingests implement.
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
     * Replace the ingest on Nightwatch's core with one of Firewatch's, which transmit nothing.
     *
     * @param  Closure(): Ingest  $ingest
     *
     * @throws RuntimeException when the interface or the property is not what Firewatch's ingests were built against
     */
    public function replace(object $core, Closure $ingest): void
    {
        // The ingest is loaded only after this check, as a class that no longer
        // fits its interface is a fatal error. Preloading every class through
        // opcache declares it sooner, and then this guard can't protect it.
        if (! interface_exists($this->interface()) || $this->hasUnexpectedSignatures()) {
            throw new RuntimeException('its ingest interface changed');
        }

        if (! $this->isAssignable($core)) {
            throw new RuntimeException('its core has no assignable ingest property');
        }

        $replacement = $ingest();

        (new ReflectionProperty($core, 'ingest'))->setValue($core, $replacement);
    }

    /**
     * Determine if the interface has a method Firewatch's ingests were not written against.
     *
     * A method the interface dropped is harmless, as the ingests still satisfy it.
     */
    protected function hasUnexpectedSignatures(): bool
    {
        $unexpected = array_diff($this->signatures(), $this->expectedSignatures());

        return $unexpected !== [];
    }

    /**
     * Get the signatures Firewatch's ingests were written against, sorted.
     *
     * @return list<string>
     */
    protected function expectedSignatures(): array
    {
        $signatures = static::EXPECTED_SIGNATURES;

        sort($signatures);

        return $signatures;
    }

    /**
     * Get the ingest interface Firewatch's ingests implement.
     *
     * @return class-string
     */
    protected function interface(): string
    {
        return Ingest::class;
    }

    /**
     * Get the signatures of the ingest interface's methods.
     *
     * @return list<string>
     */
    protected function signatures(): array
    {
        $methods = (new ReflectionClass($this->interface()))->getMethods();

        $signatures = array_map($this->signature(...), $methods);

        sort($signatures);

        return $signatures;
    }

    /**
     * Get a method's signature as its modifiers, name, parameters and return type.
     */
    protected function signature(ReflectionMethod $method): string
    {
        $static = $method->isStatic() ? 'static ' : '';
        $reference = $method->returnsReference() ? '&' : '';
        $parameters = array_map($this->parameter(...), $method->getParameters());

        return $static.$reference.$method->getName().'('.implode(', ', $parameters).'): '.$method->getReturnType();
    }

    /**
     * Get a parameter as its type, passing, name and whether it is optional.
     */
    protected function parameter(ReflectionParameter $parameter): string
    {
        $reference = $parameter->isPassedByReference() ? '&' : '';
        $variadic = $parameter->isVariadic() ? '...' : '';
        $optional = $parameter->isOptional() && ! $parameter->isVariadic() ? ' = ?' : '';

        return trim($parameter->getType().' '.$reference.$variadic.'$'.$parameter->getName()).$optional;
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
