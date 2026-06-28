<?php

namespace ErnestDefoe\Janitor;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A housekeeping rule: look inside some tags for discussions matching the
 * conditions, then apply an action — on its own frequency.
 *
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property array $scope_tag_ids
 * @property array $conditions
 * @property string $action
 * @property array $action_tag_ids
 * @property string $frequency
 * @property \Carbon\Carbon|null $last_run_at
 */
class Rule extends AbstractModel
{
    protected $table = 'janitor_rules';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'scope_tag_ids' => 'array',
        'conditions' => 'array',
        'action_tag_ids' => 'array',
        'last_run_at' => 'datetime',
    ];

    /** Whether enough time has passed since the last run for this rule's cadence. */
    public function isDue(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $last = $this->last_run_at;
        if (! $last) {
            return true;
        }

        return match ($this->frequency) {
            'every_run' => true,
            'hourly' => $last->lte(Carbon::now()->subHour()),
            'weekly' => $last->lte(Carbon::now()->subWeek()),
            default => $last->lte(Carbon::now()->subDay()), // daily
        };
    }
}
