<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Domain\Repository\UserRepository;
use NStructure\Domain\Repository\WorkspaceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class UserController
{
    public function __construct(
        private UserRepository $users,
        private WorkspaceRepository $workspace,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $email = trim((string) ($input['email'] ?? ''));
            $name = trim((string) ($input['name'] ?? ''));
            $password = (string) ($input['password'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new InvalidArgumentException('Enter a valid email address');
            }
            if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                throw new InvalidArgumentException('Name must contain 2-160 characters');
            }
            if (mb_strlen($password) < 8 || mb_strlen($password) > 200) {
                throw new InvalidArgumentException('Password must contain at least 8 characters');
            }

            $user = $this->users->create(['email' => $email, 'name' => $name, 'password' => $password]);
            return $this->json($response->withStatus(201), ['data' => $user]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'User could not be created']);
        }
    }

    public function updateProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($userId < 1) {
                throw new InvalidArgumentException('Not authenticated');
            }
            $input = (array) ($request->getParsedBody() ?? []);
            $name = trim((string) ($input['name'] ?? ''));
            $email = trim((string) ($input['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new InvalidArgumentException('Enter a valid email address');
            }
            if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                throw new InvalidArgumentException('Name must contain 2-160 characters');
            }

            $user = $this->users->updateProfile($userId, $name, $email);
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            return $this->json($response, ['data' => $user]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Profile could not be updated']);
        }
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($userId < 1) {
                throw new InvalidArgumentException('Not authenticated');
            }
            $input = (array) ($request->getParsedBody() ?? []);
            $currentPassword = (string) ($input['current_password'] ?? '');
            $newPassword = (string) ($input['new_password'] ?? '');

            if (mb_strlen($newPassword) < 8 || mb_strlen($newPassword) > 200) {
                throw new InvalidArgumentException('New password must contain at least 8 characters');
            }
            if (!$this->users->verifyPassword($userId, $currentPassword)) {
                throw new InvalidArgumentException('Current password is incorrect');
            }

            $this->users->updatePassword($userId, $newPassword);
            return $this->json($response, ['data' => ['updated' => true]]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Password could not be updated']);
        }
    }

    public function updateWorkspace(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            return $this->json($response, ['data' => $this->workspace->update($input)]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Workspace settings could not be saved']);
        }
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            return $this->json($response, ['data' => $this->users->archive((int) $arguments['id'])]);
        } catch (ResourceInUseException $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage(), 'reason' => $exception->reason]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'User could not be removed']);
        }
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
