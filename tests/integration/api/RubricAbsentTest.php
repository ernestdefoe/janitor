<?php

namespace ErnestDefoe\Janitor\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Without Rubric a prefix rule matches nothing, and every other rule is unaffected. */
class RubricAbsentTest extends TestCase
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
                ['id' => 1, 'name' => 'Unprefixed', 'enabled' => true, 'conditions' => json_encode(['noPrefix' => true]), 'action' => 'hide', 'frequency' => 'daily'],
                ['id' => 2, 'name' => 'Lacks', 'enabled' => true, 'conditions' => json_encode(['lacksPrefixIds' => [1]]), 'action' => 'hide', 'frequency' => 'daily'],
                ['id' => 3, 'name' => 'Set', 'enabled' => true, 'conditions' => json_encode([]), 'action' => 'set_prefix', 'action_prefix_id' => 1, 'frequency' => 'daily'],
                ['id' => 4, 'name' => 'Remove', 'enabled' => true, 'conditions' => json_encode([]), 'action' => 'remove_prefix', 'frequency' => 'daily'],
                ['id' => 5, 'name' => 'Plain', 'enabled' => true, 'conditions' => json_encode(['ageDays' => 30]), 'action' => 'lock', 'frequency' => 'daily'],
            ],
        ]);
    }

    private function runRule(int $rule): array
    {
        $response = $this->send($this->request('POST', "/api/janitor/rules/$rule/run", ['authenticatedAs' => 1]));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    #[Test]
    public function prefix_rules_match_nothing_without_rubric()
    {
        foreach ([1, 2, 3, 4] as $rule) {
            $this->assertSame(0, $this->runRule($rule)['matched'], "Rule $rule");
        }

        $this->assertNull($this->database()->table('discussions')->where('id', 1)->value('hidden_at'));
    }

    #[Test]
    public function other_rules_still_run_without_rubric()
    {
        $this->assertSame(1, $this->runRule(5)['applied']);
    }
}
