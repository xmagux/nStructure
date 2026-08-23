<?php

declare(strict_types=1);

namespace NStructure\Tests\Unit;

use NStructure\Application\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    public function testItTranslatesConfiguredLocales(): void
    {
        $path = dirname(__DIR__, 2) . '/resources/translations';
        $translator = new Translator($path, 'en');

        self::assertSame('Network overview', $translator->translate('page.dashboard'));

        $localizedMessages = require $path . '/pl.php';
        $translator->setLocale('pl');
        self::assertSame($localizedMessages['page.dashboard'], $translator->translate('page.dashboard'));
    }

    public function testItFallsBackToTheTranslationKey(): void
    {
        $translator = new Translator(dirname(__DIR__, 2) . '/resources/translations', 'en');

        self::assertSame('missing.key', $translator->translate('missing.key'));
    }
}
