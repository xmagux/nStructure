<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\NetworkRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final readonly class DashboardController
{
    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private NetworkRepository $repository,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.dashboard', 'dashboard', $this->repository->dashboard());
        return $this->view->render($response, 'pages/dashboard.twig', $data);
    }
}
