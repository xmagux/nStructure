<?php

declare(strict_types=1);

namespace NStructure\Application\Translation;

final class Translator
{
    private array $messages = [];

    public function __construct(
        private readonly string $translationPath,
        private string $locale,
    ) {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = in_array($locale, ['en', 'pl'], true) ? $locale : 'en';
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function translate(string $key, array $replacements = []): string
    {
        if (!isset($this->messages[$this->locale])) {
            $path = $this->translationPath . '/' . $this->locale . '.php';
            $this->messages[$this->locale] = is_file($path) ? require $path : [];
        }

        $message = (string) ($this->messages[$this->locale][$key] ?? $key);
        foreach ($replacements as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }
}
