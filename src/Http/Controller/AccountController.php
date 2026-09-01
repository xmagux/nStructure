<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\UserRepository;
use NStructure\Domain\Repository\WorkspaceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final readonly class AccountController
{
    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private UserRepository $users,
        private WorkspaceRepository $workspace,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.account', 'account', [
            'users' => $this->users->all(),
            'audit_log' => $this->users->auditLog(100),
            'owner_user_id' => $this->users->ownerId(),
            // The raw (possibly-null) override, for the form's own fields —
            // deliberately not the layout's "workspace" fallback-filled
            // values, so an unset field shows empty (with the default as
            // its placeholder) rather than looking already customized.
            'workspace_settings' => $this->workspace->get(),
        ]);
        return $this->view->render($response, 'pages/account.twig', $data);
    }
}
