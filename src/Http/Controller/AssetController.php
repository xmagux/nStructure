<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Application\Storage\AssetImageStorage;
use NStructure\Domain\Repository\NetworkRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Slim\Psr7\Stream;
use Throwable;

final readonly class AssetController
{
    public function __construct(
        private NetworkRepository $repository,
        private AssetImageStorage $storage,
    ) {
    }

    public function uploadRackImage(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->upload($request, $response, 'RACK', (int) $arguments['id']);
    }

    public function uploadPanelImage(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->upload($request, $response, 'PATCH_PANEL', (int) $arguments['id']);
    }

    public function uploadLocationImage(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->upload($request, $response, 'LOCATION', (int) $arguments['id']);
    }

    public function uploadServerRoomImage(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->upload($request, $response, 'SERVER_ROOM', (int) $arguments['id']);
    }

    public function uploadCableImage(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->upload($request, $response, 'CABLE', (int) $arguments['id']);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $image = $this->repository->assetImage((int) $arguments['id']);
        if ($image === null) {
            return $response->withStatus(404);
        }

        try {
            $path = $this->storage->path((string) $image['storage_path']);
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Image file could not be opened');
            }
            return $response
                ->withBody(new Stream($handle))
                ->withHeader('Content-Type', (string) $image['mime_type'])
                ->withHeader('Content-Length', (string) filesize($path))
                ->withHeader('Cache-Control', 'private, max-age=86400')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (RuntimeException) {
            return $response->withStatus(404);
        }
    }

    private function upload(ServerRequestInterface $request, ResponseInterface $response, string $entityType, int $entityId): ResponseInterface
    {
        $exists = match ($entityType) {
            'RACK' => $this->repository->rack($entityId) !== null,
            'LOCATION' => $this->repository->location($entityId) !== null,
            'SERVER_ROOM' => $this->repository->serverRoomExists($entityId),
            'CABLE' => $this->repository->cableExists($entityId),
            default => $this->repository->panel($entityId) !== null,
        };
        if (!$exists) {
            return $this->json($response->withStatus(404), ['error' => 'Infrastructure asset not found']);
        }

        $upload = $request->getUploadedFiles()['image'] ?? null;
        if (!$upload instanceof UploadedFileInterface) {
            return $this->json($response->withStatus(422), ['error' => 'Select an image to upload']);
        }

        $stored = null;
        try {
            $stored = $this->storage->store($upload);
            $image = $this->repository->addAssetImage($entityType, $entityId, $stored);
            return $this->json($response->withStatus(201), ['data' => $image]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable) {
            if (is_array($stored)) {
                $this->storage->delete((string) $stored['storage_path']);
            }
            return $this->json($response->withStatus(500), ['error' => 'The image could not be saved']);
        }
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
