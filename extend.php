<?php

/*
 * This file is part of ernestdefoe/janitor.
 *
 * Janitor for Flarum 2 — automated, rules-based discussion housekeeping.
 */

use ErnestDefoe\Janitor\Api\Controller;
use ErnestDefoe\Janitor\Console\RunJanitorCommand;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // ---- Admin JSON API (custom controllers, admin-gated) -------------------
    (new Extend\Routes('api'))
        ->get('/janitor/rules', 'janitor.rules.list', Controller\ListRulesController::class)
        ->post('/janitor/rules', 'janitor.rules.create', Controller\SaveRuleController::class)
        ->patch('/janitor/rules/{id}', 'janitor.rules.update', Controller\SaveRuleController::class)
        ->delete('/janitor/rules/{id}', 'janitor.rules.delete', Controller\DeleteRuleController::class)
        ->post('/janitor/rules/{id}/run', 'janitor.rules.run', Controller\RunRuleController::class)
        ->get('/janitor/log', 'janitor.log', Controller\ListLogController::class),

    // ---- The scheduled worker ----------------------------------------------
    // Registers `janitor:run` and runs it every 15 minutes. The command itself
    // decides which rules are actually DUE (per-rule frequency), so the fixed
    // cadence here is just the heartbeat. Requires the host to run Flarum's
    // scheduler: `* * * * * php /path/to/flarum schedule:run`.
    (new Extend\Console())
        ->command(RunJanitorCommand::class)
        ->schedule('janitor:run', function (Event $event) {
            $event->everyFifteenMinutes()->withoutOverlapping();
        }),
];
