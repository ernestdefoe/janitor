<?php

namespace ErnestDefoe\Janitor\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class JanitorTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-janitor');

        $old = Carbon::now()->subDays(100);
        $discussions = [];
        $pivot = [];

        // 1–6: old, quiet, in the Sales tag. 7: old but busy. 8: recent.
        // 9: old but in General. 10: old but private. 11: old but hidden.
        for ($id = 1; $id <= 11; $id++) {
            $discussions[] = ['id' => $id, 'title' => "Thread $id", 'created_at' => $old, 'last_posted_at' => $old, 'user_id' => 2, 'comment_count' => 1];
            $pivot[] = ['discussion_id' => $id, 'tag_id' => $id === 9 ? 2 : 1];
        }
        $discussions[6]['comment_count'] = 20;
        $discussions[7]['last_posted_at'] = Carbon::now();
        $discussions[9]['is_private'] = true;
        $discussions[10]['hidden_at'] = $old;

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Tag::class => [
                ['id' => 1, 'name' => 'Sales', 'slug' => 'sales', 'position' => 0, 'discussion_count' => 10],
                ['id' => 2, 'name' => 'General', 'slug' => 'general', 'position' => 1, 'discussion_count' => 1],
            ],
            Discussion::class => $discussions,
            'discussion_tag' => $pivot,
            'janitor_rules' => [
                ['id' => 1, 'name' => 'Archive old sales', 'enabled' => true, 'scope_tag_ids' => json_encode([1]), 'conditions' => json_encode(['ageDays' => 30, 'ageBasis' => 'last_post', 'maxReplies' => 5]), 'action' => 'hide', 'action_tag_ids' => json_encode([]), 'frequency' => 'daily'],
            ],
        ]);
    }

    /**
     * Applying a rule goes through each discussion's model and its domain
     * event (Hidden, Deleted, …), so flarum/tags and any other listener keep
     * their counts and indexes right: one write per discussion, plus what
     * those listeners do for it, by design. A run is capped (100 by default).
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['hidden_user_id', 'pivot_discussion_id', 'discussion_count'];
    }

    public static function adminRoutes(): array
    {
        return [
            'list rules' => ['GET', '/api/janitor/rules'],
            'create rule' => ['POST', '/api/janitor/rules'],
            'update rule' => ['PATCH', '/api/janitor/rules/1'],
            'delete rule' => ['DELETE', '/api/janitor/rules/1'],
            'run rule' => ['POST', '/api/janitor/rules/1/run'],
            'log' => ['GET', '/api/janitor/log'],
        ];
    }

    private function call(string $method, string $path, ?int $actor = null, array $json = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];

        if ($json) {
            $options['json'] = $json;
        }

        $request = $this->request($method, $path, $options);

        // A guest's write is refused for its missing CSRF token before the
        // controller runs, which would hide whether the controller checks.
        if (! $actor && $method !== 'GET') {
            $request = $this->requestWithCsrfToken($request);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function hidden(): array
    {
        return $this->database()->table('discussions')->whereNotNull('hidden_at')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function only_an_admin_reaches_janitor(string $method, string $path)
    {
        [$status] = $this->call($method, $path);
        $this->assertSame(403, $status, 'A guest');

        [$status] = $this->call($method, $path, 2, ['name' => 'Mine']);
        $this->assertSame(403, $status, 'A member');

        $this->assertSame('Archive old sales', $this->database()->table('janitor_rules')->where('id', 1)->value('name'));
        $this->assertSame([11], $this->hidden(), 'Nothing ran');
    }

    #[Test]
    public function an_admin_saves_a_rule_with_its_input_cleaned()
    {
        [$status, $body] = $this->call('POST', '/api/janitor/rules', 1, ['data' => [
            'name' => '  Tidy  ', 'action' => 'explode', 'frequency' => 'yearly', 'scope_tag_ids' => ['1', 'x', 0],
            'conditions' => ['ageDays' => '-5', 'minReplies' => '', 'ageBasis' => 'nonsense'],
        ]]);

        $this->assertSame(201, $status, json_encode($body));
        $this->assertSame('Tidy', $body['data']['name']);
        $this->assertSame('hide', $body['data']['action'], 'An unknown action falls back to hide');
        $this->assertSame('daily', $body['data']['frequency']);
        $this->assertSame([1], $body['data']['scope_tag_ids']);
        $this->assertSame(0, $body['data']['conditions']['ageDays']);
        $this->assertArrayNotHasKey('minReplies', $body['data']['conditions']);
        $this->assertSame('last_post', $body['data']['conditions']['ageBasis']);

        [$status] = $this->call('PATCH', '/api/janitor/rules/1', 1, ['data' => ['name' => 'Renamed', 'action' => 'lock']]);
        $this->assertSame(200, $status);
        $this->assertSame('lock', $this->database()->table('janitor_rules')->where('id', 1)->value('action'));

        [$status, $body] = $this->call('GET', '/api/janitor/rules', 1);
        $this->assertSame(200, $status);
        $this->assertCount(2, $body['data']);

        [$status] = $this->call('DELETE', '/api/janitor/rules/1', 1);
        $this->assertContains($status, [200, 204]);
        $this->assertSame(1, $this->database()->table('janitor_rules')->count());
    }

    #[Test]
    public function a_dry_run_logs_what_it_would_do_and_changes_nothing()
    {
        $response = $this->send($this->request('POST', '/api/janitor/rules/1/run', ['authenticatedAs' => 1])->withQueryParams(['dry' => '1']));
        [$status, $body] = [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(6, $body['data']['matched']);
        $this->assertSame(0, $body['data']['applied']);
        $this->assertSame([11], $this->hidden());
        $this->assertNull($this->database()->table('janitor_rules')->where('id', 1)->value('last_run_at'), 'A preview does not move the schedule');

        [, $body] = $this->call('GET', '/api/janitor/log', 1);
        $this->assertCount(6, $body['data']);
        $this->assertTrue((bool) $body['data'][0]['dry_run']);
    }

    #[Test]
    public function a_run_touches_only_what_the_rule_matches()
    {
        [$status, $body] = $this->call('POST', '/api/janitor/rules/1/run', 1);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(6, $body['data']['applied']);
        // Not 7 (busy), 8 (recent), 9 (other tag); 10 stays private and 11
        // was hidden already.
        $this->assertSame([1, 2, 3, 4, 5, 6, 11], $this->hidden());
        $this->assertNotNull($this->database()->table('janitor_rules')->where('id', 1)->value('last_run_at'));
    }

    #[Test]
    public function a_run_stops_at_the_cap()
    {
        $this->setting('ernestdefoe-janitor.cap', 4);

        [, $body] = $this->call('POST', '/api/janitor/rules/1/run', 1);

        $this->assertSame(4, $body['data']['applied']);
        $this->assertTrue($body['data']['capped']);
        $this->assertSame([1, 2, 3, 4, 11], $this->hidden());
    }
}
