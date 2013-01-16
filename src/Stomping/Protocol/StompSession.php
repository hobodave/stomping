<?php

namespace Stomping\Protocol;

use Stomping\Protocol\Commands;
use Stomping\Error\StompProtocolError;

class StompSession
{
    const CONNECTING = 'connecting';
    const CONNECTED = 'connected';
    const DISCONNECTING = 'disconnecting';
    const DISCONNECTED = 'disconnected';

    protected $_id;
    protected $_server;
    protected $_state = self::DISCONNECTED;
    protected $_lastSent;
    protected $_lastReceived;
    protected $_clientHeartBeat;
    protected $_serverHeartBeat;
    private $_version;
    private $__version;
    private $__versions = array();

    protected $_check;
    protected $_nextSubscription;

    protected $_receipts;
    protected $_transactions;
    protected $_subscriptions;

    public function __construct($version = null, $check = true)
    {
        $this->version = $version;
        $this->_check = $check;
        $this->_nextSubscription = -1;
        $this->_reset();
        $this->_flush();
    }

    public function connect($login = null, $passcode = null, $headers = null, $versions = null, $host = null, $heartBeats = null)
    {
        $this->_check('connect', array(self::DISCONNECTED));
        $this->_versions = $versions;
        $frame = Commands::connect($login, $passcode, $headers, $this->_versions, $host, $heartBeats);
        $this->_state = self::CONNECTING;

        return $frame;
    }

    public function disconnect($receipt = null)
    {
        $this->_check('disconnect', array(self::CONNECTED));
        $frame = Commands::disconnect($receipt);
        $this->_receipt($receipt);
        $this->_state = self::DISCONNECTING;

        return $frame;
    }

    public function close($flush = true)
    {
        $this->_reset();
        if ($flush) {
            $this->_flush();
        }
    }

    public function send($destination, $body = '', $headers = null, $receipt = null)
    {
        $this->_check('send', array(self::CONNECTED));
        $frame = Commands::send($destination, $body, $headers, $receipt);
        $this->_receipt($receipt);

        return $frame;
    }

    public function subscribe($destination, $headers = null, $receipt = null, $context = null)
    {
        $this->_check('subscribe', array(self::CONNECTED));
        list($frame, $token) = Commands::subscribe($destination, $headers, $receipt, $this->version);

        $tokenKey = json_encode($token);
        if (isset($this->_subscriptions[$tokenKey])) {
            throw new StompProtocolError(sprintf(
                'Already subscribed [%s=%s]',
                key($token), current($token)
            ));
        }

        $this->_receipt($receipt);
        $this->_subscriptions[$tokenKey] = array(
            ++$this->_nextSubscription,
            $destination,
            $headers,
            $receipt,
            $context
        );

        return array($frame, $token);
    }

    public function unsubscribe($token, $receipt = null)
    {
        $this->_check('unsubscribe', array(self::CONNECTED));
        $frame = Commands::unsubscribe($token, $receipt, $this->version);

        $tokenKey = json_encode($token);
        if (isset($this->_subscriptions[$tokenKey])) {
            unset($this->_subscriptions[$tokenKey]);
        } else {
            throw new StompProtocolError(sprintf(
                'No such subscription [%s=%s]',
                key($token), current($token)
            ));
        }

        $this->_receipt($receipt);

        return array($frame, $token);
    }

    public function ack(StompFrame $frame, $receipt = null)
    {
        $this->_check('ack', array(self::CONNECTED));
        $frame = Commands::ack($frame, $this->_transactions, $receipt, $this->version);
        $this->_receipt($receipt);

        return $frame;
    }

    public function nack(StompFrame $frame, $receipt = null)
    {
        $this->_check('nack', array(self::CONNECTED));
        $frame = Commands::nack($frame, $this->_transactions, $receipt, $this->version);
        $this->_receipt($receipt);

        return $frame;
    }

    public function transaction($transaction = null)
    {
        return $transaction ?: uniqid('', true);
    }

    public function begin($transaction = null, $receipt = null)
    {
        $this->_check('begin', array(self::CONNECTED));
        $frame = Commands::begin($transaction, $receipt);
        if (isset($this->_transactions[$transaction])) {
            throw new StompProtocolError("Transaction already active: {$transaction}");
        }
        $this->_transactions[$transaction] = true;
        $this->_receipt($receipt);

        return $frame;
    }

    public function abort($transaction, $receipt = null)
    {
        $this->_check('abort', array(self::CONNECTED));
        $frame = Commands::abort($transaction, $receipt);

        if (!isset($this->_transactions[$transaction])) {
            throw new StompProtocolError("Transaction unknown: {$transaction}");
        }

        unset($this->_transactions[$transaction]);
        $this->_receipt($receipt);

        return $frame;
    }

