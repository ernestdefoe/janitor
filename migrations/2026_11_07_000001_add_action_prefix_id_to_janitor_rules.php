<?php

use Flarum\Database\Migration;

// The Rubric prefix a "set prefix" rule applies. No foreign key: Rubric is
// optional, and a rule whose prefix was deleted simply matches nothing.
return Migration::addColumns('janitor_rules', [
    'action_prefix_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
