<?php

namespace ErnestDefoe\Janitor;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rule engine. For each rule it builds a discussion query from the rule's
 * scope + conditions, then applies the action (or, in dry-run, only logs what it
 * WOULD do). Every (would-be) action is recorded to the janitor_log.
 *
 * Flarum 2 has no Laravel facades, so the DB connection + settings are injected.
 */
class Janitor
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /** Run every enabled rule that is due for its cadence. */
    public function runDueRules(bool $forceDry = false): array
    {
        $out = [];
        foreach (Rule::where('enabled', true)->get() as $rule) {
            if ($rule->isDue()) {
                $out[] = $this->runRule($rule, $forceDry || $this->globalDryRun());
            }
        }

        return $out;
    }

    /** Run a single rule now. Returns a summary the admin UI / command can show. */
    public function runRule(Rule $rule, bool $dry): array
    {
        $cap = max(1, (int) ($this->settings->get('ernestdefoe-janitor.cap') ?: 100));
        $matches = $this->query($rule)->limit($cap)->get();

        $applied = 0;
        foreach ($matches as $discussion) {
            if (! $dry) {
                $this->apply($rule, $discussion);
                $applied++;
            }
            $this->log($rule, $discussion, $dry);
        }

        $rule->last_run_at = Carbon::now();
        $rule->save();

        return [
            'rule' => $rule->name,
            'matched' => $matches->count(),
            'applied' => $applied,
            'dry' => $dry,
            'capped' => $matches->count() >= $cap,
        ];
    }

    public function globalDryRun(): bool
    {
        return (bool) $this->settings->get('ernestdefoe-janitor.dry_run');
    }

    /** Build the discussion query for a rule (scope + conditions + safety guards). */
    protected function query(Rule $rule): Builder
    {
        $schema = $this->db->getSchemaBuilder();
        $hasPivot = $schema->hasTable('discussion_tag');

        $q = Discussion::query()->where('is_private', false)->whereNull('hidden_at');

        // Scope: only discussions carrying one of the rule's tags (empty = all).
        $scope = array_filter((array) $rule->scope_tag_ids);
        if ($scope && $hasPivot) {
            $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('discussion_tag')
                ->whereColumn('discussion_tag.discussion_id', 'discussions.id')->whereIn('tag_id', $scope));
        }

        $c = (array) $rule->conditions;

        // Age — by last activity (default) or creation date.
        if (! empty($c['ageDays'])) {
            $col = ($c['ageBasis'] ?? 'last_post') === 'created' ? 'created_at' : 'last_posted_at';
            $q->where($col, '<=', Carbon::now()->subDays((int) $c['ageDays']));
        }

        if ($hasPivot) {
            foreach (array_filter((array) ($c['hasTagIds'] ?? [])) as $tid) {
                $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('discussion_tag')
                    ->whereColumn('discussion_tag.discussion_id', 'discussions.id')->where('tag_id', $tid));
            }
            $lacks = array_filter((array) ($c['lacksTagIds'] ?? []));
            if ($lacks) {
                $q->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('discussion_tag')
                    ->whereColumn('discussion_tag.discussion_id', 'discussions.id')->whereIn('tag_id', $lacks));
            }
        }

        // Replies = comment_count - 1 (the first post is a comment in Flarum).
        if (isset($c['minReplies']) && $c['minReplies'] !== '' && $c['minReplies'] !== null) {
            $q->where('comment_count', '>=', ((int) $c['minReplies']) + 1);
        }
        if (isset($c['maxReplies']) && $c['maxReplies'] !== '' && $c['maxReplies'] !== null) {
            $q->where('comment_count', '<=', ((int) $c['maxReplies']) + 1);
        }

        // Never touch stickied or locked discussions (when those extensions exist).
        if ($schema->hasColumn('discussions', 'is_sticky')) {
            $q->where(fn ($w) => $w->where('is_sticky', false)->orWhereNull('is_sticky'));
        }
        if ($schema->hasColumn('discussions', 'is_locked')) {
            $q->where(fn ($w) => $w->where('is_locked', false)->orWhereNull('is_locked'));
        }

        return $q->orderBy('id');
    }

    protected function apply(Rule $rule, Discussion $d): void
    {
        $schema = $this->db->getSchemaBuilder();

        switch ($rule->action) {
            case 'hide':
                $d->hide()->save();
                break;

            case 'delete':
                $tagIds = $this->discussionTagIds($d->id);
                $this->db->table('posts')->where('discussion_id', $d->id)->delete();
                if ($schema->hasTable('discussion_tag')) {
                    $this->db->table('discussion_tag')->where('discussion_id', $d->id)->delete();
                }
                $d->delete();
                $this->recount($tagIds);
                break;

            case 'lock':
            case 'unlock':
                if ($schema->hasColumn('discussions', 'is_locked')) {
                    $d->is_locked = $rule->action === 'lock';
                    $d->save();
                }
                break;

            case 'add_tag':
                $this->attach($d, (array) $rule->action_tag_ids);
                break;

            case 'remove_tag':
                $this->detach($d, (array) $rule->action_tag_ids);
                break;

            case 'move':
                $this->detach($d, (array) $rule->scope_tag_ids);
                $this->attach($d, (array) $rule->action_tag_ids);
                break;
        }
    }

    protected function attach(Discussion $d, array $tagIds): void
    {
        if (! $this->db->getSchemaBuilder()->hasTable('discussion_tag')) {
            return;
        }
        foreach (array_filter($tagIds) as $tid) {
            $this->db->table('discussion_tag')->updateOrInsert(
                ['discussion_id' => $d->id, 'tag_id' => (int) $tid],
                []
            );
        }
        $this->recount($tagIds);
    }

    protected function detach(Discussion $d, array $tagIds): void
    {
        $tagIds = array_filter($tagIds);
        if (! $tagIds || ! $this->db->getSchemaBuilder()->hasTable('discussion_tag')) {
            return;
        }
        $this->db->table('discussion_tag')->where('discussion_id', $d->id)->whereIn('tag_id', $tagIds)->delete();
        $this->recount($tagIds);
    }

    protected function discussionTagIds(int $discussionId): array
    {
        if (! $this->db->getSchemaBuilder()->hasTable('discussion_tag')) {
            return [];
        }

        return $this->db->table('discussion_tag')->where('discussion_id', $discussionId)->pluck('tag_id')->all();
    }

    /** Keep flarum/tags' cached discussion_count honest after retagging. */
    protected function recount(array $tagIds): void
    {
        $schema = $this->db->getSchemaBuilder();
        if (! $schema->hasTable('tags') || ! $schema->hasColumn('tags', 'discussion_count')) {
            return;
        }
        foreach (array_unique(array_filter($tagIds)) as $tid) {
            $count = $this->db->table('discussion_tag')
                ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
                ->where('discussion_tag.tag_id', (int) $tid)
                ->where('discussions.is_private', false)
                ->whereNull('discussions.hidden_at')
                ->count();
            $this->db->table('tags')->where('id', (int) $tid)->update(['discussion_count' => $count]);
        }
    }

    protected function log(Rule $rule, Discussion $d, bool $dry): void
    {
        LogEntry::create([
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'action' => $rule->action,
            'discussion_id' => $d->id,
            'discussion_title' => mb_substr((string) $d->title, 0, 250),
            'dry_run' => $dry,
            'created_at' => Carbon::now(),
        ]);
    }
}
