<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\NetworkRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final readonly class PageController
{
    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private NetworkRepository $repository,
    ) {
    }

    public function topology(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.topology', 'topology', ['topology' => $this->repository->topology()]);
        return $this->view->render($response, 'pages/topology.twig', $data);
    }

    public function inventory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.inventory', 'inventory', ['inventory' => $this->repository->inventory()]);
        return $this->view->render($response, 'pages/inventory.twig', $data);
    }

    public function locations(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.locations', 'locations', ['locations' => $this->repository->locations()]);
        return $this->view->render($response, 'pages/locations.twig', $data);
    }

    public function location(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $location = $this->repository->location((int) $arguments['id']);
        if ($location === null) {
            return $this->notFound($response);
        }
        $data = $this->context->make('page.location', 'locations', [
            'location' => $location,
            'locations' => $this->repository->locations(),
        ]);
        return $this->view->render($response, 'pages/location.twig', $data);
    }

    public function rack(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $rack = $this->repository->rack((int) $arguments['id']);
        if ($rack === null) {
            return $this->notFound($response);
        }
        $data = $this->context->make('page.rack', 'locations', [
            'rack' => $rack,
            'connector_types' => $this->repository->connectorTypes(),
        ]);
        return $this->view->render($response, 'pages/rack.twig', $data);
    }

    public function panel(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $panel = $this->repository->panel((int) $arguments['id']);
        if ($panel === null) {
            return $this->notFound($response);
        }
        $data = $this->context->make('page.patch_panel', 'locations', [
            'panel' => $panel,
            'connector_types' => $this->repository->connectorTypes(),
            'active_device_options' => $this->repository->activeDeviceOptions(),
        ]);
        return $this->view->render($response, 'pages/panel.twig', $data);
    }

    public function cables(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.cables', 'cables', [
            'cables' => $this->repository->cables(),
            'cable_endpoint_options' => $this->repository->cableEndpointOptions(),
        ]);
        return $this->view->render($response, 'pages/cables.twig', $data);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->view->render(
            $response->withStatus(404),
            'pages/not-found.twig',
            $this->context->make('page.not_found', ''),
        );
    }
}
