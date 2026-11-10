<?php

namespace ErnestDefoe\TimeCapsule;

final class Settings
{
    public const VIEW = 'time-capsule.view';
    public const CREATE = 'time-capsule.create';
    public const MODERATE = 'time-capsule.moderate';
    public const COMMUNITY = 'time-capsule.community';

    public const NAV = 'time-capsule.nav';
    public const TAG = 'time-capsule.tag';
    public const MAX_YEARS = 'time-capsule.max_years';
    public const FOUNDED = 'time-capsule.founded';

    public const DEFAULT_MAX_YEARS = 25;
    public const MAX_LENGTH = 20000;
    public const MAX_ENVELOPE = 80;
    public const MAX_PHOTOS = 4;
    public const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
    /** Sealed at once, per member; and sealed per day. */
    public const MAX_SEALED = 100;
    public const MAX_PER_DAY = 10;
    /** The soonest a letter may open: it is a letter to the future. */
    public const MIN_HOURS = 20;
}