    public function commit($transaction, $receipt = null)
    {
        $this->_check('commit', array(self::CONNECTED));
        $frame = Commands::commit($transaction, $receipt);

        if (!isset($this->_transactions[$transaction])) {
            throw new StompProtocolError("Transaction unknown: {$transaction}");
        }

        unset($this->_transactions[$transaction]);
        $this->_receipt($receipt);

        return $frame;
    }

    public function connected($frame)
    {
        $this->_check('connected', array(self::CONNECTING));
        try {
            list($this->version, $this->_server, $this->_id, $heartBeats) = Commands::connected($frame, $this->_versions);
            list($this->_serverHeartBeat, $this->_clientHeartBeat) = $heartBeats;
        } catch (\Exception $e) {
            $this->_versions = null;
            throw $e;
        }
        $this->_state = self::CONNECTED;
    }

    public function message($frame)
    {
        $this->_check('message', array(self::CONNECTED));
        $token = Commands::message($frame, $this->version);
        $tokenKey = json_encode($token);
        if (!isset($this->_subscriptions[$tokenKey])) {
            throw new StompProtocolError(sprintf(
                'No such subscription [%s=%s]',
                key($token), current($token)
            ));
        }

        return $token;
    }

    public function receipt($frame)
    {
        $this->_check('receipt', array(self::CONNECTED, self::DISCONNECTING));
        $receipt = Commands::receipt($frame, $this->version);

        if (!isset($this->_receipts[$receipt])) {
            throw new StompProtocolError("Unexpected receipt: {$receipt}");
        }

        unset($this->_receipts[$receipt]);

        return $receipt;
    }

    public function beat()
    {
        return Commands::beat($this->version);
    }

    public function sent()
    {
        $this->_lastSent = microtime(true);
    }

    public function received()
    {
        $this->_lastReceived = microtime(true);
    }

    public function replay()
    {
        $subscriptions = $this->_subscriptions;
        $this->_flush();
        usort($subscriptions, function($a, $b) {
                if ($a[0] == $b[0]) return 0;
                return ($a[0] < $b[0]) ? -1 : 1;
            });

        array_walk($subscriptions, function(&$value, $key) { $value = array_slice($value, 1); });

        return $subscriptions;
    }

    public function __get($name)
    {
        switch ($name) {
            case 'version':
                return $this->getVersion();
            case '_versions';
                return $this->getVersions();
            case 'lastSent':
                return $this->_lastSent;
            case 'lastReceived':
                return $this->_lastReceived;
            case 'clientHeartBeat':
                return $this->_clientHeartBeat;
            case 'serverHeartBeat':
                return $this->_serverHeartBeat;
            case 'id':
                return $this->_id;
            case 'server':
                return $this->_server;
            case 'state':
                return $this->_state;
            default:
                throw new \OutOfRangeException();
        }
    }

    public function __set($name, $value)
    {
        switch ($name) {
            case 'version':
                $this->setVersion($value);
                break;
            case '_versions':
                $this->setVersions($value);
                break;
            default:
                throw new \OutOfRangeException();
        }
    }

    protected function getVersion()
    {
        return $this->_version ?: $this->__version;
    }

    protected function setVersion($version)
    {
        $version = Commands::version($version);

        if (is_null($this->__version)) {
            $this->__version = $version;
            $version = null;
        }

        $this->_version = $version;
    }

    protected function getVersions()
    {
        $versions = count($this->__versions)
            ? $this->__versions
            : Commands::versions($this->version);
        sort($versions);

        return $versions;
    }

    protected function setVersions($versions)
    {
        if ($versions && count(array_diff($versions, Commands::versions($this->version)))) {
            throw new StompProtocolError(sprintf(
                'Invalid versions: %s [version=%s]',
                implode(',', $versions),
                $this->version
            ));
        }

        $this->__versions = $versions;
    }

    protected function _flush()
    {
        $this->_receipts = array();
        $this->_subscriptions = array();
        $this->_transactions = array();
    }

    protected function _receipt($receipt)
    {
        if (!$receipt) {
            return;
        }

        if (isset($this->_receipts[$receipt])) {
            throw new StompProtocolError("Duplicate receipt: {$receipt}");
        }

        $this->_receipts[$receipt] = true;
    }

    protected function _reset()
    {
        $this->_id = null;
        $this->_server = null;
        $this->_state = self::DISCONNECTED;
        $this->_lastSent = $this->_lastReceived = null;
        $this->_clientHeartBeat = $this->_serverHeartBeat = 0;
        $this->version = $this->__version;
        $this->_versions = array();
    }

    private function _check($command, $states)
    {
        if ($this->_check && !in_array($this->_state, $states)) {
            throw new StompProtocolError(sprintf(
                'Cannot handle command %s in state %s (only in states %s)',
                $command, $this->_state, implode(', ', $states)
            ));
        }
    }
}