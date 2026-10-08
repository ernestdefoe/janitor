<?php

namespace ErnestDefoe\Janitor;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Discussion\Event\Hidden;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
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
    protected ?User $actor = null;
    protected bool $actorResolved = false;

    /** The log keeps this many newest rows; the admin page shows the latest 100. */
    public const LOG_KEEP = 1000;

    /** Schema answers, asked once per process instead of once per discussion. */
    protected array $schemaCache = [];

    /** Tags whose discussion_count changed during the current rule run. */
    protected array $dirtyTags = [];

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events
    ) {
    }

    /**
     * A system actor (first admin) to attribute actions to, so audit-log
     * extensions — which hook the core domain events Janitor dispatches — record
     * a real user. Null is fine for Hidden/Deleted; the tag event needs it.
     */
    protected function actor(): ?User
    {
        if (! $this->actorResolved) {
            $this->actorResolved = true;
            $this->actor = User::query()
                ->whereHas('groups', fn ($q) => $q->where('id', Group::ADMINISTRATOR_ID))
                ->first();
        }

        return $this->actor;
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

    /**
     * Run a single rule now. Returns a summary the admin UI / command can show.
     * $touchSchedule=false keeps an on-demand PREVIEW from advancing
     * last_run_at — otherwise previewing a daily rule silently postpones its
     * next real scheduled run by a day.
     */
    public function runRule(Rule $rule, bool $dry, bool $touchSchedule = true): array
    {
        $cap = max(1, (int) ($this->settings->get('ernestdefoe-janitor.cap') ?: 100));
        $matches = $this->query($rule)->limit($cap)->get();

        $applied = 0;
        $this->dirtyTags = [];
        $log = [];
        try {
            foreach ($matches as $discussion) {
                if (! $dry) {
                    $this->apply($rule, $discussion);
                    $applied++;
                }
                $log[] = $this->logRow($rule, $discussion, $dry);
            }
        } finally {
            // One COUNT per touched tag for the whole run, not one per
            // discussion: a 100-discussion move used to recount the same tag
            // 100 times.
            $this->flushRecounts();
            // And one insert for the run's log, not one per discussion. What
            // was applied before a failure is still logged.
            if ($log) {
                LogEntry::query()->insert($log);
            }
            $this->pruneLog();
        }

        if ($touchSchedule) {
            $rule->last_run_at = Carbon::now();
            $rule->save();
        }

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

    /**
     * Build the discussion query for a rule (scope + conditions + safety guards).
     *
     * @return Builder<Discussion>
     */
    protected function query(Rule $rule): Builder
    {
        $hasPivot = $this->hasTable('discussion_tag');

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
        if (isset($c['minReplies']) && $c['minReplies'] !== '') {
            $q->where('comment_count', '>=', ((int) $c['minReplies']) + 1);
        }
        if (isset($c['maxReplies']) && $c['maxReplies'] !== '') {
            $q->where('comment_count', '<=', ((int) $c['maxReplies']) + 1);
        }

        // Stickied and locked discussions are protected by default, but a rule
        // can opt in per flag — e.g. archiving closed-and-locked sale threads,
        // which the blanket guard used to silently exclude. The `unlock`
        // action always includes locked discussions: with the guard applied it
        // could never match the very threads it exists to unlock.
        $includeSticky = ! empty($c['includeSticky']);
        $includeLocked = ! empty($c['includeLocked']) || $rule->action === 'unlock';
        if (! $includeSticky && $this->hasColumn('discussions', 'is_sticky')) {
            $q->where(fn ($w) => $w->where('is_sticky', false)->orWhereNull('is_sticky'));
        }
        if (! $includeLocked && $this->hasColumn('discussions', 'is_locked')) {
            $q->where(fn ($w) => $w->where('is_locked', false)->orWhereNull('is_locked'));
        }

        return $q->orderBy('id');
    }

    protected function apply(Rule $rule, Discussion $d): void
    {
        $actor = $this->actor();

        switch ($rule->action) {
            case 'hide':
                $d->hide($actor)->save();
                $this->events->dispatch(new Hidden($d, $actor));
                break;

            case 'delete':
                $tagIds = $this->discussionTagIds($d->id);
                $this->db->table('posts')->where('discussion_id', $d->id)->delete();
                if ($this->hasTable('discussion_tag')) {
                    $this->db->table('discussion_tag')->where('discussion_id', $d->id)->delete();
                }
                $d->delete();
                $this->events->dispatch(new Deleted($d, $actor));
                $this->recount($tagIds);
                break;

            case 'lock':
            case 'unlock':
                if ($this->hasColumn('discussions', 'is_locked')) {
                    // flarum/lock's column, so set as an attribute.
                    $d->setAttribute('is_locked', $rule->action === 'lock');
                    $d->save();
                    $this->dispatchExt(
                        'Flarum\\Lock\\Event\\DiscussionWas' . ($rule->action === 'lock' ? 'Locked' : 'Unlocked'),
                        fn ($cls) => $actor ? new $cls($d, $actor) : null
                    );
                }
                break;

            case 'add_tag':
                $old = $this->discussionTagIds($d->id);
                $this->attach($d, (array) $rule->action_tag_ids);
                $this->dispatchTagged($d, $old, $actor);
                break;

            case 'remove_tag':
                $old = $this->discussionTagIds($d->id);
                $this->detach($d, (array) $rule->action_tag_ids);
                $this->dispatchTagged($d, $old, $actor);
                break;

            case 'move':
                $old = $this->discussionTagIds($d->id);
                $this->detach($d, (array) $rule->scope_tag_ids);
                $this->attach($d, (array) $rule->action_tag_ids);
                $this->dispatchTagged($d, $old, $actor);
                break;
        }
    }

    /** Fire flarum/tags' DiscussionWasTagged so audit logs record retag/move. */
    protected function dispatchTagged(Discussion $d, array $oldTagIds, ?User $actor): void
    {
        $tagCls = 'Flarum\\Tags\\Tag';
        if (! $actor || ! class_exists($tagCls)) {
            return;
        }
        $this->dispatchExt('Flarum\\Tags\\Event\\DiscussionWasTagged', function ($cls) use ($d, $actor, $oldTagIds, $tagCls) {
            $old = $tagCls::query()->whereIn('id', array_filter($oldTagIds))->get()->all();

            return new $cls($d, $actor, $old);
        });
    }

    /** Dispatch an optional-extension event if its class exists; never throw. */
    protected function dispatchExt(string $class, callable $make): void
    {
        if (! class_exists($class)) {
            return;
        }
        try {
            if ($event = $make($class)) {
                $this->events->dispatch($event);
            }
        } catch (\Throwable $e) {
            // Audit integration is best-effort and must never break the action.
        }
    }

    protected function attach(Discussion $d, array $tagIds): void
    {
        if (! $this->hasTable('discussion_tag')) {
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
        if (! $tagIds || ! $this->hasTable('discussion_tag')) {
            return;
        }
        $this->db->table('discussion_tag')->where('discussion_id', $d->id)->whereIn('tag_id', $tagIds)->delete();
        $this->recount($tagIds);
    }

    protected function discussionTagIds(int $discussionId): array
    {
        if (! $this->hasTable('discussion_tag')) {
            return [];
        }

        return $this->db->table('discussion_tag')->where('discussion_id', $discussionId)->pluck('tag_id')->all();
    }

    /** Mark tags whose cached discussion_count must be recomputed after the run. */
    protected function recount(array $tagIds): void
    {
        foreach (array_filter($tagIds) as $tid) {
            $this->dirtyTags[(int) $tid] = true;
        }
    }

    /** Keep flarum/tags' cached discussion_count honest after retagging. */
    protected function flushRecounts(): void
    {
        $tagIds = array_keys($this->dirtyTags);
        $this->dirtyTags = [];

        if (! $tagIds || ! $this->hasTable('tags') || ! $this->hasColumn('tags', 'discussion_count')) {
            return;
        }
        foreach ($tagIds as $tid) {
            $count = $this->db->table('discussion_tag')
                ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
                ->where('discussion_tag.tag_id', (int) $tid)
                ->where('discussions.is_private', false)
                ->whereNull('discussions.hidden_at')
                ->count();
            $this->db->table('tags')->where('id', (int) $tid)->update(['discussion_count' => $count]);
        }
    }

    /**
     * Keep the log bounded. A dry-run rule set to "every run" logs up to the
     * per-run cap every fifteen minutes, so an unpruned table grows by
     * thousands of rows a day for as long as the forum exists. Only the newest
     * 100 are ever shown; the newest LOG_KEEP are kept.
     */
    protected function pruneLog(): void
    {
        $floor = LogEntry::query()->orderByDesc('id')->skip(self::LOG_KEEP - 1)->value('id');

        if ($floor !== null) {
            LogEntry::query()->where('id', '<', $floor)->delete();
        }
    }

    protected function hasTable(string $table): bool
    {
        return $this->schemaCache['t:'.$table] ??= $this->db->getSchemaBuilder()->hasTable($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return $this->schemaCache['c:'.$table.'.'.$column] ??= $this->db->getSchemaBuilder()->hasColumn($table, $column);
    }

    /** @return array<string, mixed> one janitor_log row */
    protected function logRow(Rule $rule, Discussion $d, bool $dry): array
    {
        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'action' => $rule->action,
            'discussion_id' => $d->id,
            'discussion_title' => mb_substr((string) $d->title, 0, 250),
            'dry_run' => $dry,
            'created_at' => Carbon::now(),
        ];
    }
}
