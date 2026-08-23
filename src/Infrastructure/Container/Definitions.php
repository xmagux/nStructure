<?php

declare(strict_types=1);

namespace NStructure\Infrastructure\Container;

use NStructure\Application\Storage\AssetImageStorage;
use NStructure\Application\Translation\Translator;
use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\NetworkRepository;
use NStructure\Domain\Repository\UserRepository;
use NStructure\Http\Middleware\AuthMiddleware;
use NStructure\Http\Middleware\LocaleMiddleware;
use NStructure\Http\Middleware\SessionMiddleware;
use NStructure\Infrastructure\Database\ConnectionFactory;
use NStructure\Infrastructure\Repository\DemoNetworkRepository;
use NStructure\Infrastructure\Repository\MySqlNetworkRepository;
use NStructure\Infrastructure\Repository\MySqlUserRepository;
use PDO;
use Slim\Views\Twig;
use Twig\TwigFunction;

final class Definitions
{
    public static function build(string $rootPath): Container
    {
        $settingsFactory = require $rootPath . '/config/settings.php';
        $settings = $settingsFactory($rootPath);
        $container = new Container();

        $container->set('settings', $settings);
        $container->set(PDO::class, static fn (): PDO => ConnectionFactory::create($settings['database']));
        $container->set(NetworkRepository::class, static fn (Container $container): NetworkRepository => $settings['app']['demo_mode']
            ? new DemoNetworkRepository()
            : new MySqlNetworkRepository($container->get(PDO::class)));
        $container->set(AssetImageStorage::class, static fn (): AssetImageStorage => new AssetImageStorage(
            $rootPath . '/storage/uploads/assets',
        ));
        $container->set(Translator::class, static fn (): Translator => new Translator(
            $rootPath . '/resources/translations',
            $settings['app']['locale'],
        ));
        $container->set(ViewContext::class, static fn (Container $container): ViewContext => new ViewContext(
            $settings,
            $container->get(Translator::class),
        ));
        $container->set(Twig::class, static function (Container $container) use ($settings): Twig {
            $translator = $container->get(Translator::class);
            $twig = Twig::create($settings['view']['path'], [
                'cache' => $settings['view']['cache'],
                'auto_reload' => $settings['app']['debug'],
            ]);
            $twig->getEnvironment()->addFunction(new TwigFunction(
                't',
                static fn (string $key, array $replacements = []): string => $translator->translate($key, $replacements),
            ));
            return $twig;
        });
        $container->set(UserRepository::class, static fn (Container $container): UserRepository => new MySqlUserRepository($container->get(PDO::class)));
        $container->set(SessionMiddleware::class, static fn (): SessionMiddleware => new SessionMiddleware($settings));
        $container->set(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($settings));
        $container->set(LocaleMiddleware::class, static fn (Container $container): LocaleMiddleware => new LocaleMiddleware(
            $container->get(Translator::class),
            $settings,
        ));

        return $container;
    }
}
