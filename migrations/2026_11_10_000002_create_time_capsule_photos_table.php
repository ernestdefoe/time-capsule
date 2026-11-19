<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('time_capsule_photos', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('capsule_id');
    // An encrypted file under storage/time-capsule/photos, never in public/.
    $table->string('file', 64);
    $table->unsignedSmallInteger('width');
    $table->unsignedSmallInteger('height');
    $table->unsignedTinyInteger('position')->default(0);

    $table->index('capsule_id');
    $table->foreign('capsule_id')->references('id')->on('time_capsules')->cascadeOnDelete();
});
