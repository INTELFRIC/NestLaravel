<?php

namespace NestLaravel\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use NestLaravel\Tenancy\Concerns\BelongsToTenant;
use ReflectionClass;
use Throwable;

/**
 * Static isolation audit: finds models/tables that could leak across tenants.
 *
 *   FAIL  a model uses BelongsToTenant but its table has no tenant column      (every query would error)
 *   FAIL  a table has a tenant column but NO model scopes it                    (rows readable across tenants)
 *   WARN  tenancy.strict_jobs is off                                            (tenant-less jobs run unscoped)
 */
class TenantCheckCommand extends Command
{
    protected $signature = 'tenant:check {--json}';

    protected $description = 'Audit models and tables for tenant isolation gaps';

    public function handle(): int
    {
        $column = (string) config('tenancy.column', 'tenant_id');
        $findings = [];
        $scopedTables = [];

        foreach ($this->modelClasses() as $class) {
            $uses = in_array(BelongsToTenant::class, class_uses_recursive($class), true);
            $table = (new $class)->getTable();

            if ($uses) {
                $scopedTables[$table] = $class;

                if (! Schema::hasColumn($table, $column)) {
                    $findings[] = ['status' => 'fail', 'message' => "{$class} uses BelongsToTenant but table [{$table}] has no [{$column}] column"];
                }
            }
        }

        foreach ($this->tablesWithColumn($column) as $table) {
            if (! isset($scopedTables[$table]) && ! in_array($table, ['outbox_messages', 'inbox_events', 'saga_instances', 'jobs', 'failed_jobs'], true)) {
                $findings[] = ['status' => 'fail', 'message' => "Table [{$table}] has a [{$column}] column but no model with BelongsToTenant scopes it"];
            }
        }

        config('tenancy.strict_jobs')
            ? $findings[] = ['status' => 'pass', 'message' => 'strict_jobs enabled: tenant-less job dispatch is refused']
            : $findings[] = ['status' => 'warn', 'message' => 'TENANCY_STRICT_JOBS=false: a job dispatched without a tenant runs unscoped'];

        $findings[] = ['status' => 'pass', 'message' => count($scopedTables).' tenant-scoped model(s): '.(implode(', ', array_keys($scopedTables)) ?: 'none')];

        if ($this->option('json')) {
            $this->line(json_encode(['findings' => $findings], JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($findings as $f) {
                $this->line(['pass' => '[PASS] ', 'warn' => '[WARN] ', 'fail' => '[FAIL] '][$f['status']].$f['message']);
            }
        }

        return collect($findings)->contains('status', 'fail') ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<class-string<Model>> */
    private function modelClasses(): array
    {
        $classes = [];
        $roots = array_filter([app_path()], 'is_dir');

        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (! preg_match('/namespace\s+([^;]+);/', $source, $ns) || ! preg_match('/class\s+(\w+)\s+extends\s+\w*Model\b/', $source, $cls)) {
                    continue;
                }
                $fqcn = trim($ns[1]).'\\'.$cls[1];
                try {
                    if (class_exists($fqcn) && is_subclass_of($fqcn, Model::class) && ! (new ReflectionClass($fqcn))->isAbstract()) {
                        $classes[] = $fqcn;
                    }
                } catch (Throwable) {
                }
            }
        }

        return $classes;
    }

    /** @return list<string> */
    private function tablesWithColumn(string $column): array
    {
        try {
            return array_values(array_filter(
                array_map(static fn ($t) => is_array($t) ? $t['name'] : ($t->name ?? (string) $t), Schema::getTables()),
                static fn (string $table) => Schema::hasColumn($table, $column),
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
