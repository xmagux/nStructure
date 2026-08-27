<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\SensorRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Views\Twig;
use Throwable;

final readonly class SensorController
{
    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private SensorRepository $repository,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context->make('page.sensors', 'sensors', ['sensors' => $this->repository->all()]);
        return $this->view->render($response, 'pages/sensors.twig', $data);
    }

    public function pollAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['data' => $this->repository->pollAll()]);
    }

    public function poll(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            return $this->json($response, ['data' => $this->repository->poll((int) $arguments['id'])]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(404), ['error' => $exception->getMessage()]);
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validate($input);
            $sensor = $this->repository->create($input);
            return $this->json($response->withStatus(201), ['data' => $sensor]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Sensor could not be created']);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validate($input);
            $sensor = $this->repository->update((int) $arguments['id'], $input);
            return $this->json($response, ['data' => $sensor]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Sensor could not be updated']);
        }
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            return $this->json($response, ['data' => $this->repository->archive((int) $arguments['id'])]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Sensor could not be removed']);
        }
    }

    private function validate(array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        $host = trim((string) ($input['host'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name must contain 2-160 characters');
        }
        if ($host === '' || mb_strlen($host) > 255) {
            throw new InvalidArgumentException('Host is required');
        }
        $port = filter_var($input['snmp_port'] ?? 161, FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SNMP port must be between 1 and 65535');
        }
        foreach (['temperature_oid', 'humidity_oid'] as $field) {
            $oid = trim((string) ($input[$field] ?? ''));
            if ($oid !== '' && !preg_match('/^\.?\d+(\.\d+)+$/', $oid)) {
                throw new InvalidArgumentException('OID must look like 1.3.6.1.4.1.21796.4.1.3.1.4.1');
            }
        }
        foreach ([['temperature_min', 'temperature_max'], ['humidity_min', 'humidity_max']] as [$minField, $maxField]) {
            $min = $input[$minField] ?? '';
            $max = $input[$maxField] ?? '';
            foreach ([$minField => $min, $maxField => $max] as $field => $raw) {
                if ($raw !== '' && $raw !== null && filter_var($raw, FILTER_VALIDATE_FLOAT) === false) {
                    throw new InvalidArgumentException(sprintf('%s must be a number', $field));
                }
            }
            if ($min !== '' && $min !== null && $max !== '' && $max !== null && (float) $min > (float) $max) {
                throw new InvalidArgumentException(sprintf('%s cannot be greater than %s', $minField, $maxField));
            }
        }
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
