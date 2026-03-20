#!/usr/bin/env php
<?php
/**
 * Database Seeder
 *
 * Creates sample data for development and testing.
 *
 * Usage:
 *   php cli/seed.php [options]
 *
 * Options:
 *   --seeder=NAME    Run a specific seeder (default: all)
 *   --fresh          Truncate tables before seeding
 *   --count=N        Number of records to create (default varies by seeder)
 *   --list           List available seeders
 *   --help           Show this help
 */

chdir(dirname(__DIR__));
require_once 'includes/config.php';

// Parse options
$options = getopt('', ['seeder:', 'fresh', 'count:', 'list', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Available seeders
$seeders = [
    'users' => [
        'description' => 'Create sample users',
        'class' => 'UserSeeder',
        'default_count' => 10
    ],
    'categories' => [
        'description' => 'Create sample categories',
        'class' => 'CategorySeeder',
        'default_count' => 10
    ],
    'tags' => [
        'description' => 'Create sample tags',
        'class' => 'TagSeeder',
        'default_count' => 20
    ],
    'collections' => [
        'description' => 'Create sample collections',
        'class' => 'CollectionSeeder',
        'default_count' => 5
    ],
    'models' => [
        'description' => 'Create sample 3D models (metadata only)',
        'class' => 'ModelSeeder',
        'default_count' => 50
    ],
    'activity' => [
        'description' => 'Create sample activity log entries',
        'class' => 'ActivitySeeder',
        'default_count' => 100
    ],
];

if (isset($options['list'])) {
    listSeeders($seeders);
    exit(0);
}

$db = getDB();

// Determine which seeders to run
$targetSeeder = $options['seeder'] ?? null;
$count = isset($options['count']) ? (int)$options['count'] : null;
$fresh = isset($options['fresh']);

echo "Silo Database Seeder\n";
echo "====================\n\n";

if ($fresh) {
    echo "Warning: --fresh will truncate tables. Proceed? [y/N] ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
    echo "\n";
}

// Run seeders
$toRun = $targetSeeder ? [$targetSeeder => $seeders[$targetSeeder] ?? null] : $seeders;

foreach ($toRun as $name => $config) {
    if (!$config) {
        echo "Unknown seeder: $name\n";
        continue;
    }

    $seederCount = $count ?? $config['default_count'];
    echo "Running {$name} seeder ({$seederCount} records)...\n";

    try {
        $seederClass = $config['class'];
        $seeder = new $seederClass($db);

        if ($fresh) {
            $seeder->truncate();
        }

        $created = $seeder->run($seederCount);
        echo "  Created {$created} records.\n";
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
}

echo "\nSeeding complete!\n";

// ========================================
// Helper Functions
// ========================================

function showHelp(): void {
    echo <<<HELP
Silo Database Seeder

Usage: php cli/seed.php [options]

Options:
  --seeder=NAME    Run a specific seeder (default: all)
  --fresh          Truncate tables before seeding
  --count=N        Number of records to create
  --list           List available seeders
  --help           Show this help

Examples:
  php cli/seed.php                      # Run all seeders
  php cli/seed.php --seeder=users       # Run only users seeder
  php cli/seed.php --fresh --count=100  # Fresh seed with 100 records each
  php cli/seed.php --list               # List available seeders

HELP;
}

function listSeeders(array $seeders): void {
    echo "Available Seeders:\n";
    echo "------------------\n";
    foreach ($seeders as $name => $config) {
        printf("  %-15s %s (default: %d)\n",
            $name,
            $config['description'],
            $config['default_count']
        );
    }
}

// ========================================
// Base Seeder Class
// ========================================

abstract class Seeder {
    protected $db;
    protected string $table;

    public function __construct($db) {
        $this->db = $db;
    }

    abstract public function run(int $count): int;

    public function truncate(): void {
        $this->db->exec("DELETE FROM {$this->table}");
        echo "  Truncated {$this->table}\n";
    }

    protected function randomElement(array $array) {
        return $array[array_rand($array)];
    }

    protected function randomDate(string $start = '-1 year', string $end = 'now'): string {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        return date('Y-m-d H:i:s', rand($startTs, $endTs));
    }

    protected function faker(): object {
        return new class {
            private array $firstNames = ['James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda', 'William', 'Elizabeth', 'David', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen'];
            private array $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin'];
            private array $words = ['print', 'model', 'design', 'custom', 'mini', 'figure', 'tool', 'part', 'holder', 'mount', 'bracket', 'case', 'box', 'stand', 'hook', 'clip', 'gear', 'wheel', 'frame', 'base'];
            private array $adjectives = ['custom', 'modular', 'adjustable', 'compact', 'large', 'small', 'sturdy', 'lightweight', 'reinforced', 'improved', 'v2', 'v3', 'remix', 'modified', 'enhanced'];

            public function firstName(): string {
                return $this->firstNames[array_rand($this->firstNames)];
            }

            public function lastName(): string {
                return $this->lastNames[array_rand($this->lastNames)];
            }

            public function name(): string {
                return $this->firstName() . ' ' . $this->lastName();
            }

            public function email(): string {
                return strtolower($this->firstName() . '.' . $this->lastName() . rand(1, 999) . '@example.com');
            }

            public function username(): string {
                return strtolower($this->firstName() . rand(1, 999));
            }

            public function word(): string {
                return $this->words[array_rand($this->words)];
            }

            public function words(int $count = 3): string {
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $result[] = $this->word();
                }
                return implode(' ', $result);
            }

            public function sentence(): string {
                return ucfirst($this->words(rand(5, 10))) . '.';
            }

            public function paragraph(): string {
                $sentences = [];
                for ($i = 0; $i < rand(3, 6); $i++) {
                    $sentences[] = $this->sentence();
                }
                return implode(' ', $sentences);
            }

            public function modelName(): string {
                $adj = $this->adjectives[array_rand($this->adjectives)];
                $word = $this->word();
                return ucfirst($adj) . ' ' . ucfirst($word);
            }

            public function hexColor(): string {
                return '#' . str_pad(dechex(rand(0, 16777215)), 6, '0', STR_PAD_LEFT);
            }

            public function url(): string {
                return 'https://example.com/' . $this->word() . '/' . rand(1, 1000);
            }

            public function boolean(int $chanceOfTrue = 50): bool {
                return rand(1, 100) <= $chanceOfTrue;
            }

            public function numberBetween(int $min, int $max): int {
                return rand($min, $max);
            }
        };
    }
}

// ========================================
// Seeders
// ========================================

class UserSeeder extends Seeder {
    protected string $table = 'users';

    public function run(int $count): int {
        $faker = $this->faker();
        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            $username = $faker->username();
            $email = $faker->email();
            $name = $faker->name();
            $password = password_hash('password123', PASSWORD_ARGON2ID);
            $createdAt = $this->randomDate('-6 months');

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO users (username, email, display_name, password, created_at)
                    VALUES (:username, :email, :name, :password, :created_at)
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':name' => $name,
                    ':password' => $password,
                    ':created_at' => $createdAt
                ]);
                $created++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }

        return $created;
    }
}

