<?php
// Batch operations and webhook helper functions
// =====================
// Webhook Functions
// =====================

/**
 * Trigger webhook for an event (delegated to plugins via filter)
 */
function triggerWebhook($event, $payload)
{
    if (class_exists('PluginManager')) {
        PluginManager::applyFilter('trigger_webhook', null, $event, $payload);
    }
}

// =====================
// Batch Operations
// =====================

/**
 * Batch insert multiple rows into a table
 * @param string $table Table name
 * @param array $columns Column names
 * @param array $rows Array of row data arrays
 * @param int $chunkSize Number of rows per insert (default 100)
 * @return int Number of rows inserted
 */
function batchInsert(string $table, array $columns, array $rows, int $chunkSize = 100): int
{
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    // Build column list
    $columnList = implode(', ', array_map(function ($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    // Process in chunks to avoid memory issues
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "INSERT INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        // Flatten values array
        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += count($chunk);
    }

    return $inserted;
}

/**
 * Batch insert with IGNORE (skip duplicates)
 */
function batchInsertIgnore(string $table, array $columns, array $rows, int $chunkSize = 100): int
{
    if (empty($rows) || empty($columns)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();
    $inserted = 0;

    $columnList = implode(', ', array_map(function ($col) use ($type) {
        return $type === 'mysql' ? "`$col`" : "\"$col\"";
    }, $columns));

    $insertKeyword = $type === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $placeholderRow = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($chunk), $placeholderRow));

        $sql = "$insertKeyword INTO $table ($columnList) VALUES $placeholders";
        $stmt = $db->prepare($sql);

        $values = [];
        foreach ($chunk as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt->execute($values);
        $inserted += $stmt->rowCount();
    }

    return $inserted;
}

/**
 * Batch update using CASE statements (more efficient than individual updates)
 * @param string $table Table name
 * @param string $idColumn Primary key column
 * @param string $updateColumn Column to update
 * @param array $updates Array of [id => value] pairs
 * @return int Number of rows affected
 */
function batchUpdate(string $table, string $idColumn, string $updateColumn, array $updates): int
{
    if (empty($updates)) {
        return 0;
    }

    $db = getDB();
    $type = $db->getType();

    $ids = array_keys($updates);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    // Build CASE statement
    $caseStmt = "CASE $idColumn ";
    $params = [];
    foreach ($updates as $id => $value) {
        $caseStmt .= "WHEN ? THEN ? ";
        $params[] = $id;
        $params[] = $value;
    }
    $caseStmt .= "END";

    // Add IDs for WHERE clause
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $sql = "UPDATE $table SET $updateColumn = $caseStmt WHERE $idColumn IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

