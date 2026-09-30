<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MakeAgentSkillCommandTest extends TestCase
{
    public function test_it_scaffolds_cursor_and_boost_skill_files(): void
    {
        if (! is_dir(dirname(base_path(), 2).DIRECTORY_SEPARATOR.'.cursor')) {
            $this->markTestSkipped('Editor (.cursor) integration is not part of generated workspaces.');
        }

        $name = 'tmp-platform-skill-test';
        $boost = base_path('.ai/skills/'.$name.'/SKILL.md');
        $cursor = dirname(base_path(), 2).DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'SKILL.md';

        try {
            $this->artisan('make:agent-skill', [
                'name' => $name,
                '--description' => 'Temporary skill used by the test suite.',
            ])->assertSuccessful();

            $this->assertFileExists($boost);
            $this->assertFileExists($cursor);
            $this->assertStringContainsString('name: tmp-platform-skill-test', File::get($boost));
            $this->assertStringContainsString('Temporary skill used by the test suite.', File::get($cursor));
        } finally {
            File::deleteDirectory(dirname($boost));
            File::deleteDirectory(dirname($cursor));
        }
    }
}
