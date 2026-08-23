<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final readonly class AccountController
{
    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private UserRepository $users,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.account', 'account', [
            'users' => $this->users->all(),
            'audit_log' => $this->users->auditLog(100),
        ]);
        return $this->view->render($response, 'pages/account.twig', $data);
    }
}
