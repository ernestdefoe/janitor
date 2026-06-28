<?php

namespace ErnestDefoe\Janitor;

use Flarum\Database\AbstractModel;

/**
 * One recorded action (or would-be action, when dry-run) taken by a rule.
 *
 * @property int $id
 * @property int|null $rule_id
 * @property string $rule_name
 * @property string $action
 * @property int|null $discussion_id
 * @property string $discussion_title
 * @property bool $dry_run
 * @property \Carbon\Carbon|null $created_at
 */
class LogEntry extends AbstractModel
{
    protected $table = 'janitor_log';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'dry_run' => 'boolean',
        'created_at' => 'datetime',
    ];
}
