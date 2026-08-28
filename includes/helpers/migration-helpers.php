<?php

/**
 * Migration status/run helpers for the web migration UI.
 *
 * Used by: app/admin/database.php.
 *
 * These call getMigrationList() from includes/migrations.php. That file is not
 * loaded globally on purpose (cli/migrate.php declares its own unguarded
 * getPendingMigrations()/showMigrationStatus()), so callers must require
 * includes/migrations.php themselves - as that page already does.
 */

/**
 * Build the applied/pending listing for every known migration.
 *
 * @return array{migrations: array<int, array{name: string, description: string, applied: bool}>, appliedCount: int, pendingCount: int}
 */
function getMigrationStatus($db): array
{
    $appliedCount = 0;
    $pendingCount = 0;
    $migrationStatus = [];

    foreach (getMigrationList() as $m) {
        $isApplied = $m['check']($db);
        $migrationStatus[] = [
            'name' => $m['name'],
            'description' => $m['description'] ?? '',
            'applied' => $isApplied
        ];
        if ($isApplied) {
            $appliedCount++;
        } else {
            $pendingCount++;
        }
    }

    return [
        'migrations' => $migrationStatus,
        'appliedCount' => $appliedCount,
        'pendingCount' => $pendingCount,
    ];
}

/**
 * Apply every pending migration.
 *
 * @param bool $stopOnError Stop after the first failure (prevents cascading
 *                          issues) instead of attempting the remaining ones.
 * @return array{results: array<int, array{name: string, success: bool, error?: string}>, applied: int, errors: int}
 */
function runPendingMigrations($db, bool $stopOnError = true): array
{
    $applied = 0;
    $errors = 0;
    $results = [];

    foreach (getMigrationList() as $migration) {
        if ($migration['check']($db)) {
            continue;
        }

        try {
            $migration['apply']($db);
            $results[] = ['name' => $migration['name'], 'success' => true];
            $applied++;
        } catch (Exception $e) {
            $results[] = ['name' => $migration['name'], 'success' => false, 'error' => $e->getMessage()];
            $errors++;
            if ($stopOnError) {
                break;
            }
        }
    }

    return [
        'results' => $results,
        'applied' => $applied,
        'errors' => $errors,
    ];
}