class CategorySeeder extends Seeder {
    protected string $table = 'categories';

    public function run(int $count): int {
        $categories = [
            'Tools', 'Toys', 'Gadgets', 'Home', 'Garden', 'Kitchen',
            'Office', 'Gaming', 'Cosplay', 'Art', 'Jewelry', 'Fashion',
            'Automotive', 'Electronics', 'Music', 'Sports', 'Education',
            'Science', 'Medical', 'Mechanical'
        ];

        $created = 0;
        $toCreate = array_slice($categories, 0, min($count, count($categories)));

        foreach ($toCreate as $name) {
            try {
                $stmt = $this->db->prepare("INSERT INTO categories (name) VALUES (:name)");
                $stmt->execute([':name' => $name]);
                $created++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }

        return $created;
    }
}

class TagSeeder extends Seeder {
    protected string $table = 'tags';

    public function run(int $count): int {
        $faker = $this->faker();
        $tags = [
            'FDM', 'SLA', 'Resin', 'No Supports', 'Easy Print', 'Remix',
            'Functional', 'Decorative', 'Articulated', 'Snap Fit',
            'Beginner', 'Advanced', 'Multi-Part', 'Single Print',
            'PLA', 'PETG', 'ABS', 'TPU', 'Silk', 'Matte',
            'Tested', 'WIP', 'Beta', 'Final', 'Popular'
        ];

        $created = 0;
        $toCreate = array_slice($tags, 0, min($count, count($tags)));

        foreach ($toCreate as $name) {
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO tags (name, color, created_at)
                    VALUES (:name, :color, :created_at)
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':color' => $faker->hexColor(),
                    ':created_at' => $this->randomDate('-3 months')
                ]);
                $created++;
            } catch (Exception $e) {
                // Skip duplicates
            }
        }

        return $created;
    }
}

