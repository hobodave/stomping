<?php

namespace Stomping\Protocol;

use ArrayIterator;
use IteratorAggregate;
use Stomping\Error\StompConnectTimeout;

class StompFailoverTransport implements IteratorAggregate
{
    /** @var StompFailoverUri */
    protected $_failoverUri;
    /** @var int */
    protected $_maxReconnectAttempts;
    /** @var int */
    protected $_reconnectAttempts = -1;
    /** @var int */
    protected $_reconnectDelay;

    public function __construct($uri)
    {
        $this->_failoverUri = new StompFailoverUri($uri);
        $this->_maxReconnectAttempts = null;
    }

    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Retrieve an external iterator
     * @link http://php.net/manual/en/iteratoraggregate.getiterator.php
     * @return \Traversable An instance of an object implementing <b>Iterator</b> or
     * <b>Traversable</b>
     */
    public function getIterator()
    {
        $this->_reset();
        $ret = array();
        foreach ($this->_brokers() as $b) {
            $ret[] = array($b, $this->_delay());
        }

        return new ArrayIterator($ret);
    }


    protected function _brokers()
    {
        $failoverUri = $this->_failoverUri;
        $options = $failoverUri->options;
        $brokers = $failoverUri->brokers;

        if ($options['randomize']) {
            shuffle($brokers);
        }

        if ($options['priorityBackup']) {
            usort($brokers, function($a, $b) {
                    $local = StompFailoverUri::localHostNames();
                    $first = (int) in_array($a['host'], $local);
                    $second = (int) in_array($b['host'], $local);
                    return $second - $first;
                });
        }

        return $brokers;
    }

    protected function _delay()
    {
        $options = $this->_failoverUri->options;
        $this->_reconnectAttempts++;

        if ($this->_reconnectAttempts == 0) {
            return 0;
        }

        if ($this->_maxReconnectAttempts != -1 && $this->_reconnectAttempts > $this->_maxReconnectAttempts) {
            throw new StompConnectTimeout("Reconnect timeout: {$this->_maxReconnectAttempts} attempts");
        }

        $delay = max(0, min($this->_reconnectDelay + (mt_rand()/mt_getrandmax() * $options['reconnectDelayJitter']), $options['maxReconnectDelay']));
        $this->_reconnectDelay *= ($options['useExponentialBackOff']) ? $options['backOffMultiplier'] : 1;

        return $delay / 1000.0;
    }

    protected function _reset()
    {
        $options = $this->_failoverUri->options;
        $this->_reconnectDelay = $options['initialReconnectDelay'];

        if (is_null($this->_maxReconnectAttempts)) {
            $this->_maxReconnectAttempts = $options['startupMaxReconnectAttempts'];
        } else {
            $this->_maxReconnectAttempts = $options['maxReconnectAttempts'];
        }

        $this->_reconnectAttempts = -1;
    }
}