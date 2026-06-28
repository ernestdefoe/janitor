<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('janitor_log', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('rule_id')->nullable();
    $table->string('rule_name');
    $table->string('action');
    $table->unsignedInteger('discussion_id')->nullable();
    $table->string('discussion_title')->default('');
    $table->boolean('dry_run')->default(false);
    $table->timestamp('created_at')->nullable();

    $table->index('created_at');
});
