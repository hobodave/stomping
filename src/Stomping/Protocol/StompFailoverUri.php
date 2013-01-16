<?php

namespace Stomping\Protocol;

class StompFailoverUri
{
    const FAILOVER_PREFIX = 'failover:';
    const REGEX_BRACKETS = '/^\((?P<uri>.+)\)$/';
    const REGEX_URI = '#^(?P<protocol>tcp)://(?P<host>[^:]+):(?P<port>\d+)$#';

    public $uri;
    public $brokers;
    public $options;

    protected static $localHostNames;
    protected static $supportedOptions;

    public static function localHostNames()
    {
        if (empty(static::$localHostNames)) {
            static::$localHostNames = array(
                'localhost',
                '127.0.0.1',
                gethostbyname(gethostname()),
                gethostname(),
            );
        }

        return static::$localHostNames;
    }

    protected static function supportedOptions()
    {
        if (empty(static::$supportedOptions)) {
            static::$supportedOptions = array(
                'initialReconnectDelay' => 10,
                'maxReconnectDelay' => 30000,
                'useExponentialBackOff' => true,
                'backOffMultiplier' => 2.0,
                'maxReconnectAttempts' => -1,
                'startupMaxReconnectAttempts' => 0,
                'reconnectDelayJitter' => 0,
                'randomize' => true,
                'priorityBackup' => false,
            );
        }

        return static::$supportedOptions;
    }

    public function __construct($uri)
    {
        $this->_parse($uri);
    }

    public function __toString()
    {
        return $this->uri;
    }

    protected function _parse($uri)
    {
        $this->uri = $uri;
        $parts = explode('?', $uri);

        $uri = $parts[0];
        $options = isset($parts[1]) ? $parts[1] : null;

        if (0 === strpos($uri, self::FAILOVER_PREFIX)) {
            $uri = substr($uri, strlen(self::FAILOVER_PREFIX));
        }

        $this->_setOptions($options);
        $this->_setBrokers($uri);
    }

    protected function _setBrokers($uri)
    {
        $brackets = null;
        preg_match(self::REGEX_BRACKETS, $uri, $brackets);
        $uri = isset($brackets['uri']) ? $brackets['uri'] : $uri;
        $brokers = array();
        $keys = array('protocol' => true, 'host' => true, 'port' => true);
        foreach (explode(',', $uri) as $u) {
            $matches = null;
            $matched = preg_match(self::REGEX_URI, $u, $matches);
            if (!$matched) {
                throw new \UnexpectedValueException("Invalid Broker: {$u}");
            }
            $broker = array_intersect_key($matches, $keys);
            $broker['port'] = (int) $broker['port'];
            $brokers[] = $broker;
        }

        $this->brokers = $brokers;
    }

    protected function _setOptions($options = null)
    {
        $defaults = static::supportedOptions();
        $options = array_merge($defaults, (array) $options);
        $this->options = $options;
    }
}