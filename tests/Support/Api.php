<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Answers of the fake API, for Http::fake().
 */
final class Api
{
    public const BASE_URL = 'https://api.flashpi.com.ec';

    public const ID = '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b';

    private function __construct() {}

    /**
     * A JSON answer.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): PromiseInterface
    {
        return Http::response($body, $status, ['X-Request-Id' => 'req_test', ...$headers]);
    }

    /**
     * A Problem Details answer like the API's.
     *
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $code, array $headers = []): PromiseInterface
    {
        $body = [
            'type' => "https://flashpi.com.ec/docs/en/errors/{$code}",
            'title' => 'Problem',
            'status' => $status,
            'detail' => "Detail of {$code}",
            'code' => $code,
            'errors' => [],
            'requestId' => 'req_problem',
        ];

        return Http::response(
            (string) json_encode($body),
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8', 'X-Request-Id' => 'req_problem', ...$headers],
        );
    }

    /**
     * A list answer.
     *
     * @param list<array<string, mixed>> $data
     */
    public static function page(array $data, ?string $nextCursor = null): PromiseInterface
    {
        return self::json(['data' => $data, 'pagination' => ['nextCursor' => $nextCursor, 'hasMore' => $nextCursor !== null]]);
    }

    /**
     * A webhook endpoint as the API returns it.
     *
     * @return array{
     *     id: string,
     *     url: string,
     *     description: null,
     *     enabledEvents: list<string>,
     *     payloadMode: string,
     *     status: string,
     *     environment: string,
     *     verification: array{verifiedAt: null, inProgress: bool, attempts: int},
     *     previousSecretExpiresAt: null,
     *     disabledAt: null,
     *     createdAt: string,
     *     updatedAt: string,
     * }
     */
    public static function endpoint(
        string $url = 'https://shop.example.com/flashpi/webhook',
        string $status = 'pending_verification',
        string $payloadMode = 'full',
    ): array {
        return [
            'id' => 'we_' . substr(self::ID, 0, 8),
            'url' => $url,
            'description' => null,
            'enabledEvents' => ['*'],
            'payloadMode' => $payloadMode,
            'status' => $status,
            'environment' => 'test',
            'verification' => ['verifiedAt' => null, 'inProgress' => $status === 'pending_verification', 'attempts' => 1],
            'previousSecretExpiresAt' => null,
            'disabledAt' => null,
            'createdAt' => '2026-11-16T10:00:00Z',
            'updatedAt' => '2026-11-16T10:00:00Z',
        ];
    }

    /**
     * The URL of an API path.
     */
    public static function url(string $path): string
    {
        return self::BASE_URL . $path;
    }
}
