<?php

declare(strict_types=1);

namespace NStructure\Domain\Repository;

interface NetworkRepository
{
    public function dashboard(): array;

    public function topology(): array;

    public function inventory(): array;

    public function locations(): array;

    public function location(int $id): ?array;

    public function rack(int $id): ?array;

    public function panel(int $id): ?array;

    public function serverRoomExists(int $id): bool;

    public function cableExists(int $id): bool;

    public function assetImage(int $id): ?array;

    public function addAssetImage(string $entityType, int $entityId, array $metadata): array;

    public function cables(): array;

    public function cableEndpointOptions(): array;

    public function search(string $query): array;

    public function connectorTypes(): array;

    public function activeDeviceOptions(): array;

    public function createLocation(array $input): array;

    public function updateLocation(int $locationId, array $input): array;

    public function archiveLocation(int $locationId): array;

    public function createServerRoom(int $locationId, array $input): array;

    public function updateServerRoom(int $serverRoomId, array $input): array;

    public function archiveServerRoom(int $serverRoomId): array;

    public function createUpsDevice(int $serverRoomId, array $input): array;

    public function updateUpsDevice(int $upsDeviceId, array $input): array;

    public function archiveUpsDevice(int $upsDeviceId): array;

    public function createRack(int $serverRoomId, array $input): array;

    public function updateRack(int $rackId, array $input): array;

    public function archiveRack(int $rackId): array;

    public function createPatchPanel(int $rackId, array $input): array;

    public function updatePatchPanel(int $panelId, array $input): array;

    public function archivePatchPanel(int $panelId): array;

    public function updatePort(int $portId, array $input): array;

    public function rearFiberRoutes(int $portId): array;

    public function frontPortTargets(int $portId, string $query = ''): array;

    public function connectionTargets(int $portId, string $query = '', string $routeKey = ''): array;

    public function connectPorts(int $sourcePortId, int $destinationPortId, array $input): array;

    public function createCable(array $input): array;

    public function updateCable(int $cableId, array $input): array;

    public function archiveCable(int $cableId): array;

    public function tracePort(int $portId): ?array;
}
