<?php

namespace Stomping\Protocol;

use Stomping\Error\StompFrameError;

/**
 * This is a parser for a wire-level byte-stream of STOMP frames.
 */
class StompParser
{
    public $version;

    protected $parsers = array();

    /** @var StompFrame[] Collection of all complete Frames parsed */
    protected $frames = array();

    /** @var string Input character buffer */
    protected $buffer = '';

    /** @var Callback Active parser callback */
    protected $parse;

    /** @var int Length of Frame body if Content-length header provided */
    protected $length = -1;

    /** @var StompFrame Frame being currently parsed */
    protected $frame;

    /** @var int Number of bytes parsed */
    protected $read = 0;

    /**
     * @param string $version A valid STOMP protocol version, or null (equivalent to the StompSpec::DEFAULT_VERSION).
     */
    public function __construct($version = null)
    {
        $this->version = $version ? : StompSpec::DEFAULT_VERSION;
        $this->parsers = array(
            'heart-beat' => array($this, 'parseHeartBeat'),
            'command' => array($this, 'parseCommand'),
            'headers' => array($this, 'parseHeader'),
            'body' => array($this, 'parseBody')
        );
        $this->reset();
    }

    /**
     * Indicates whether there are frames available.
     *
     * @return bool
     */
    public function canRead()
    {
        return !empty($this->frames);
    }

    /**
     * Return the next frame as a StompFrame object (if any), or null otherwise.
     *
     * @return StompFrame|null
     */
    public function get()
    {
        if ($this->canRead()) {
            return array_shift($this->frames);
        }

        return null;
    }

    /**
     * Add a byte-stream of wire-level data.
     *
     * @param string $data An iterable of characters. If any character evaluates to false, that stream will no longer be consumed.
     */
    public function add($data)
    {
        rewind($data);
        do {
            $c = fread($data, 1);
            if (false === $c || '' === $c) {
                return;
            }
            call_user_func($this->parse, $c);
        } while (false !== $c);
    }

    /**
     * Reset internal state, including all fully or partially parsed frames.
     */
    public function reset()
    {
        $this->frames = array();
        $this->next();
    }

    protected function flush()
    {
        $this->buffer = '';
    }

    protected function next()
    {
        $this->frame = new StompFrame();
        $this->length = -1;
        $this->read = 0;
        $this->transition('heart-beat');
    }

    protected function transition($state)
    {
        $this->flush();
        $this->parse = $this->parsers[$state];
    }

    protected function parseHeartBeat($char)
    {
        if ($char !== StompSpec::LINE_DELIMITER) {
            $this->transition('command');
            call_user_func($this->parse, $char);
            return;
        }

        if ($this->version !== StompSpec::VERSION_1_0) {
            $this->frames[] = new StompHeartBeat();
        }
    }

    protected function parseCommand($char)
    {
        if ($char !== StompSpec::LINE_DELIMITER) {
            $this->buffer .= $char;
            return;
        }

        $command = $this->buffer;

        if (empty($command)) {
            return;
        }

        $valid = StompSpec::commands($this->version);

        if (!isset($valid[$command])) {
            $this->flush();
            throw new StompFrameError("Invalid command: {$command}");
        }

        $this->frame->command = $command;
        $this->transition('headers');
    }

    protected function parseHeader($char)
    {
        if ($char !== StompSpec::LINE_DELIMITER) {
            $this->buffer .= $char;
            return;
        }

        $header = $this->buffer;

        if ($header) {
            list($name, $value) = explode(StompSpec::HEADER_SEPARATOR, $header, 2);

            if (is_null($value)) {
                throw new StompFrameError("No separator in header line: {$header}");
            }

            $this->frame->headers[$name] = $value;
            $this->transition('headers');
        } else {
            $this->length = isset($this->frame->headers[StompSpec::CONTENT_LENGTH_HEADER])
                ? (int)$this->frame->headers[StompSpec::CONTENT_LENGTH_HEADER]
                : -1;
            $this->transition('body');
        }
    }

    protected function parseBody($char)
    {
        $this->read++;

        if ($this->read <= $this->length || $char !== StompSpec::FRAME_DELIMITER) {
            $this->buffer .= $char;
            return;
        }

        $this->frame->body = $this->buffer;
        $this->frames[] = $this->frame;
        $this->next();
    }
}