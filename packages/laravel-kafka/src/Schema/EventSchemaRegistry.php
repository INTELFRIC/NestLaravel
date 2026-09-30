<?php

namespace NestLaravel\Kafka\Schema;

use NestLaravel\Kafka\Contracts\DomainEvent;
use NestLaravel\Kafka\Contracts\HasEventSchema;
use NestLaravel\Kafka\Exceptions\InvalidEventException;

/**
 * Known event schemas, keyed by type + version. Populated from `config('kafka.events')` (list of event classes;
 * `nestlaravel generate event` maintains it) or programmatically via register().
 *
 * Producer side: validates outgoing events (and, with `kafka.schema.enforce_producer`, refuses events that have no
 * schema). Consumer side: validates incoming payloads for every known type/version; types this service has never
 * registered pass through untouched (topics may carry events the service does not care about).
 */
final class EventSchemaRegistry
{
    /** @var array<string, array<int, EventSchema>> */
    private array $schemas = [];

    /** @var array<string, class-string> */
    private array $classes = [];

    public function __construct(private readonly bool $enforceProducer = false, private readonly bool $enforceConsumer = false) {}

    public function register(EventSchema $schema): void
    {
        $this->schemas[$schema->type][$schema->version] = $schema;
    }

    /** @param class-string<HasEventSchema> $class */
    public function registerClass(string $class): void
    {
        if (! is_subclass_of($class, HasEventSchema::class)) {
            throw new \InvalidArgumentException("[{$class}] must implement ".HasEventSchema::class.'.');
        }

        $schema = $class::eventSchema();
        $this->register($schema);
        $this->classes[$schema->type.'@'.$schema->version] = $class;
    }

    public function find(string $type, int $version): ?EventSchema
    {
        return $this->schemas[$type][$version] ?? null;
    }

    /** @return array<string, array<int, EventSchema>> */
    public function all(): array
    {
        return $this->schemas;
    }

    /** @return array<string, class-string> */
    public function classes(): array
    {
        return $this->classes;
    }

    /** Validate an event about to be published. */
    public function assertValidOutgoing(DomainEvent $event): void
    {
        $schema = $this->find($event->eventType(), $event->version());

        if ($schema === null && $event instanceof HasEventSchema) {
            $schema = $event::eventSchema();
        }

        if ($schema === null) {
            if ($this->enforceProducer) {
                throw new InvalidEventException("Event [{$event->eventType()}] v{$event->version()} has no registered schema (kafka.schema.enforce_producer is on).");
            }

            return;
        }

        $schema->validate($event->payload());
    }

    /**
     * Validate a consumed envelope.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function assertValidIncoming(array $envelope): void
    {
        $type = (string) ($envelope['event_type'] ?? '');
        $version = (int) ($envelope['event_version'] ?? $envelope['version'] ?? 1);
        $schema = $this->find($type, $version);

        if ($schema === null) {
            if ($this->enforceConsumer && isset($this->schemas[$type])) {
                throw new InvalidEventException("Event [{$type}] arrived with unsupported version {$version}.");
            }

            return;
        }

        $schema->validate((array) ($envelope['payload'] ?? []));
    }

    /**
     * Would moving from $previous to $next break consumers? (used by tests and `events:check`)
     *
     * @return list<string>
     */
    public function compatibilityProblems(string $type, int $previous, int $next): array
    {
        $old = $this->find($type, $previous);
        $new = $this->find($type, $next);

        if ($old === null || $new === null) {
            return ["Schema for [{$type}] v{$previous} or v{$next} is not registered."];
        }

        return array_map(
            static fn (string $f) => "v{$next} requires [{$f}] which v{$previous} events are not guaranteed to carry.",
            $new->incompatibilitiesWith($old),
        );
    }
}