class CollectionSeeder extends Seeder {
    protected string $table = 'collections';

    public function run(int $count): int {
        $faker = $this->faker();
        $collections = [
            'Desk Organizers' => 'Organization tools and accessories for your desk',
            'Board Game Accessories' => 'Custom inserts, tokens, and accessories for board games',
            'Plant Pots' => 'Decorative and functional planters',
            'Phone Accessories' => 'Stands, mounts, and cases for phones',
            'Cable Management' => 'Clips, holders, and organizers for cables',
            'Miniatures' => 'Tabletop gaming miniatures and terrain',
            'Kitchen Gadgets' => 'Useful tools for the kitchen',
            'Wall Art' => 'Decorative pieces for walls',
            'Cosplay Props' => 'Props and accessories for cosplay',
            'Tool Holders' => 'Organization for workshop tools'
        ];

        $created = 0;
        $i = 0;

        foreach ($collections as $name => $description) {
            if ($i >= $count) break;

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO collections (name, description, created_at)
                    VALUES (:name, :description, :created_at)
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':created_at' => $this->randomDate('-3 months')
                ]);
                $created++;
            } catch (Exception $e) {
                // Skip duplicates
            }
            $i++;
        }

        return $created;
    }
}

class ModelSeeder extends Seeder {
    protected string $table = 'models';

