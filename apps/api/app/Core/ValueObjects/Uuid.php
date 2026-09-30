<?php

namespace App\Core\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class Uuid implements JsonSerializable, Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException("Invalid UUID [{$value}].");
        }

        $this->value = $normalized;
    }

    public static function generate(): self
    {
        return new self((string) Str::uuid());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new self($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
