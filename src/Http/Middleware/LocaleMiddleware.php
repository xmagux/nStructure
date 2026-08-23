<?php

declare(strict_types=1);

namespace NStructure\Http\Middleware;

use NStructure\Application\Translation\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Translator $translator,
        private array $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestedLocale = $request->getQueryParams()['lang'] ?? null;
        if (is_string($requestedLocale) && in_array($requestedLocale, ['en', 'pl'], true)) {
            $_SESSION['locale'] = $requestedLocale;
        }

        $locale = (string) ($_SESSION['locale'] ?? $this->settings['app']['locale']);
        $this->translator->setLocale($locale);

        return $handler->handle($request);
    }
}
