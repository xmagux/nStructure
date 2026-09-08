<?php

declare(strict_types=1);

namespace NStructure\Http\Middleware;

use NStructure\Application\View\ViewContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final readonly class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private Twig $view,
        private ViewContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $provided = $request->getHeaderLine('X-CSRF-Token') ?: (string) ($body['_token'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return $this->reject($request, $body);
        }

        return $handler->handle($request);
    }

    private function reject(ServerRequestInterface $request, array $body): ResponseInterface
    {
        if (!$this->wantsHtml($request)) {
            $response = new Response(419);
            $response->getBody()->write(json_encode([
                'error' => 'CSRF token mismatch',
            ], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $redirect = trim((string) ($body['redirect'] ?? ''));
        $loginUrl = '/login';
        if (str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            $loginUrl .= '?redirect=' . rawurlencode($redirect);
        }

        $data = $this->context->make('auth.session_expired_title', 'login', [
            'login_url' => $loginUrl,
        ]);

        $response = $this->view->render(new Response(419), 'pages/session-expired.twig', $data);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function wantsHtml(ServerRequestInterface $request): bool
    {
        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            return false;
        }

        $accept = $request->getHeaderLine('Accept');
        if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
            return false;
        }

        return $request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest';
    }
}
