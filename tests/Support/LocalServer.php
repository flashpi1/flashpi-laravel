<?php

declare(strict_types=1);

namespace Flashpi\Laravel\Tests\Support;

/**
 * A `php -S` server on a free port whose router is server.php, for the tests that need real
 * sockets (flashpi-php: tests/Support/LocalServer.php). It answers one request at a time.
 */
final class LocalServer
{
    /** @var resource|null */
    private static $process = null;

    private static ?string $url = null;

    private function __construct() {}

    /**
     * The server's base URL, started on first use.
     */
    public static function url(): string
    {
        if (self::$url !== null) {
            return self::$url;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        \assert($probe !== false);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        \assert(\is_resource($process));
        self::$process = $process;
        self::$url = "http://127.0.0.1:{$port}";
        for ($tries = 0; $tries < 100; $tries++) {
            $connection = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return self::$url;
            }
            usleep(50_000);
        }

        throw new \RuntimeException("The local server did not start on port {$port}");
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process, 9);
            proc_close(self::$process);
        }
        self::$process = null;
        self::$url = null;
    }

    /**
     * The RIDE the server sends: bytes of every value, `$size` of them.
     */
    public static function pdf(int $size): string
    {
        return substr('%PDF-1.7' . str_repeat(implode('', array_map('chr', range(0, 255))), intdiv($size, 256) + 1), 0, $size);
    }
}
