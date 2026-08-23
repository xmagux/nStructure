<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final readonly class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 15;

    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private UserRepository $users,
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!empty($_SESSION['user_id'])) {
            return (new Response(302))->withHeader('Location', '/');
        }

        $redirect = (string) ($request->getQueryParams()['redirect'] ?? '');
        return $this->renderLogin($response, $redirect, null);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $redirect = trim((string) ($body['redirect'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $lastAttempt = (float) ($_SESSION['login_last_attempt'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS && (microtime(true) - $lastAttempt) < self::LOCKOUT_SECONDS) {
            return $this->renderLogin($response, $redirect, 'auth.too_many_attempts');
        }

        $user = $email !== '' ? $this->users->findActiveByEmail($email) : null;
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $_SESSION['login_attempts'] = $attempts + 1;
            $_SESSION['login_last_attempt'] = microtime(true);
            return $this->renderLogin($response, $redirect, 'auth.invalid_credentials');
        }

        unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $this->users->touchLastLogin((int) $user['id']);

        $target = str_starts_with($redirect, '/') && !str_starts_with($redirect, '//') ? $redirect : '/';
        return $response->withStatus(302)->withHeader('Location', $target);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $_SESSION = [];
        session_regenerate_id(true);
        return $response->withStatus(302)->withHeader('Location', '/login');
    }

    private function renderLogin(ResponseInterface $response, string $redirect, ?string $errorKey): ResponseInterface
    {
        $data = $this->context->make('auth.login_title', 'login', [
            'redirect' => $redirect,
            'error' => $errorKey,
        ]);
        return $this->view->render($response, 'pages/login.twig', $data);
    }
}
