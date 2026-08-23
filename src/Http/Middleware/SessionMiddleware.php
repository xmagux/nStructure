<?php

declare(strict_types=1);

namespace NStructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private array $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = ($request->getUri()->getScheme() === 'https') || ($request->getHeaderLine('X-Forwarded-Proto') === 'https');
            session_name($this->settings['app']['session_name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $handler->handle($request);
    }
}