    public function run(int $count): int {
        $faker = $this->faker();
        $created = 0;

        // Get existing categories and tags
        $categories = $this->db->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
        $tags = $this->db->query("SELECT id FROM tags")->fetchAll(PDO::FETCH_COLUMN);

        $fileTypes = ['stl', '3mf'];
        $printTypes = ['fdm', 'sla', 'both', null];
        $licenses = ['CC BY 4.0', 'CC BY-SA 4.0', 'CC BY-NC 4.0', 'CC0', 'Personal Use', null];

        for ($i = 0; $i < $count; $i++) {
            $name = $faker->modelName();
            $filename = str_replace(' ', '_', strtolower($name)) . '.' . $this->randomElement($fileTypes);
            $fileType = pathinfo($filename, PATHINFO_EXTENSION);
            $fileSize = $faker->numberBetween(50000, 50000000);
            $createdAt = $this->randomDate('-6 months');

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO models (
                        name, filename, file_path, file_size, file_type,
                        description, creator, print_type, license,
                        download_count, is_archived, is_printed,
                        dim_x, dim_y, dim_z, dim_unit,
                        created_at, updated_at
                    ) VALUES (
                        :name, :filename, :file_path, :file_size, :file_type,
                        :description, :creator, :print_type, :license,
                        :download_count, :is_archived, :is_printed,
                        :dim_x, :dim_y, :dim_z, :dim_unit,
                        :created_at, :updated_at
                    )
                ");

                $stmt->execute([
                    ':name' => $name,
                    ':filename' => $filename,
                    ':file_path' => 'seed/' . $filename,
                    ':file_size' => $fileSize,
                    ':file_type' => $fileType,
                    ':description' => $faker->paragraph(),
                    ':creator' => $faker->name(),
                    ':print_type' => $this->randomElement($printTypes),
                    ':license' => $this->randomElement($licenses),
                    ':download_count' => $faker->numberBetween(0, 500),
                    ':is_archived' => $faker->boolean(10) ? 1 : 0,
                    ':is_printed' => $faker->boolean(30) ? 1 : 0,
                    ':dim_x' => $faker->numberBetween(10, 200),
                    ':dim_y' => $faker->numberBetween(10, 200),
                    ':dim_z' => $faker->numberBetween(5, 150),
                    ':dim_unit' => 'mm',
                    ':created_at' => $createdAt,
                    ':updated_at' => $createdAt
                ]);

                $modelId = $this->db->lastInsertId();

                // Add random categories
                if (!empty($categories) && $faker->boolean(70)) {
                    $numCategories = $faker->numberBetween(1, 3);
                    $selectedCategories = array_rand(array_flip($categories), min($numCategories, count($categories)));
                    if (!is_array($selectedCategories)) $selectedCategories = [$selectedCategories];

                    foreach ($selectedCategories as $catId) {
                        try {
                            $stmt = $this->db->prepare("
                                INSERT INTO model_categories (model_id, category_id)
                                VALUES (:model_id, :category_id)
                            ");
                            $stmt->execute([':model_id' => $modelId, ':category_id' => $catId]);
                        } catch (Exception $e) {}
                    }
                }

                // Add random tags
                if (!empty($tags) && $faker->boolean(80)) {
                    $numTags = $faker->numberBetween(1, 5);
                    $selectedTags = array_rand(array_flip($tags), min($numTags, count($tags)));
                    if (!is_array($selectedTags)) $selectedTags = [$selectedTags];

                    foreach ($selectedTags as $tagId) {
                        try {
                            $stmt = $this->db->prepare("
                                INSERT INTO model_tags (model_id, tag_id)
                                VALUES (:model_id, :tag_id)
                            ");
                            $stmt->execute([':model_id' => $modelId, ':tag_id' => $tagId]);
                        } catch (Exception $e) {}
                    }
                }

                $created++;
            } catch (Exception $e) {
                // Skip errors
            }
        }

        return $created;
    }
}

class ActivitySeeder extends Seeder {
    protected string $table = 'activity_log';

    public function run(int $count): int {
        $faker = $this->faker();
        $created = 0;

        // Get existing users and models
        $users = $this->db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $models = $this->db->query("SELECT id, name FROM models")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users) || empty($models)) {
            echo "  Warning: Need users and models to seed activity log\n";
            return 0;
        }

        $actions = ['upload', 'download', 'view', 'edit', 'favorite', 'tag'];

        for ($i = 0; $i < $count; $i++) {
            $action = $this->randomElement($actions);
            $model = $this->randomElement($models);
            $userId = $this->randomElement($users);
            $createdAt = $this->randomDate('-3 months');

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO activity_log (
                        user_id, action, entity_type, entity_id, entity_name,
                        ip_address, created_at
                    ) VALUES (
                        :user_id, :action, :entity_type, :entity_id, :entity_name,
                        :ip_address, :created_at
                    )
                ");

                $stmt->execute([
                    ':user_id' => $userId,
                    ':action' => $action,
                    ':entity_type' => 'model',
                    ':entity_id' => $model['id'],
                    ':entity_name' => $model['name'],
                    ':ip_address' => long2ip(rand(0, 4294967295)),
                    ':created_at' => $createdAt
                ]);

                $created++;
            } catch (Exception $e) {
                // Skip errors
            }
        }

        return $created;
    }
}
