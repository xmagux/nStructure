<?php

declare(strict_types=1);

namespace NStructure\Domain\Repository;

/**
 * A handful of branding labels shown on every page (the sidebar workspace
 * dropdown, the dashboard's network map subtitle) — previously hard-coded
 * translation strings, now editable from Ustawienia. Any field left blank
 * falls back to its translated default.
 */
interface WorkspaceRepository
{
    public function get(): array;

    public function update(array $input): array;
}
