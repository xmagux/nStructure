<?php

declare(strict_types=1);

namespace NStructure\Http\Controller;

use InvalidArgumentException;
use NStructure\Domain\Exception\ResourceInUseException;
use NStructure\Domain\Repository\NetworkRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class ApiController
{
    public function __construct(private NetworkRepository $repository)
    {
    }

    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['status' => 'ok', 'service' => 'nStructure']);
    }

    public function topology(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['data' => $this->repository->topology()]);
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, ['data' => $this->repository->search($query)]);
    }

    public function panel(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $panel = $this->repository->panel((int) $arguments['id']);
        return $panel === null
            ? $this->json($response->withStatus(404), ['error' => 'Patch panel not found'])
            : $this->json($response, ['data' => $panel]);
    }

    public function createLocation(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateLocation($input);
            $location = $this->repository->createLocation($input);
            return $this->json($response->withStatus(201), ['data' => $location]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable) {
            return $this->json($response->withStatus(409), ['error' => 'Location could not be created']);
        }
    }

    public function updateLocation(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateLocation($input);
            return $this->json($response, ['data' => $this->repository->updateLocation((int) $arguments['id'], $input)]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Location could not be updated']);
        }
    }

    public function archiveLocation(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveLocation((int) $arguments['id']),
        );
    }

    public function createServerRoom(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateName($input);
            if (mb_strlen(trim((string) ($input['floor'] ?? ''))) > 40) {
                throw new InvalidArgumentException('Floor must contain no more than 40 characters');
            }
            $room = $this->repository->createServerRoom((int) $arguments['id'], $input);
            return $this->json($response->withStatus(201), ['data' => $room]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable) {
            return $this->json($response->withStatus(409), ['error' => 'Server room could not be created']);
        }
    }

    public function updateServerRoom(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateName($input);
            $locationId = filter_var($input['location_id'] ?? null, FILTER_VALIDATE_INT);
            if ($locationId === false || $locationId < 1) {
                throw new InvalidArgumentException('Location is required');
            }
            if (mb_strlen(trim((string) ($input['floor'] ?? ''))) > 40) {
                throw new InvalidArgumentException('Floor must contain no more than 40 characters');
            }
            return $this->json($response, ['data' => $this->repository->updateServerRoom((int) $arguments['id'], $input)]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Server room could not be updated']);
        }
    }

    public function archiveServerRoom(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveServerRoom((int) $arguments['id']),
        );
    }

    public function createUpsDevice(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateUpsDevice($input);
            $upsDevice = $this->repository->createUpsDevice((int) $arguments['id'], $input);
            return $this->json($response->withStatus(201), ['data' => $upsDevice]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'UPS device could not be created']);
        }
    }

    public function updateUpsDevice(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateUpsDevice($input);
            return $this->json($response, ['data' => $this->repository->updateUpsDevice((int) $arguments['id'], $input)]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'UPS device could not be updated']);
        }
    }

    public function archiveUpsDevice(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveUpsDevice((int) $arguments['id']),
        );
    }

    public function createRack(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateNamedEntity($input);
            $totalUnits = filter_var($input['total_units'] ?? null, FILTER_VALIDATE_INT);
            if ($totalUnits === false || $totalUnits < 1 || $totalUnits > 60) {
                throw new InvalidArgumentException('Rack size must contain 1-60 units');
            }
            $rack = $this->repository->createRack((int) $arguments['id'], $input);
            return $this->json($response->withStatus(201), ['data' => $rack]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable) {
            return $this->json($response->withStatus(409), ['error' => 'Rack could not be created']);
        }
    }

    public function updateRack(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateNamedEntity($input);
            $totalUnits = filter_var($input['total_units'] ?? null, FILTER_VALIDATE_INT);
            if ($totalUnits === false || $totalUnits < 1 || $totalUnits > 60) {
                throw new InvalidArgumentException('Rack size must contain 1-60 units');
            }
            return $this->json($response, ['data' => $this->repository->updateRack((int) $arguments['id'], $input)]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Rack could not be updated']);
        }
    }

    public function archiveRack(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveRack((int) $arguments['id']),
        );
    }

    public function createPatchPanel(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateName($input);
            $start = filter_var($input['rack_unit_start'] ?? null, FILTER_VALIDATE_INT);
            $height = filter_var($input['rack_unit_height'] ?? null, FILTER_VALIDATE_INT);
            $portCount = filter_var($input['port_count'] ?? null, FILTER_VALIDATE_INT);
            $rows = filter_var($input['layout_rows'] ?? null, FILTER_VALIDATE_INT);
            $connectorTypeId = filter_var($input['connector_type_id'] ?? null, FILTER_VALIDATE_INT);
            if ($start === false || $start < 1 || $start > 60) {
                throw new InvalidArgumentException('Rack unit must be between 1 and 60');
            }
            if ($height === false || $height < 1 || $height > 12) {
                throw new InvalidArgumentException('Panel height must be between 1U and 12U');
            }
            if ($portCount === false || $portCount < 1 || $portCount > 288) {
                throw new InvalidArgumentException('Port count must be between 1 and 288');
            }
            if ($rows === false || $rows < 1 || $rows > 12) {
                throw new InvalidArgumentException('Layout rows must be between 1 and 12');
            }
            if ($connectorTypeId === false || $connectorTypeId < 1) {
                throw new InvalidArgumentException('Connector type is required');
            }
            $input['layout_columns'] = (int) ceil($portCount / $rows);
            $panel = $this->repository->createPatchPanel((int) $arguments['id'], $input);
            return $this->json($response->withStatus(201), ['data' => $panel]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Patch panel could not be created']);
        }
    }

    public function updatePatchPanel(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateNamedEntity($input);
            $this->validatePatchPanelGeometry($input);
            $panel = $this->repository->updatePatchPanel((int) $arguments['id'], $input);
            return $this->json($response, ['data' => $panel]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Patch panel could not be updated']);
        }
    }

    public function archivePatchPanel(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archivePatchPanel((int) $arguments['id']),
        );
    }

    public function updateActiveDevice(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateActiveDevice($input);
            $device = $this->repository->updateActiveDevice((int) $arguments['id'], $input);
            return $this->json($response, ['data' => $device]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Active device could not be updated']);
        }
    }

    public function archiveActiveDevice(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveActiveDevice((int) $arguments['id']),
        );
    }

    public function createRackItem(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateRackItem($input);
            $item = $this->repository->createRackItem((int) $arguments['id'], $input);
            return $this->json($response->withStatus(201), ['data' => $item]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Rack item could not be created']);
        }
    }

    public function updateRackItem(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateRackItem($input);
            $item = $this->repository->updateRackItem((int) $arguments['id'], $input);
            return $this->json($response, ['data' => $item]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Rack item could not be updated']);
        }
    }

    public function archiveRackItem(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveRackItem((int) $arguments['id']),
        );
    }

    public function updatePort(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $status = strtoupper(trim((string) ($input['administrative_status'] ?? '')));
            $label = trim((string) ($input['label'] ?? ''));
            $notes = trim((string) ($input['notes'] ?? ''));
            $connectorTypeId = filter_var($input['connector_type_id'] ?? null, FILTER_VALIDATE_INT);
            $frontMode = strtoupper(trim((string) ($input['front_connection_mode'] ?? 'UNCHANGED')));
            $rearMode = strtoupper(trim((string) ($input['rear_connection_mode'] ?? 'UNCHANGED')));
            $highlightColor = trim((string) ($input['highlight_color'] ?? ''));
            if (!in_array($status, ['AVAILABLE', 'RESERVED', 'BLOCKED', 'DAMAGED'], true)) {
                throw new InvalidArgumentException('Invalid administrative status');
            }
            if ($highlightColor !== '' && !in_array($highlightColor, ['red', 'orange', 'amber', 'yellow', 'lime', 'green', 'teal', 'cyan', 'blue', 'indigo', 'purple', 'pink'], true)) {
                throw new InvalidArgumentException('Invalid port highlight color');
            }
            if ($connectorTypeId === false || $connectorTypeId < 1) {
                throw new InvalidArgumentException('Select a connector type');
            }
            if (mb_strlen($label) > 120 || mb_strlen($notes) > 2000) {
                throw new InvalidArgumentException('Port description is too long');
            }
            if (!in_array($frontMode, ['UNCHANGED', 'NONE', 'DEVICE', 'PORT'], true)) {
                throw new InvalidArgumentException('Invalid front connection mode');
            }
            if (!in_array($rearMode, ['UNCHANGED', 'NONE'], true)) {
                throw new InvalidArgumentException('Invalid rear connection mode');
            }
            $patchCordLabel = trim((string) ($input['front_patch_cord_label'] ?? ''));
            $frontNotes = trim((string) ($input['front_connection_notes'] ?? ''));
            if (mb_strlen($patchCordLabel) > 120 || mb_strlen($frontNotes) > 2000) {
                throw new InvalidArgumentException('Front connection description is too long');
            }
            if ($frontMode === 'DEVICE') {
                $deviceId = filter_var($input['active_device_id'] ?? 0, FILTER_VALIDATE_INT);
                $rackId = filter_var($input['active_device_rack_id'] ?? 0, FILTER_VALIDATE_INT);
                $deviceType = strtoupper(trim((string) ($input['active_device_type'] ?? '')));
                $deviceVendor = trim((string) ($input['active_device_vendor'] ?? ''));
                $deviceName = trim((string) ($input['active_device_name'] ?? ''));
                $deviceModel = trim((string) ($input['active_device_model'] ?? ''));
                $interfaceName = trim((string) ($input['active_interface_name'] ?? ''));
                $interfaceType = strtoupper(trim((string) ($input['active_interface_type'] ?? '')));
                $interfaceSpeed = trim((string) ($input['active_interface_speed'] ?? ''));
                if (($deviceId === false || $deviceId < 1) && ($rackId === false || $rackId < 1)) {
                    throw new InvalidArgumentException('Select a rack for the active device');
                }
                if (($deviceId === false || $deviceId < 1) && !in_array($deviceType, ['SWITCH', 'ROUTER', 'FIREWALL', 'TRANSPORT', 'SERVER', 'OTHER'], true)) {
                    throw new InvalidArgumentException('Select an active device type');
                }
                if (($deviceId === false || $deviceId < 1) && (mb_strlen($deviceVendor) < 2 || mb_strlen($deviceVendor) > 120 || mb_strlen($deviceName) < 2 || mb_strlen($deviceName) > 160)) {
                    throw new InvalidArgumentException('Enter the active device vendor and name');
                }
                if (mb_strlen($deviceModel) > 120 || mb_strlen($interfaceName) < 1 || mb_strlen($interfaceName) > 120) {
                    throw new InvalidArgumentException('Enter a valid device interface');
                }
                if (!in_array($interfaceType, ['SFP', 'SFP_PLUS', 'SFP28', 'QSFP_PLUS', 'QSFP28', 'RJ45', 'OTHER'], true)) {
                    throw new InvalidArgumentException('Select an interface type');
                }
                if (mb_strlen($interfaceSpeed) > 40 || mb_strlen($patchCordLabel) > 120 || mb_strlen($frontNotes) > 2000) {
                    throw new InvalidArgumentException('Front connection description is too long');
                }
            } elseif ($frontMode === 'PORT') {
                $destinationPortId = filter_var($input['front_destination_port_id'] ?? null, FILTER_VALIDATE_INT);
                if ($destinationPortId === false || $destinationPortId < 1 || $destinationPortId === (int) $arguments['id']) {
                    throw new InvalidArgumentException('Select a front port in another rack');
                }
            }
            $port = $this->repository->updatePort((int) $arguments['id'], $input);
            return $this->json($response, ['data' => $port]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Port could not be updated']);
        }
    }

    public function rearFiberRoutes(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->json($response, ['data' => $this->repository->rearFiberRoutes((int) $arguments['id'])]);
    }

    public function frontPortTargets(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        return $this->json($response, ['data' => $this->repository->frontPortTargets((int) $arguments['id'], $query)]);
    }

    public function connectionTargets(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $routeKey = trim((string) ($request->getQueryParams()['route'] ?? ''));
        return $this->json($response, ['data' => $this->repository->connectionTargets((int) $arguments['id'], $query, $routeKey)]);
    }

    public function connectPorts(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $destinationPortId = filter_var($input['destination_port_id'] ?? null, FILTER_VALIDATE_INT);
            $sourcePortId = (int) $arguments['id'];
            if ($destinationPortId === false || $destinationPortId < 1 || $destinationPortId === $sourcePortId) {
                throw new InvalidArgumentException('Select a different destination port');
            }
            $routeKey = trim((string) ($input['rear_route_key'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $routeKey)) {
                throw new InvalidArgumentException('Select an available physical fiber route');
            }
            if (mb_strlen(trim((string) ($input['notes'] ?? ''))) > 2000) {
                throw new InvalidArgumentException('Connection notes are too long');
            }
            $connection = $this->repository->connectPorts($sourcePortId, $destinationPortId, $input);
            return $this->json($response->withStatus(201), ['data' => $connection]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Ports could not be connected']);
        }
    }

    public function createCable(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateCable($input);
            $cable = $this->repository->createCable($input);
            return $this->json($response->withStatus(201), ['data' => $cable]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable) {
            return $this->json($response->withStatus(409), ['error' => 'Cable could not be created']);
        }
    }

    public function updateCable(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        try {
            $input = (array) ($request->getParsedBody() ?? []);
            $this->validateCable($input);
            return $this->json($response, ['data' => $this->repository->updateCable((int) $arguments['id'], $input)]);
        } catch (InvalidArgumentException $exception) {
            return $this->json($response->withStatus(422), ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return $this->json($response->withStatus(409), ['error' => $exception->getMessage() ?: 'Cable could not be updated']);
        }
    }

    public function archiveCable(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        return $this->archiveResource(
            $response,
            fn (): array => $this->repository->archiveCable((int) $arguments['id']),
        );
    }

    public function tracePort(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $path = $this->repository->tracePort((int) $arguments['id']);
        return $path === null
            ? $this->json($response->withStatus(404), ['error' => 'No fiber path starts at this port'])
            : $this->json($response, ['data' => $path]);
    }

    private function archiveResource(ResponseInterface $response, callable $operation): ResponseInterface
    {
        try {
            return $this->json($response, ['data' => $operation()]);
        } catch (ResourceInUseException $exception) {
            return $this->json($response->withStatus(409), [
                'error' => $exception->getMessage(),
                'reason' => $exception->reason,
            ]);
        } catch (Throwable $exception) {
            return $this->json(
                $response->withStatus(409),
                ['error' => $exception->getMessage() ?: 'Infrastructure element could not be removed'],
            );
        }
    }

    private const LOCATION_ICONS = [
        'loc-office', 'loc-datacenter', 'loc-server-room', 'loc-tower', 'loc-warehouse',
        'loc-campus', 'loc-cloud', 'loc-satellite', 'loc-factory', 'loc-globe',
    ];

    private function validateLocation(array $input): void
    {
        $this->validateNamedEntity($input);
        $iconKey = trim((string) ($input['icon_key'] ?? 'loc-office'));
        if (!in_array($iconKey, self::LOCATION_ICONS, true)) {
            throw new InvalidArgumentException('Select a valid location icon');
        }
    }

    private function validateUpsDevice(array $input): void
    {
        $this->validateName($input);
        foreach (['manufacturer', 'model', 'serial_number'] as $field) {
            if (mb_strlen(trim((string) ($input[$field] ?? ''))) > 120) {
                throw new InvalidArgumentException('UPS manufacturer, model, and serial number must contain no more than 120 characters');
            }
        }
        foreach (['rated_power_va', 'rated_power_w'] as $field) {
            if (($input[$field] ?? '') === '') {
                continue;
            }
            $power = filter_var($input[$field], FILTER_VALIDATE_INT);
            if ($power === false || $power < 1 || $power > 10000000) {
                throw new InvalidArgumentException('UPS power must be between 1 and 10000000');
            }
        }
        $ipAddress = trim((string) ($input['ip_address'] ?? ''));
        if ($ipAddress !== '' && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('UPS IP address is invalid');
        }
        $managementUrl = trim((string) ($input['management_url'] ?? ''));
        $scheme = strtolower((string) parse_url($managementUrl, PHP_URL_SCHEME));
        if ($managementUrl !== '' && (
            mb_strlen($managementUrl) > 2048
            || filter_var($managementUrl, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
        )) {
            throw new InvalidArgumentException('UPS management URL must be a valid HTTP or HTTPS address');
        }
        $batteryDate = trim((string) ($input['battery_replaced_at'] ?? ''));
        if ($batteryDate !== '') {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $batteryDate);
            if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $batteryDate) {
                throw new InvalidArgumentException('Battery replacement date is invalid');
            }
        }
        $interval = filter_var($input['battery_replacement_interval_months'] ?? 36, FILTER_VALIDATE_INT);
        if ($interval === false || $interval < 1 || $interval > 240) {
            throw new InvalidArgumentException('Battery replacement interval must be between 1 and 240 months');
        }
        if (($input['battery_count'] ?? '') !== '') {
            $batteryCount = filter_var($input['battery_count'], FILTER_VALIDATE_INT);
            if ($batteryCount === false || $batteryCount < 1 || $batteryCount > 200) {
                throw new InvalidArgumentException('Battery count must be between 1 and 200');
            }
        }
        if (mb_strlen(trim((string) ($input['battery_type'] ?? ''))) > 160) {
            throw new InvalidArgumentException('Battery type must contain no more than 160 characters');
        }
        $status = strtoupper(trim((string) ($input['operational_status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'MAINTENANCE', 'ALARM', 'RETIRED'], true)) {
            throw new InvalidArgumentException('Invalid UPS status');
        }
        if (mb_strlen(trim((string) ($input['notes'] ?? ''))) > 4000) {
            throw new InvalidArgumentException('UPS notes must contain no more than 4000 characters');
        }
    }

    private function validateActiveDevice(array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        $vendor = trim((string) ($input['vendor'] ?? ''));
        $model = trim((string) ($input['model'] ?? ''));
        $deviceType = strtoupper(trim((string) ($input['device_type'] ?? '')));
        $managementAddress = trim((string) ($input['management_address'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name must contain 2-160 characters');
        }
        if (!in_array($deviceType, ['SWITCH', 'ROUTER', 'FIREWALL', 'TRANSPORT', 'SERVER', 'OTHER'], true)) {
            throw new InvalidArgumentException('Select a valid device type');
        }
        if (mb_strlen($vendor) < 2 || mb_strlen($vendor) > 120) {
            throw new InvalidArgumentException('Vendor must contain 2-120 characters');
        }
        if (mb_strlen($model) > 120) {
            throw new InvalidArgumentException('Model must contain no more than 120 characters');
        }
        if (mb_strlen($managementAddress) > 255) {
            throw new InvalidArgumentException('Management address must contain no more than 255 characters');
        }
        if (mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Notes must contain no more than 2000 characters');
        }
    }

    private function validateRackItem(array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        $kind = strtoupper(trim((string) ($input['kind'] ?? '')));
        $start = filter_var($input['rack_unit_start'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($input['rack_unit_height'] ?? null, FILTER_VALIDATE_INT);
        $notes = trim((string) ($input['notes'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name must contain 2-160 characters');
        }
        if (!in_array($kind, ['ORGANIZER', 'PATCH_PANEL', 'FREE_SPACE', 'POWER', 'ACTIVE_DEVICE', 'UPS', 'OTHER'], true)) {
            throw new InvalidArgumentException('Select a valid item kind');
        }
        if ($start === false || $start < 1 || $start > 60) {
            throw new InvalidArgumentException('Rack unit must be between 1 and 60');
        }
        if ($height === false || $height < 1 || $height > 12) {
            throw new InvalidArgumentException('Height must be between 1U and 12U');
        }
        if (mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('Notes must contain no more than 2000 characters');
        }
    }

    private function validateNamedEntity(array $input): void
    {
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/i', $code)) {
            throw new InvalidArgumentException('Code must contain 2-40 letters, numbers, hyphens, or underscores');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name must contain 2-160 characters');
        }
    }

    private function validateName(array $input): void
    {
        $name = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Name must contain 2-160 characters');
        }
    }

    private function validatePatchPanelGeometry(array &$input): void
    {
        $start = filter_var($input['rack_unit_start'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($input['rack_unit_height'] ?? null, FILTER_VALIDATE_INT);
        $portCount = filter_var($input['port_count'] ?? null, FILTER_VALIDATE_INT);
        $rows = filter_var($input['layout_rows'] ?? null, FILTER_VALIDATE_INT);
        $connectorTypeId = filter_var($input['connector_type_id'] ?? null, FILTER_VALIDATE_INT);
        if ($start === false || $start < 1 || $start > 60) {
            throw new InvalidArgumentException('Rack unit must be between 1 and 60');
        }
        if ($height === false || $height < 1 || $height > 12) {
            throw new InvalidArgumentException('Panel height must be between 1U and 12U');
        }
        if ($portCount === false || $portCount < 1 || $portCount > 288) {
            throw new InvalidArgumentException('Port count must be between 1 and 288');
        }
        if ($rows === false || $rows < 1 || $rows > 12) {
            throw new InvalidArgumentException('Layout rows must be between 1 and 12');
        }
        if ($connectorTypeId === false || $connectorTypeId < 1) {
            throw new InvalidArgumentException('Connector type is required');
        }
        $input['layout_columns'] = (int) ceil($portCount / $rows);
    }

    private function validateCable(array $input): void
    {
        $this->validateNamedEntity($input);
        $medium = strtoupper(trim((string) ($input['medium'] ?? '')));
        $fiberCount = filter_var($input['fiber_count'] ?? null, FILTER_VALIDATE_INT);
        $sourceEndpoint = $this->cableEndpointKey($input, 'source');
        $destinationEndpoint = $this->cableEndpointKey($input, 'destination');
        $length = filter_var($input['length_m'] ?? null, FILTER_VALIDATE_FLOAT);
        $status = strtoupper(trim((string) ($input['operational_status'] ?? '')));
        if (!in_array($medium, ['SM', 'MM'], true)) {
            throw new InvalidArgumentException('Medium must be SM or MM');
        }
        if ($fiberCount === false || $fiberCount < 1 || $fiberCount > 1728) {
            throw new InvalidArgumentException('Fiber count must be between 1 and 1728');
        }
        if ($sourceEndpoint === null || $destinationEndpoint === null || $sourceEndpoint === $destinationEndpoint) {
            throw new InvalidArgumentException('Cable endpoints must be different');
        }
        if ($length === false || $length < 0) {
            throw new InvalidArgumentException('Length must be zero or greater');
        }
        if (!in_array($status, ['PLANNED', 'ACTIVE', 'MAINTENANCE', 'DAMAGED', 'RETIRED'], true)) {
            throw new InvalidArgumentException('Invalid cable status');
        }
    }

    private function cableEndpointKey(array $input, string $side): ?string
    {
        $key = strtoupper(trim((string) ($input[$side . '_endpoint'] ?? '')));
        $legacyLocationId = filter_var($input[$side . '_location_id'] ?? null, FILTER_VALIDATE_INT);
        if ($key === '' && $legacyLocationId !== false && $legacyLocationId > 0) {
            $key = 'LOCATION:' . $legacyLocationId;
        }
        return preg_match('/^(LOCATION|ROOM|RACK):[1-9][0-9]*$/', $key) === 1 ? $key : null;
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
