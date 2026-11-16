<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Events;

use Flashpi\Exception\FlashpiException;
use Flashpi\Laravel\Internal\ClientBuilder;
use Illuminate\Container\Container;

/**
 * A webhook event from Flashpi, verified by the package's route and dispatched as a Laravel
 * event: one class per type (InvoiceAuthorized for `invoice.authorized`, ...) and
 * WebhookReceived for every delivery.
 *
 * ```php
 * use Flashpi\Laravel\Events\InvoiceAuthorized;
 * use Illuminate\Support\Facades\Event;
 *
 * Event::listen(function (InvoiceAuthorized $event): void {
 *     $document = $event->fetch()['data']; // the authorized invoice
 * });
 * ```
 *
 * A full payload carries the event's `data`: the document, certificate or email sending it is
 * about, as the API described it when the event happened. A thin payload (an endpoint whose
 * payloadMode is `thin`) carries only the id, type, environment and date: `data` is null and
 * fetch() reads the whole event from the API, only when a listener calls it. fetch() works for
 * both, so a listener need not know the endpoint's mode.
 *
 * The event holds strings and arrays only, so a queued listener (ShouldQueue) gets it intact:
 * serialized, it keeps the payload once (with its `data`), the webhook-id and what fetch() read,
 * and the constructor rebuilds the rest. It is built from the webhook's body:
 * `new InvoiceAuthorized($payload)` in a listener's test.
 *
 * @template-covariant TData of array<mixed>
 *
 * @phpstan-type Environment 'test'|'live'
 */
abstract class WebhookEvent
{
    /**
     * The webhook type of the class, or null for WebhookReceived, which takes any type.
     *
     * @var string|null
     */
    public const TYPE = null;

    /** The event's id: the same in every delivery of it, and what events->get() takes. */
    public readonly string $id;

    /** The webhook type, such as `invoice.authorized`. */
    public readonly string $type;

    /**
     * `test` for the SRI's test environment (pruebas), `live` for production.
     *
     * @var Environment
     */
    public readonly string $environment;

    /** When the event happened (ISO 8601). */
    public readonly string $createdAt;

    /**
     * What the event is about, as the API described it when the event happened; null in a thin
     * payload, where fetch() reads it.
     *
     * @var TData|null
     */
    public readonly ?array $data;

    /**
     * The webhook's body as Flashpi sent it, with any field this version does not know.
     *
     * @var array<string, mixed>
     */
    public readonly array $payload;

    /** The delivery's webhook-id header (Flashpi sends the event's id, on every retry). */
    public readonly string $webhookId;

    /**
     * The event fetch() read from the API.
     *
     * @var array<string, mixed>|null
     */
    private ?array $fetched = null;

    /**
     * @param array<string, mixed> $payload A verified webhook body, as Flashpi::unwrapWebhook()
     *                                      returns it.
     * @param string|null $webhookId The delivery's webhook-id header (default: the event's id).
     *
     * @throws \InvalidArgumentException A body that is not a Flashpi event, or an event of
     *                                   another type than the class's.
     */
    final public function __construct(array $payload, ?string $webhookId = null)
    {
        $this->id = self::text($payload, 'id');
        $this->type = self::text($payload, 'type');
        $expected = static::TYPE;
        if ($expected !== null && $this->type !== $expected) {
            throw new \InvalidArgumentException(static::class . " is the {$expected} event, not {$this->type}");
        }
        $environment = $payload['environment'] ?? null;
        if ($environment !== 'test' && $environment !== 'live') {
            throw new \InvalidArgumentException('A Flashpi event has the environment test or live');
        }
        $this->environment = $environment;
        $this->createdAt = self::text($payload, 'createdAt');
        if (\array_key_exists('data', $payload) && !\is_array($payload['data'])) {
            throw new \InvalidArgumentException('The data of a Flashpi event is an object');
        }
        /** @var TData|null $data Checked against the type only: Flashpi signed it. */
        $data = $payload['data'] ?? null;
        $this->data = $data;
        $this->payload = $payload;
        $this->webhookId = $webhookId ?? $this->id;
    }

    /**
     * Whether the payload is thin: no `data`, which fetch() reads from the API.
     */
    public function isThin(): bool
    {
        return $this->data === null;
    }

    /**
     * What a queue keeps of the event: the payload once (`data` is part of it), the webhook-id
     * and what fetch() read.
     *
     * @return array{payload: array<string, mixed>, webhookId: string, fetched: array<string, mixed>|null}
     */
    public function __serialize(): array
    {
        return ['payload' => $this->payload, 'webhookId' => $this->webhookId, 'fetched' => $this->fetched];
    }

    /**
     * Rebuilds the event as the constructor builds it.
     *
     * @param array{payload: array<string, mixed>, webhookId: string, fetched: array<string, mixed>|null} $data
     *
     * @throws \InvalidArgumentException A payload that is not an event of the class's type.
     */
    public function __unserialize(array $data): void
    {
        $this->__construct($data['payload'], $data['webhookId']);
        $this->fetched = $data['fetched'];
    }

    /**
     * The whole event, with its `data`: the payload itself when it carries it, without any
     * request; otherwise the event as the API returns it (`$flashpi->events->get($id)`), read
     * once per event object with the client of the app's container (FLASHPI_API_KEY, of the
     * event's environment).
     *
     * @return array{id: string, type: string, environment: Environment, createdAt: string, data: TData}
     *
     * @throws FlashpiException The API's error, a key of the other environment, or an answer
     *                          that is not this event.
     */
    public function fetch(): array
    {
        if ($this->data !== null) {
            /** @var array{id: string, type: string, environment: Environment, createdAt: string, data: TData} $event The constructor checked it. */
            $event = $this->payload;

            return $event;
        }
        if ($this->fetched === null) {
            $this->fetched = $this->read();
        }
        /** @var array{id: string, type: string, environment: Environment, createdAt: string, data: TData} $event read() checked it. */
        $event = $this->fetched;

        return $event;
    }

    /**
     * The event from the API.
     *
     * @return array<string, mixed>
     *
     * @throws FlashpiException
     */
    private function read(): array
    {
        $flashpi = ClientBuilder::resolve(Container::getInstance());
        if ($flashpi->environment !== $this->environment) {
            throw new FlashpiException(
                "The event {$this->id} is from the {$this->environment} environment, but FLASHPI_API_KEY is a {$flashpi->environment} key: "
                . "fetch it with a key of the {$this->environment} environment",
            );
        }
        $event = $flashpi->events->get($this->id);
        if (!$this->isThis($event)) {
            throw new FlashpiException("The API did not return the event {$this->id} with its data");
        }

        return $event;
    }

    /**
     * Whether an event from the API is this one, with its data. Checked at run time: the API may
     * add event types.
     *
     * @param array<string, mixed> $event
     */
    private function isThis(array $event): bool
    {
        return ($event['id'] ?? null) === $this->id
            && ($event['type'] ?? null) === $this->type
            && ($event['environment'] ?? null) === $this->environment
            && \is_string($event['createdAt'] ?? null)
            && \is_array($event['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException
     */
    private static function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException("The {$key} of a Flashpi event is a string");
        }

        return $value;
    }
}
