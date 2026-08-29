<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Domain\Repository\AlertRepository;
use NStructure\Infrastructure\Mail\Mailer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final readonly class AlertController
{
    public function __construct(
        private AlertRepository $repository,
        private Mailer $mailer,
    ) {
    }

    public function createRecipient(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            return $this->json($response->withStatus(201), ['data' => $this->repository->createRecipient($input)]);
        } catch (RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Could not add recipient']);
        }
    }

    public function updateRecipient(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            return $this->json($response, ['data' => $this->repository->updateRecipient((int) $arguments['id'], $input)]);
        } catch (RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Could not update recipient']);
        }
    }

    public function archiveRecipient(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->json($response, ['data' => $this->repository->archiveRecipient((int) $arguments['id'])]);
    }

    public function createGroup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            return $this->json($response->withStatus(201), ['data' => $this->repository->createGroup($input)]);
        } catch (RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Could not add group']);
        }
    }

    public function updateGroup(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            return $this->json($response, ['data' => $this->repository->updateGroup((int) $arguments['id'], $input)]);
        } catch (RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Could not update group']);
        }
    }

    public function deleteGroup(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $this->repository->deleteGroup((int) $arguments['id']);
        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function setGroupMembers(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $recipientIds = is_array($input['recipient_ids'] ?? null) ? $input['recipient_ids'] : [];
        $this->repository->setGroupMembers((int) $arguments['id'], $recipientIds);
        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function setSensorAlertTargets(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $recipientIds = is_array($input['recipient_ids'] ?? null) ? $input['recipient_ids'] : [];
        $groupIds = is_array($input['group_ids'] ?? null) ? $input['group_ids'] : [];
        $this->repository->setSensorAlertTargets((int) $arguments['id'], $recipientIds, $groupIds);
        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function saveSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $minutes = filter_var($input['repeat_interval_minutes'] ?? null, FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes === null || $minutes < 1) {
                throw new InvalidArgumentException('Repeat interval must be a positive number of minutes');
            }
            $this->repository->saveSettings($minutes);
            return $this->json($response, ['data' => $this->repository->getSettings()]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        }
    }

    public function sendTestEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response->withStatus(422), ['error' => 'A valid email address is required']);
        }
        if (!$this->mailer->isConfigured()) {
            return $this->json($response->withStatus(409), ['error' => 'SMTP is not configured (SMTP_HOST is empty)']);
        }
        $sent = $this->mailer->send(
            $email,
            null,
            'nStructure — testowa wiadomość',
            "To jest testowa wiadomość z modułu alertów nStructure.\n\nJeśli ją widzisz, konfiguracja SMTP działa poprawnie.",
        );
        return $sent
            ? $this->json($response, ['data' => ['ok' => true]])
            : $this->json($response->withStatus(502), ['error' => 'Sending failed — check SMTP settings and daemon/app logs']);
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
