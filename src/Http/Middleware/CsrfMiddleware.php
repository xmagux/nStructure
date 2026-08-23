<?php

declare(strict_types=1);

namespace NStructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $provided = $request->getHeaderLine('X-CSRF-Token') ?: (string) ($body['_token'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            $response = new Response(419);
            $response->getBody()->write(json_encode([
                'error' => 'CSRF token mismatch',
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
