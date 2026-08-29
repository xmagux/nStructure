<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Application\View\ViewContext;
use NStructure\Domain\Repository\AlertRepository;
use NStructure\Domain\Repository\SensorRepository;
use NStructure\Infrastructure\Heartbeat\HeartbeatStore;
use NStructure\Infrastructure\Metrics\VictoriaMetricsClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Views\Twig;
use Throwable;

final readonly class SensorController
{
    // VictoriaMetrics's Influx line-protocol ingestion names each series
    // "<measurement>_<field>"; every point is written with field "value",
    // so the daemon's measurement names all gain a "_value" suffix here.
    private const METRIC_NAMES = [
        'temperature' => 'sensor_temperature_celsius_value',
        'humidity' => 'sensor_humidity_percent_value',
        'ping_latency' => 'sensor_ping_latency_ms_value',
        'ping_up' => 'sensor_ping_up_value',
    ];

    private const RANGE_SECONDS = [
        '5m' => 300,
        '1h' => 3600,
        '6h' => 21600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    private const MAX_POINTS = 1000;

    public function __construct(
        private Twig $view,
        private ViewContext $context,
        private SensorRepository $repository,
        private VictoriaMetricsClient $metrics,
        private HeartbeatStore $heartbeat,
        private AlertRepository $alertRepository,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sensors = $this->repository->all();
        $layout = ['order' => [], 'sizes' => []];
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId !== null) {
            $layout = $this->repository->getTileLayout((int) $userId);
            $sensors = $this->applyTileOrder($sensors, $layout['order']);
        }
        $data = $this->context->make('page.sensors', 'sensors', [
            'sensors' => $sensors,
            'sensor_sizes' => $layout['sizes'],
            'alert_recipients' => $this->alertRepository->listRecipients(),
            'alert_groups' => $this->alertRepository->listGroups(),
            'alert_settings' => $this->alertRepository->getSettings(),
            'alert_targets' => $this->alertRepository->listAllSensorAlertTargets(),
        ]);
        return $this->view->render($response, 'pages/sensors.twig', $data);
    }

    public function saveLayout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return $this->json($response->withStatus(401), ['error' => 'Not authenticated']);
        }
        $input = (array) ($request->getParsedBody() ?? []);
        $order = is_array($input['order'] ?? null) ? $input['order'] : [];
        $sizes = is_array($input['sizes'] ?? null) ? $input['sizes'] : [];
        $this->repository->saveTileLayout((int) $userId, $order, $sizes);
        return $this->json($response, ['data' => ['ok' => true]]);
    }

    /**
     * Sorts sensors by the user's saved tile order, with any sensor not in
     * that list (newly added since they last arranged things) appended at
     * the end in its default name-sorted position.
     */
    private function applyTileOrder(array $sensors, array $order): array
    {
        if ($order === []) {
            return $sensors;
        }
        $positions = array_flip($order);
        $indexed = array_values($sensors);
        usort($indexed, static function (array $a, array $b) use ($positions): int {
            $posA = $positions[$a['id']] ?? PHP_INT_MAX;
            $posB = $positions[$b['id']] ?? PHP_INT_MAX;
            return $posA <=> $posB;
        });
        return $indexed;
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

    public function history(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $sensorId = (int) $arguments['id'];
        $sensor = $this->repository->find($sensorId);
        if ($sensor === null) {
            return $this->json($response->withStatus(404), ['error' => 'Sensor not found']);
        }

        $query = $request->getQueryParams();
        $metricKey = (string) ($query['metric'] ?? 'temperature');
        $rangeKey = (string) ($query['range'] ?? '24h');
        if ($metricKey === 'channel') {
            $channelId = filter_var($query['channel_id'] ?? null, FILTER_VALIDATE_INT);
            if ($channelId === false || $channelId === null) {
                return $this->json($response->withStatus(422), ['error' => 'channel_id is required']);
            }
        } elseif (!isset(self::METRIC_NAMES[$metricKey])) {
            return $this->json($response->withStatus(422), ['error' => 'Unknown metric']);
        }
        if (!isset(self::RANGE_SECONDS[$rangeKey])) {
            return $this->json($response->withStatus(422), ['error' => 'Unknown range']);
        }

        $rangeSeconds = self::RANGE_SECONDS[$rangeKey];
        $end = time();
        $sinceMs = filter_var($query['since'] ?? null, FILTER_VALIDATE_INT);
        $start = $sinceMs !== false && $sinceMs !== null
            ? max($end - $rangeSeconds, (int) ($sinceMs / 1000))
            : $end - $rangeSeconds;
        $windowSeconds = max(1, $end - $start);
        $step = $this->formatStepDuration(max(1, (int) ceil($windowSeconds / self::MAX_POINTS)));

        $promql = $metricKey === 'channel'
            ? sprintf('sensor_channel_value_value{sensor_id="%d",channel_id="%d"}', $sensorId, $channelId)
            : sprintf('%s{sensor_id="%d"}', self::METRIC_NAMES[$metricKey], $sensorId);
        $points = $this->metrics->queryRange($promql, $start, $end, $step);

        return $this->json($response, ['data' => $points]);
    }

    public function heartbeat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $sensorId = filter_var($input['sensor_id'] ?? null, FILTER_VALIDATE_INT);
        if ($sensorId === false || $sensorId === null) {
            return $this->json($response->withStatus(422), ['error' => 'sensor_id is required']);
        }
        if ($this->repository->find($sensorId) === null) {
            return $this->json($response->withStatus(404), ['error' => 'Sensor not found']);
        }

        $this->heartbeat->touch($sensorId);
        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function metricsStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['data' => ['reachable' => $this->metrics->isReachable()]]);
    }

    private function formatStepDuration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds . 's',
            $seconds < 3600 => (int) ceil($seconds / 60) . 'm',
            $seconds < 86400 => (int) ceil($seconds / 3600) . 'h',
            default => (int) ceil($seconds / 86400) . 'd',
        };
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
        $this->validateChannels($input['channels'] ?? null);
    }

    /**
     * The form ships extra channels as a JSON-encoded string (the shared
     * submit helper can only carry flat string fields), so this both
     * decodes and validates each row before it ever reaches the repository.
     */
    private function validateChannels(mixed $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }
        $channels = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($channels)) {
            throw new InvalidArgumentException('Channels must be a list');
        }
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                throw new InvalidArgumentException('Each channel must be an object');
            }
            $label = trim((string) ($channel['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                throw new InvalidArgumentException('Channel label must contain 1-80 characters');
            }
            if (!in_array($channel['channel_type'] ?? null, ['temperature', 'humidity'], true)) {
                throw new InvalidArgumentException('Channel type must be temperature or humidity');
            }
            $oid = trim((string) ($channel['value_oid'] ?? ''));
            if ($oid === '' || !preg_match('/^\.?\d+(\.\d+)+$/', $oid)) {
                throw new InvalidArgumentException('Channel OID must look like 1.3.6.1.4.1.21796.4.9.3.1.4.3');
            }
            $divisor = $channel['value_divisor'] ?? '';
            if ($divisor !== '' && $divisor !== null && filter_var($divisor, FILTER_VALIDATE_FLOAT) === false) {
                throw new InvalidArgumentException('Channel divisor must be a number');
            }
        }
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
