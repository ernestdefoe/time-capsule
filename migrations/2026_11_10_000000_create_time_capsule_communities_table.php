<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('time_capsule_communities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->dateTime('opens_at');
            $table->dateTime('opened_at')->nullable();
            $table->boolean('post_to_discussion')->default(false);
            $table->boolean('notify_everyone')->default(true);
            $table->unsignedInteger('discussion_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedTinyInteger('post_attempts')->default(0);
            // Notifications go out in chunks by user id; the cursor makes a crashed run resume, never repeat.
            $table->unsignedInteger('notify_cursor')->default(0);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['opened_at', 'opens_at']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('time_capsule_communities');
    },
];
