<?php
/**
 * Quick database schema checker
 */

require_once __DIR__ . '/includes/config.php';

$db = getDatabase();
$result = $db->query("PRAGMA table_info(models)");

echo "Current models table schema:\n";
echo str_repeat("-", 80) . "\n";
printf("%-5s %-30s %-15s %-10s %-10s\n", "CID", "Name", "Type", "NotNull", "Default");
echo str_repeat("-", 80) . "\n";

$columnCount = 0;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    printf("%-5s %-30s %-15s %-10s %-10s\n",
        $row['cid'],
        $row['name'],
        $row['type'],
        $row['notnull'],
        $row['dflt_value'] ?? 'NULL'
    );
    $columnCount++;
}

echo str_repeat("-", 80) . "\n";
echo "Total columns: $columnCount\n\n";

// Check migration status
echo "Migration status:\n";
echo str_repeat("-", 80) . "\n";
$migResult = $db->query("SELECT * FROM migrations ORDER BY id DESC LIMIT 10");
if ($migResult) {
    while ($row = $migResult->fetchArray(SQLITE3_ASSOC)) {
        printf("%s - %s - %s\n", $row['id'], $row['migration'], $row['applied_at']);
    }
} else {
    echo "No migrations table found\n";
}
