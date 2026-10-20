<?php

declare(strict_types=1);

namespace Kabuto\Console\Commands;

use Kabuto\Console\Command;

final class MakeMigrationCommand extends Command
{
    public function name(): string { return 'make:migration'; }
    public function description(): string { return 'Create a versioned database migration'; }
    public function handle(): int
    {
        $name = $this->argument(0);
        if ($name === null || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            $this->error('Usage: php kabuto make:migration <name>');
            return 1;
        }
        $dir = $this->console->basePath() . '/database/migrations';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = $dir . '/' . date('YmdHis') . '_' . $name . '.php';
        file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

use Kabuto\Database\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Write migration SQL here.
    }

    public function down(PDO $pdo): void
    {
        // Write rollback SQL here.
    }
};
PHP
        );
        $this->success('Created migration: ' . $file);
        return 0;
    }
}
