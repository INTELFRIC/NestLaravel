<?php

namespace NestLaravel\Kafka\Console;

use Illuminate\Console\Command;
use NestLaravel\Kafka\Support\ProductionChecker;

/** `php artisan nestlaravel:check` — effective-configuration audit of this application (used by `nestlaravel production:check`). */
class ProductionCheckCommand extends Command
{
    protected $signature = 'nestlaravel:check {--json : Machine-readable output}';

    protected $description = 'Report PASS/WARN/FAIL findings about this application\'s production configuration';

    public function handle(): int
    {
        $findings = (new ProductionChecker)->run();

        if ($this->option('json')) {
            $this->line(json_encode(['app' => config('app.name'), 'env' => config('app.env'), 'findings' => $findings], JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($findings as $f) {
                $tag = ['pass' => '<info>[PASS]</info>', 'warn' => '<comment>[WARN]</comment>', 'fail' => '<error>[FAIL]</error>'][$f['status']];
                $this->line("{$tag} {$f['message']}");
            }
        }

        return collect($findings)->contains('status', 'fail') ? self::FAILURE : self::SUCCESS;
    }
}
