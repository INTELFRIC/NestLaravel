<?php

namespace App\Core\DTOs;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

abstract readonly class DataTransferObject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $reflection = new ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            $args[] = self::resolveParameter($parameter, $data);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Build from a Form Request / Request that exposes validated().
     *
     * @param  Request&object{validated(): array<string, mixed>}  $request
     */
    public static function fromRequest(Request $request): static
    {
        if (! method_exists($request, 'validated')) {
            throw ValidationException::withMessages([
                'request' => ['Request must provide validated() data to build a DTO.'],
            ]);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        return static::fromArray($validated);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        foreach (get_object_vars($this) as $key => $value) {
            $payload[$key] = $value instanceof self
                ? $value->toArray()
                : $value;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveParameter(ReflectionParameter $parameter, array $data): mixed
    {
        $name = $parameter->getName();
        $exists = array_key_exists($name, $data);

        if (! $exists) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($parameter->allowsNull()) {
                return null;
            }

            throw ValidationException::withMessages([
                $name => ["Missing required attribute [{$name}]."],
            ]);
        }

        $value = $data[$name];
        $type = $parameter->getType();

        if ($value === null) {
            if ($parameter->allowsNull()) {
                return null;
            }

            throw ValidationException::withMessages([
                $name => ["Attribute [{$name}] cannot be null."],
            ]);
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $class = $type->getName();

            if (is_subclass_of($class, self::class) && is_array($value)) {
                return $class::fromArray($value);
            }
        }

        return $value;
    }
}
