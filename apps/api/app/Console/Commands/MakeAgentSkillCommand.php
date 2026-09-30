<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeAgentSkillCommand extends Command
{
    protected $signature = 'make:agent-skill
                            {name : Skill name (kebab-case, e.g. make-invoice)}
                            {--description= : Skill description used for agent discovery}
                            {--force : Overwrite the skill if it already exists}';

    protected $description = 'Scaffold an Agent Skill (Cursor + Laravel Boost) for this platform';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = Str::kebab($this->argument('name'));

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) || strlen($name) > 64) {
            $this->components->error('Skill name must be kebab-case, max 64 characters (e.g. make-invoice).');

            return self::FAILURE;
        }

        $description = $this->option('description');
        $description = is_string($description) && trim($description) !== ''
            ? trim($description)
            : "Guides agents working on {$name} in this Laravel enterprise platform. Use when the user mentions {$name}.";

        $title = Str::headline(str_replace('-', ' ', $name));
        $stub = $this->files->get(base_path('stubs/enterprise/agent-skill.stub'));
        $contents = str_replace(
            ['{{ name }}', '{{ description }}', '{{ title }}'],
            [$name, $description, $title],
            $stub,
        );

        $written = 0;

        foreach ($this->destinations($name) as $label => $path) {
            if ($this->files->exists($path) && ! $this->option('force')) {
                $this->components->warn("Skipped existing {$label} skill: {$path}");

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $contents);
            $this->components->info("Created {$label}: {$path}");
            $written++;
        }

        if ($written === 0) {
            $this->components->error("Skill [{$name}] already exists. Re-run with --force to overwrite.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Fill in instructions in SKILL.md (keep it under 500 lines)');
        $this->line('  2. Put detailed reference material in sibling .md files, linked from SKILL.md');
        $this->line('  3. Commit .cursor/skills and apps/api/.ai/skills so the team inherits the skill');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function destinations(string $name): array
    {
        $destinations = [
            'Boost' => base_path('.ai/skills/'.$name.'/SKILL.md'),
        ];

        $root = $this->monorepoRoot();
        $cursorPath = $root.DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'SKILL.md';

        $destinations['Cursor'] = $cursorPath;

        return $destinations;
    }

    private function monorepoRoot(): string
    {
        $candidate = dirname(base_path(), 2);

        if ($this->files->isDirectory($candidate.DIRECTORY_SEPARATOR.'docs')
            && $this->files->exists($candidate.DIRECTORY_SEPARATOR.'package.json')) {
            return $candidate;
        }

        return base_path();
    }
}
