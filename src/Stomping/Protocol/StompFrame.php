<?php

namespace Stomping\Protocol;

use Stomping\Protocol\StompSpec;

/**
 * This object represents a STOMP frame which consists of a STOMP `command`, `headers`, and a message `body`.
 * Its string representation (via `__toString`) renders the wire-level STOMP frame.
 */
class StompFrame
{
    const INFO_LENGTH = 40;

    /** @var string */
    public $command = '';

    /** @var array */
    public $headers = array();

    /** @var string */
    public $body = '';

    public function __construct($command = '', $headers = array(), $body = '')
    {
        $this->command = $command;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function __toString()
    {
        $headers = '';

        foreach ($this->headers as $key => $value) {
            $headers .= sprintf("%s:%s%s", $key, $value, StompSpec::LINE_DELIMITER);
        }

        return implode(StompSpec::LINE_DELIMITER, array(
                $this->command, $headers, sprintf('%s%s', $this->body, StompSpec::FRAME_DELIMITER)
            ));
    }

    public function info()
    {
        $headers = str_replace("\n", '', sprintf("headers=%s", var_export($this->headers, true)));
        $body = substr($this->body, 0, self::INFO_LENGTH);

        if (false === strpos($this->body, $body)) {
            $body = sprintf("%s...", $body);
        }

        $body = str_replace("\n", '', sprintf("body=%s", var_export($body, true)));

        $info = implode(', ', array($headers, $body));

        return sprintf("%s frame%s", $this->command, sprintf(" [%s]", $info));
    }
}