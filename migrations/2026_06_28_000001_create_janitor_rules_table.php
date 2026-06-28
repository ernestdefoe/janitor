<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('janitor_rules', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name');
    $table->boolean('enabled')->default(true);
    // Tags the rule looks inside (JSON array of tag ids; empty = all tags).
    $table->text('scope_tag_ids')->nullable();
    // Match conditions (JSON): ageDays, ageBasis(last_post|created),
    // hasTagIds[], lacksTagIds[], minReplies, maxReplies.
    $table->text('conditions')->nullable();
    // hide | delete | lock | unlock | add_tag | remove_tag | move
    $table->string('action');
    // Target tags for add_tag / remove_tag / move (JSON array of tag ids).
    $table->text('action_tag_ids')->nullable();
    // every_run | hourly | daily | weekly
    $table->string('frequency')->default('daily');
    $table->timestamp('last_run_at')->nullable();
    $table->timestamps();
});
