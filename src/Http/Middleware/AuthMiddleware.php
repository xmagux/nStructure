<?php

declare(strict_types=1);

namespace NStructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PATHS = ['/login', '/api/v1/health'];

    public function __construct(private array $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->settings['app']['demo_mode']) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return $handler->handle($request);
        }

        if (!empty($_SESSION['user_id'])) {
            return $handler->handle($request);
        }

        if (str_starts_with($path, '/api/')) {
            $response = new Response(401);
            $response->getBody()->write(json_encode(['error' => 'Authentication required'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $redirect = $path === '/' ? '' : ('?redirect=' . rawurlencode($path));
        return (new Response(302))->withHeader('Location', '/login' . $redirect);
    }
}
