<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Support;

/**
 * Webhooks as Flashpi sends them: Standard Webhooks headers whose v1 signature is the
 * HMAC-SHA256 of `webhook-id.webhook-timestamp.body` with the key of the endpoint's whsec_
 * secret (base64 after the prefix), as flashpi-php's tests sign them.
 */
final class Webhook
{
    public const ID = '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b';

    private function __construct() {}

    /**
     * The JSON body of an event, as the API encodes it.
     *
     * @param array<string, mixed> $event
     */
    public static function body(array $event): string
    {
        return json_encode($event, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * The headers of a delivery signed with a secret, at a time (default: now).
     *
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public static function headers(string $body, string $secret, string $id = self::ID, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::signature($body, $secret, $id, $timestamp),
        ];
    }

    /**
     * The v1 signature of a delivery.
     */
    public static function signature(string $body, string $secret, string $id, int|string $timestamp): string
    {
        $key = base64_decode(substr($secret, \strlen('whsec_')), true);
        if ($key === false) {
            throw new \LogicException('Not a whsec_ secret');
        }

        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * An event with a full payload: `data` is the resource it is about.
     *
     * @param array<string, mixed> $data
     *
     * @return array{id: string, type: string, environment: string, createdAt: string, data: array<string, mixed>}
     */
    public static function event(string $type, array $data = [], string $environment = 'test', string $id = self::ID): array
    {
        return [
            ...self::thin($type, $environment, $id),
            'data' => $data === [] ? self::document() : $data,
        ];
    }

    /**
     * An event with a thin payload: id, type, environment and date only.
     *
     * @return array{id: string, type: string, environment: string, createdAt: string}
     */
    public static function thin(string $type, string $environment = 'test', string $id = self::ID): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'environment' => $environment,
            'createdAt' => '2026-11-16T15:20:00.000Z',
        ];
    }

    /**
     * The endpoint's verification challenge.
     *
     * @return array{id: string, type: string, environment: string, createdAt: string, data: array{webhookEndpointId: string, challenge: string}}
     */
    public static function verification(string $challenge): array
    {
        return [
            'id' => '019a1b2c-ffff-7f60-8a1b-2c3d4e5f6a7b',
            'type' => 'webhook_endpoint.verification',
            'environment' => 'test',
            'createdAt' => '2026-11-16T15:20:00.000Z',
            'data' => ['webhookEndpointId' => 'we_019a1b2c', 'challenge' => $challenge],
        ];
    }

    /**
     * Part of a document, as an event's `data` holds it.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            'id' => '019a1b2c-0000-7f60-8a1b-2c3d4e5f6a7b',
            'type' => 'invoice',
            'environment' => 'test',
            'status' => 'authorized',
            'accessKey' => '1611202601179000000000120010010000000011234567813',
            'counterparty' => ['name' => 'Panadería Ñandú'],
        ];
    }
}
