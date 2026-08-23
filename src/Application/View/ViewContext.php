<?php

declare(strict_types=1);

namespace NStructure\Application\View;

use NStructure\Application\Translation\Translator;

final readonly class ViewContext
{
    public function __construct(
        private array $settings,
        private Translator $translator,
    ) {
    }

    public function make(string $titleKey, string $section, array $data = []): array
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $data + [
            'app_name' => $this->settings['app']['name'],
            'page_title' => $this->translator->translate($titleKey),
            'section' => $section,
            'locale' => $this->translator->locale(),
            'csrf_token' => $_SESSION['csrf_token'],
            'demo_mode' => $this->settings['app']['demo_mode'],
            'current_path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            'current_user' => isset($_SESSION['user_id']) ? [
                'id' => (int) $_SESSION['user_id'],
                'name' => (string) ($_SESSION['user_name'] ?? ''),
                'email' => (string) ($_SESSION['user_email'] ?? ''),
            ] : null,
        ];
    }
}
