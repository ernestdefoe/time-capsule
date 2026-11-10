<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('time_capsules', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('community_id')->nullable();
            $table->string('envelope', 80)->nullable();
            // The letter, encrypted (Vault). Never plain text in the database.
            $table->mediumText('sealed_body');
            $table->unsignedInteger('words')->default(0);
            $table->unsignedTinyInteger('photo_count')->default(0);
            $table->dateTime('opens_at');
            $table->dateTime('sealed_at');
            $table->dateTime('opened_at')->nullable();
            $table->string('visibility', 10)->default('private');
            $table->boolean('author_can_read')->default(false);
            $table->boolean('post_to_discussion')->default(false);
            $table->unsignedInteger('discussion_id')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->unsignedTinyInteger('post_attempts')->default(0);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('read_at')->nullable();

            $table->index(['opened_at', 'opens_at']);
            $table->index(['user_id', 'opened_at']);
            $table->index(['community_id', 'opened_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('community_id')->references('id')->on('time_capsule_communities')->cascadeOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('time_capsules');
    },
];
