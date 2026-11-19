<?php

namespace ErnestDefoe\TimeCapsule;

use RuntimeException;

/** Another request is creating the forum's first key right now (Vault): try again in a moment. */
class KeyBusy extends RuntimeException
{
}
