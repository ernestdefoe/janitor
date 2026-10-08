<?php

namespace ErnestDefoe\Janitor\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\Test;

class RunJanitorTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-janitor');

        $old = Carbon::now()->subDays(100);

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Old', 'created_at' => $old, 'last_posted_at' => $old, 'user_id' => 1, 'comment_count' => 1],
            ],
            'janitor_rules' => [
                // Due: never run. Not due: ran an hour ago, daily. Disabled.
                ['id' => 1, 'name' => 'Due', 'enabled' => true, 'conditions' => json_encode(['ageDays' => 30]), 'action' => 'lock', 'frequency' => 'daily'],
                ['id' => 2, 'name' => 'Not due', 'enabled' => true, 'conditions' => json_encode(['ageDays' => 30]), 'action' => 'hide', 'frequency' => 'daily', 'last_run_at' => Carbon::now()->subHour()],
                ['id' => 3, 'name' => 'Off', 'enabled' => false, 'conditions' => json_encode(['ageDays' => 30]), 'action' => 'delete', 'frequency' => 'every_run'],
            ],
        ]);
    }

    #[Test]
    public function only_enabled_rules_that_are_due_run()
    {
        $output = $this->runCommand(['command' => 'janitor:run']);

        $this->assertStringContainsString('Janitor [Due]', $output);
        $this->assertStringNotContainsString('Not due', $output);
        $this->assertStringNotContainsString('Off', $output);
        $this->assertNull($this->database()->table('discussions')->where('id', 1)->value('hidden_at'), 'The hide rule was not due');
        $this->assertNotNull($this->database()->table('discussions')->where('id', 1)->first(), 'The delete rule is disabled');
    }

    #[Test]
    public function the_global_dry_run_takes_no_action()
    {
        $this->setting('ernestdefoe-janitor.dry_run', '1');
        $this->database()->table('janitor_rules')->where('id', 2)->update(['last_run_at' => null]);

        $output = $this->runCommand(['command' => 'janitor:run']);

        $this->assertStringContainsString('Janitor [Not due]: would apply to 1 discussion(s)', $output);
        $this->assertNull($this->database()->table('discussions')->where('id', 1)->value('hidden_at'));
    }
}
