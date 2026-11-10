<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $capsule_id
 * @property string $file
 * @property int $width
 * @property int $height
 * @property int $position
 */
class Photo extends AbstractModel
{
    protected $table = 'time_capsule_photos';

    public $timestamps = false;

    protected $hidden = ['file'];
}
