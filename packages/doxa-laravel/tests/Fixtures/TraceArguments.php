<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Closure;
use ReflectionFunction;
use SensitiveParameterValue;
use SplObjectStorage;

final class TraceArguments
{
    /** @param list<array<string, mixed>> $trace @return list<string> */
    public static function protectedParameters(array $trace): array
    {
        $parameters = [];
        foreach ($trace as $frame) {
            foreach ($frame['args'] ?? [] as $index => $argument) {
                if ($argument instanceof SensitiveParameterValue) {
                    $parameters[] = ($frame['class'] ?? '').'::'.$frame['function'].'#'.$index;
                }
            }
        }

        return $parameters;
    }

    public static function inspect(mixed $arguments, bool $revealRedacted = false): string
    {
        $seen = new SplObjectStorage;
        $inspect = static function (mixed $value) use (&$inspect, $seen, $revealRedacted): mixed {
            if ($value instanceof SensitiveParameterValue) {
                return $revealRedacted ? $inspect($value->getValue()) : '[redacted parameter]';
            }
            if (is_object($value)) {
                if ($seen->contains($value)) {
                    return '[already inspected object]';
                }
                $seen->attach($value);
                // Casting exposes private/protected properties without consulting __debugInfo().
                $properties = (array) $value;
                if ($value instanceof Closure) {
                    $reflection = new ReflectionFunction($value);
                    $properties['captured'] = $reflection->getStaticVariables();
                    $properties['bound_object'] = $reflection->getClosureThis();
                }

                return $inspect($properties);
            }
            if (is_array($value)) {
                return array_map($inspect, $value);
            }

            return $value;
        };

        return json_encode($inspect($arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
