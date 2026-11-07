<?php

namespace ErnestDefoe\Janitor\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Rubric thread prefixes: the has / lacks / no-prefix conditions and the set / remove actions. */
class RubricTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-rubric', 'ernestdefoe-janitor');

        $old = Carbon::now()->subDays(100);
        $prefixes = [null, 1, 2, null, 1, 3];
        $discussions = [];
        $pivot = [];

        // 1–5 in Sales, 6 in General. Prefixes: 1 Rumor, 2 Official, 3 Sold.
        foreach ($prefixes as $i => $prefix) {
            $id = $i + 1;
            $discussions[] = ['id' => $id, 'title' => "Thread $id", 'created_at' => $old, 'last_posted_at' => $old, 'user_id' => 1, 'comment_count' => 1, 'rubric_prefix_id' => $prefix];
            $pivot[] = ['discussion_id' => $id, 'tag_id' => $id === 6 ? 2 : 1];
        }

        $this->prepareDatabase([
            Tag::class => [
                ['id' => 1, 'name' => 'Sales', 'slug' => 'sales', 'position' => 0],
                ['id' => 2, 'name' => 'General', 'slug' => 'general', 'position' => 1],
            ],
            'rubric_prefixes' => [
                ['id' => 1, 'name' => 'Rumor', 'slug' => 'rumor', 'color' => '#7c3aed', 'position' => 0, 'tag_ids' => null],
                ['id' => 2, 'name' => 'Official', 'slug' => 'official', 'color' => '#0e7490', 'position' => 1, 'staff_only' => true, 'tag_ids' => null],
                // Only allowed in Sales.
                ['id' => 3, 'name' => 'Sold', 'slug' => 'sold', 'color' => '#b45309', 'position' => 2, 'tag_ids' => json_encode([1])],
            ],
            Discussion::class => $discussions,
            'discussion_tag' => $pivot,
        ]);
    }

    private function rule(array $conditions, string $action = 'hide', ?int $prefixId = null): int
    {
        return (int) $this->database()->table('janitor_rules')->insertGetId([
            'name' => 'Prefix rule', 'enabled' => true, 'scope_tag_ids' => json_encode([]), 'conditions' => json_encode($conditions),
            'action' => $action, 'action_tag_ids' => json_encode([]), 'action_prefix_id' => $prefixId, 'frequency' => 'daily',
        ]);
    }

    private function runRule(int $rule, bool $dry = false): array
    {
        $response = $this->send($this->request('POST', "/api/janitor/rules/$rule/run", ['authenticatedAs' => 1])->withQueryParams(['dry' => $dry ? '1' : '0']));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), json_encode($body));

        return $body['data'];
    }

    private function logged(): array
    {
        return $this->database()->table('janitor_log')->orderBy('discussion_id')->pluck('discussion_id')->map(fn ($id) => (int) $id)->all();
    }

    private function prefixes(): array
    {
        return $this->database()->table('discussions')->orderBy('id')->pluck('rubric_prefix_id', 'id')
            ->map(fn ($p) => $p === null ? null : (int) $p)->all();
    }

    #[Test]
    public function has_prefix_matches_any_of_the_chosen_prefixes()
    {
        $result = $this->runRule($this->rule(['hasPrefixIds' => [1, 3]]), dry: true);

        $this->assertSame(3, $result['matched']);
        $this->assertSame([2, 5, 6], $this->logged());
    }

    #[Test]
    public function lacks_prefix_matches_other_prefixes_and_unprefixed_discussions()
    {
        $this->runRule($this->rule(['lacksPrefixIds' => [1]]), dry: true);

        $this->assertSame([1, 3, 4, 6], $this->logged());
    }

    #[Test]
    public function no_prefix_matches_only_unprefixed_discussions()
    {
        $this->runRule($this->rule(['noPrefix' => true]), dry: true);

        $this->assertSame([1, 4], $this->logged());
    }

    #[Test]
    public function set_prefix_replaces_it_and_skips_tags_the_prefix_is_not_allowed_in()
    {
        $rule = $this->rule([], 'set_prefix', 3);

        $preview = $this->runRule($rule, dry: true);
        $this->assertSame(5, $preview['matched'], 'Sales discussions 1–5; 6 is in General');
        $this->assertSame([null, 1, 2, null, 1, 3], array_values($this->prefixes()), 'A preview changes nothing');

        $this->database()->table('janitor_log')->delete();
        $result = $this->runRule($rule);

        $this->assertSame(5, $result['applied']);
        // 6 already had Sold (it was seeded in General, where Sold is not
        // allowed, so the rule left it alone either way).
        $this->assertSame([1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3], $this->prefixes());
    }

    #[Test]
    public function set_prefix_never_assigns_a_prefix_outside_its_tags()
    {
        $this->database()->table('discussions')->where('id', 6)->update(['rubric_prefix_id' => null]);

        $result = $this->runRule($this->rule([], 'set_prefix', 3));

        $this->assertSame(5, $result['applied']);
        $this->assertNull($this->prefixes()[6], 'General does not allow Sold');
        $this->assertSame(0, $this->runRule($this->rule([], 'set_prefix', 3))['matched'], 'Nothing left to do on the next run');
    }

    #[Test]
    public function set_prefix_with_a_deleted_prefix_does_nothing()
    {
        $result = $this->runRule($this->rule([], 'set_prefix', 99));

        $this->assertSame(0, $result['matched']);
        $this->assertSame([null, 1, 2, null, 1, 3], array_values($this->prefixes()));
    }

    #[Test]
    public function remove_prefix_clears_only_the_chosen_prefixes()
    {
        $result = $this->runRule($this->rule(['hasPrefixIds' => [1]], 'remove_prefix'));

        $this->assertSame(2, $result['applied']);
        $this->assertSame([null, null, 2, null, null, 3], array_values($this->prefixes()));
    }

    #[Test]
    public function a_rule_saves_its_prefix_fields_cleaned()
    {
        $response = $this->send($this->request('POST', '/api/janitor/rules', ['authenticatedAs' => 1, 'json' => ['data' => [
            'name' => 'Mark sold', 'action' => 'set_prefix', 'action_prefix_id' => '3',
            'conditions' => ['hasPrefixIds' => ['1', 'x'], 'lacksPrefixIds' => [2], 'noPrefix' => 1],
        ]]]));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(201, $response->getStatusCode(), json_encode($body));
        $this->assertSame(3, $body['data']['action_prefix_id']);
        $this->assertSame([1], $body['data']['conditions']['hasPrefixIds']);
        $this->assertSame([2], $body['data']['conditions']['lacksPrefixIds']);
        $this->assertTrue($body['data']['conditions']['noPrefix']);
    }

    #[Test]
    public function the_queries_do_not_grow_with_the_number_of_discussions()
    {
        $count = function (int $discussions): int {
            $this->database()->table('discussion_tag')->where('discussion_id', '>', 6)->delete();
            $this->database()->table('discussions')->where('id', '>', 6)->delete();
            $old = Carbon::now()->subDays(100);
            for ($id = 7; $id < 7 + $discussions; $id++) {
                $this->database()->table('discussions')->insert(['id' => $id, 'title' => "Extra $id", 'slug' => "extra-$id", 'created_at' => $old, 'last_posted_at' => $old, 'user_id' => 1, 'comment_count' => 1, 'rubric_prefix_id' => 1]);
                $this->database()->table('discussion_tag')->insert(['discussion_id' => $id, 'tag_id' => 1]);
            }
            $this->database()->table('discussions')->where('id', '>', 6)->update(['rubric_prefix_id' => 1]);

            $this->database()->flushQueryLog();
            $this->database()->enableQueryLog();
            $this->runRule($this->rule(['hasPrefixIds' => [1]], 'set_prefix', 3));
            $log = array_column($this->database()->getQueryLog(), 'query');
            $this->database()->disableQueryLog();

            // Janitor's own work; the request's bookkeeping (a member's
            // last_seen_at, asset revisions) comes and goes on its own.
            return count(preg_grep('/janitor_|rubric_|discussion_tag|from "?\w*discussions"? where/', $log));
        };

        $this->assertSame($count(1), $count(20));
    }
}
