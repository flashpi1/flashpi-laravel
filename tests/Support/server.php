<?php

declare(strict_types=1);

// The router of the `php -S` server of the socket tests (LocalServer).

use Flashpi\Laravel\Tests\Support\LocalServer;

require_once __DIR__ . '/LocalServer.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(\is_string($uri) ? $uri : '/', \PHP_URL_PATH);

switch (true) {
    case $path === '/v1/status':
        header('Content-Type: application/json');
        echo '{"status":"operational"}';
        break;
    case $path === '/slow/v1/status':
        usleep(2_000_000);
        header('Content-Type: application/json');
        echo '{"status":"operational"}';
        break;
    case $path === '/redirect/v1/status':
        header('Location: https://example.com/v1/status', true, 302);
        break;
    case preg_match('#^/v1/documents/[^/]+/pdf$#', $path) === 1:
        $pdf = LocalServer::pdf(3_000_000);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . \strlen($pdf));
        foreach (str_split($pdf, 65536) as $piece) {
            echo $piece;
            flush();
        }
        break;
    default:
        http_response_code(404);
        header('Content-Type: application/problem+json');
        echo json_encode(['code' => 'not_found', 'title' => 'Not found', 'detail' => "No route {$path}"]);
}
