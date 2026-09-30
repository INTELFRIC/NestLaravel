<?php

namespace NestLaravel\Kafka\Schema;

use Illuminate\Support\Facades\Validator;
use NestLaravel\Kafka\Exceptions\InvalidEventException;

/**
 * Payload schema of one event type + version, expressed with Laravel validation rules:
 *
 *   new EventSchema('orders.order.created', 1, [
 *       'order_id' => 'required|string',
 *       'total'    => 'required|numeric|min:0',
 *       'coupon'   => 'nullable|string',
 *   ]);
 *
 * Unknown payload fields are allowed by default (forward compatibility); `strict: true` rejects them.
 */
final class EventSchema
{
    /** @param array<string, string|list<mixed>> $rules */
    public function __construct(
        public readonly string $type,
        public readonly int $version,
        public readonly array $rules,
        public readonly bool $strict = false,
    ) {}

    /** @param array<string, mixed> $payload */
    public function validate(array $payload): void
    {
        $errors = [];

        if ($this->strict) {
            foreach (array_diff(array_keys($payload), $this->fields()) as $unknown) {
                $errors[$unknown][] = 'Unknown field for a strict schema.';
            }
        }

        $validator = Validator::make($payload, $this->rules);

        if ($validator->fails()) {
            $errors = array_merge_recursive($errors, $validator->errors()->toArray());
        }

        if ($errors !== []) {
            throw new InvalidEventException(
                "Event [{$this->type}] v{$this->version} payload is invalid: ".implode('; ', array_map(
                    static fn ($field, $messages) => $field.': '.implode(' ', (array) $messages),
                    array_keys($errors),
                    $errors,
                )),
                $errors,
            );
        }
    }

    /** @return list<string> top-level payload fields (dotted/wildcard rules collapse to their first segment) */
    public function fields(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $k) => explode('.', $k)[0],
            array_keys($this->rules),
        )));
    }

    /** @return list<string> */
    public function requiredFields(): array
    {
        $required = [];
        foreach ($this->rules as $field => $rule) {
            $parts = is_array($rule) ? array_map('strval', $rule) : explode('|', (string) $rule);
            if (in_array('required', $parts, true) && ! str_contains($field, '.')) {
                $required[] = $field;
            }
        }

        return $required;
    }

    /**
     * Backward compatibility: can a consumer written for $this (the NEW schema) still read events produced under
     * $previous? It can unless the new schema requires a field that old producers were not *guaranteed* to send
     * (i.e. it was absent or merely optional in $previous).
     *
     * @return list<string> the offending fields (empty = compatible)
     */
    public function incompatibilitiesWith(self $previous): array
    {
        return array_values(array_diff($this->requiredFields(), $previous->requiredFields()));
    }
}
