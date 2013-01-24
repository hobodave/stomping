<?php

namespace Stomping\Sync;

use Stomping\Error\StompConnectionError;
use Stomping\Protocol\StompFrame;
use Stomping\Protocol\StompParser;

class StompFrameTransport
{
    const READ_SIZE = 4096;

    public function __construct($host, $port, $version = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->version = $version;

        $this->_socket = null;
        $this->_parser = new StompParser($this->version);
    }

    public function connect($timeout = null)
    {
        $errno = null;
        $errstr = null;
        $timeout = is_null($timeout) ? ini_get('default_socket_timeout') : $timeout;
        $this->_socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $timeout);

        if (false === $this->_socket) {
            throw new StompConnectionError("Could not establish connection [{$errno}:{$errstr}]");
        }

        $this->_parser->reset();
    }

    public function canRead($timeout = null)
    {
        $this->_check();

        if ($this->_parser->canRead()) {
            return true;
        }

        $read = array($this->_socket);
        $write = array();
        $except = array();

        $count = stream_select($read, $write, $except, $timeout);

        if ($count === false) {
            // Error handling
        }

        return (bool) $count;
    }

    public function disconnect()
    {
        $success = stream_socket_shutdown($this->_socket, STREAM_SHUT_RDWR);
        $success = $success && fclose($this->_socket);
        $this->_socket = null;

        if (!$success) {
            throw new StompConnectionError("Could not close connection cleanly");
        }
    }

    public function send($frame)
    {
        $this->_write((string) $frame);
    }

    public function receive()
    {
        while (true) {
            $frame = $this->_parser->get();
            if (!is_null($frame)) {
                return $frame;
            }

            try {
                $stream = fopen('php://memory', 'r+b');
                $data = fread($this->_socket, self::READ_SIZE);
                if (false === $data || '' === $data) {
                    throw new StompConnectionError('No more data');
                }
            } catch (StompConnectionError $e) {
                $this->disconnect();
                throw new StompConnectionError("Connection closed [{$e}]");
            }
            fwrite($stream, $data);
            $this->_parser->add($stream);
        }
    }

    public function _check()
    {
        if (!$this->_connected()) {
            throw new StompConnectionError('Not connected');
        }
    }

    public function _connected()
    {
        return !is_null($this->_socket);
    }

    public function _write($data)
    {
        $this->_check();
        $success = fwrite($this->_socket, $data);
        if (false === $success) {
            throw new StompConnectionError('Could not send to connection');
        }
    }

    public function __toString()
    {
        return "{$this->host}:{$this->port}";
    }
}