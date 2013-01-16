<?php

namespace Stomping\Protocol;

use Stomping\Protocol\StompSpec;

/**
 * This object represents a STOMP heart-beat. Its string representation (via `__toString()`)
 * renders the wire-level STOMP heart-beat.
 */
class StompHeartBeat
{
    public function __toString()
    {
        return StompSpec::LINE_DELIMITER;
    }

    public function info()
    {
        return 'heart-beat';
    }
}