<?php

namespace Stomping\Sync;

use Psr\Log\LogLevel;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Stomping\StompConfig;
use Stomping\Error\StompConnectionError;
use Stomping\Error\StompProtocolError;
use Stomping\Protocol\StompFailoverTransport;
use Stomping\Protocol\StompSession;
use Stomping\Protocol\Commands;

class Stomp implements LoggerAwareInterface, LoggerInterface
{
    protected $_config;

    /** @var \Stomping\Protocol\StompSession */
    protected $_session;

    /** @var \Stomping\Protocol\StompFailoverTransport */
    protected $_failover;

    /** @var StompFrameTransport */
    protected $_transport;

    /** @var  LoggerInterface */
    protected $logger;

    protected $_messages = array();

    public function __construct(StompConfig $config)
    {
        $this->_config = $config;
        $this->_session = new StompSession($this->_config->version, $this->_config->check);
        $this->_failover = new StompFailoverTransport($config->uri);
        $this->setTransport(null);
    }

    public function isConnected()
    {
        $transport = null;

        try {
            $transport = $this->getTransport();
        } catch (StompConnectionError $e) {
            return false;
        }

        return is_null($transport) ? false : true;
    }

    public function connect($headers = null, $versions = null, $host = null, $hearBeats = null, $connectTimeout = null, $connectedTimeout = null)
    {
        $transport = null;

        try {
            $transport = $this->getTransport();
        } catch (StompConnectionError $e) {
        }

        if (!is_null($transport)) {
            throw new StompConnectionError('Already connected to ' . $transport);
        }

        try {
            foreach ($this->_failover as $brokerDelayPair) {
                list($broker, $connectDelay) = $brokerDelayPair;
                $transport = new StompFrameTransport($broker['host'], $broker['port'], $this->getSession()->version);
                if ($connectDelay) {
                    $this->debug(sprintf('Delaying connect attempt for %d ms', (int) ($connectDelay * 1000)));
                    usleep($connectDelay);
                }
                // $this->log->info("Connecting to {$transport} ...");
                try {
                    $transport->connect($connectTimeout);
                    $this->info('Connection established');
                    $this->setTransport($transport);
                    $this->_connect($headers, $versions, $host, $hearBeats, $connectedTimeout);
                } catch (StompConnectionError $e) {
                    $this->warning(sprintf("Could not connect to %s [%s]", $transport, $e));
                }
            }
        } catch (StompConnectionError $e) {
            $this->error(sprintf("Reconnect failed [%s]", $e));
            throw $e;
        }
    }

    protected function _connect($headers, $versions, $host, $heartBeats, $timeout)
    {
        $frame = $this->getSession()->connect($this->_config->login, $this->_config->passcode, $headers, $versions, $host, $heartBeats);
        $this->sendFrame($frame);

        if (!$this->canRead($timeout)) {
            $this->getSession()->disconnect();
            throw new StompProtocolError("STOMP session connect failed [timeout={$timeout}]");
        }

        do {
            $frame = $this->receiveFrame();
        } while ($frame instanceof \Stomping\Protocol\StompHeartBeat);
        $this->getSession()->connected($frame);
        $this->info("STOMP session established with broker {$this->getTransport()}");

        foreach ($this->getSession()->replay() as $destHeadersRecptSet) {
            list($destination, $headers, $receipt) = $destHeadersRecptSet;
            $this->info(sprintf('Replaying subscription %s', $headers));
            $this->subscribe($destination, $headers, $receipt);
        }
    }

    public function disconnect($receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->disconnect($receipt));

        if (!$receipt) {
            $this->close();
        }
    }

    public function send($destination, $body = '', $headers = null, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame(Commands::send($destination, $body, $headers, $receipt));
    }

    public function subscribe($destination, $headers = null, $receipt = null)
    {
        $this->getTransport();
        list($frame, $token) = $this->getSession()->subscribe($destination, $headers, $receipt);
        $this->sendFrame($frame);

        return $token;
    }

    public function unsubscribe($token, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->unsubscribe($token, $receipt));
    }

    public function ack($frame, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->ack($frame, $receipt));
    }

    public function nack($frame, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->nack($frame, $receipt));
    }

    public function begin($transaction, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->begin($transaction, $receipt));
    }

    public function abort($transaction, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->abort($transaction, $receipt));
    }

    public function commit($transaction, $receipt = null)
    {
        $this->getTransport();
        $this->sendFrame($this->getSession()->commit($transaction, $receipt));
    }

    /**
     * @param callable $callback
     * @param string|null $transaction
     * @param string|null $receipt
     * @throws \Exception
     */
    public function transaction(\Closure $callback, $transaction = null, $receipt = null)
    {
        $transaction = $this->getSession()->transaction($transaction);

        $begin = $commit = $abort = null;

        if ($receipt) {
            $begin = "{$receipt}-begin";
            $commit = "{$receipt}-commit";
            $abort = "{$receipt}-abort";
        }

        $this->begin($transaction, $begin);

        try {
            $callback();
            $this->commit($transaction, $commit);
        } catch (\Exception $e) {
            $this->abort($transaction, $abort);
            throw $e;
        }
    }

    /**
     * @param $frame
     * @return array
     */
    public function message($frame)
    {
        return $this->getSession()->message($frame);
    }

    /**
     * @param $frame
     * @return string
     */
    public function receipt($frame)
    {
        return $this->getSession()->receipt($frame);
    }

    public function close($flush = true)
    {
        $this->getSession()->close($flush);

        $exception = null;

        try {
            if ($this->_transport) {
                $this->_transport->disconnect();
            }
        } catch (\Exception $e) {
            $exception = $e;
        }

        $this->setTransport(null);

        if ($exception) {
            throw $exception;
        }
    }

    public function canRead($timeout = null)
    {
        $this->getTransport();

        if (count($this->_messages)) {
            return true;
        }

        $deadline = is_null($timeout) ? null : microtime(true) + $timeout;

        while (true) {
            $timeout = is_null($deadline) ? null : max(0, $deadline - microtime(true));

            if (!$this->getTransport()->canRead($timeout)) {
                return false;
            }

            $frame = $this->getTransport()->receive();
            $this->getSession()->received();

            $this->debug('Received ' . $frame->info());

            if ($frame) {
                $this->_messages[] = $frame;
                return true;
            }
        }
    }

    public function sendFrame($frame)
    {
        $this->debug('Sending ' . $frame->info());
        $this->getTransport()->send($frame);
        $this->getSession()->sent();
    }

    public function receiveFrame()
    {
        if ($this->canRead()) {
            return array_shift($this->_messages);
        }
    }

    public function getSession()
    {
        return $this->_session;
    }

    protected function getTransport()
    {
        /** @var $transport StompFrameTransport */
        $transport = $this->_transport;

        if (!$transport) {
            throw new StompConnectionError('Not connected');
        }

        try {
            $transport->canRead(0);
        } catch (\Exception $e) {
            $this->close(false);
            throw $e;
        }

        return $transport;
    }

    protected function setTransport($transport)
    {
        $this->_transport = $transport;
        $this->_messages = array();
    }

    public function beat()
    {
        $this->sendFrame($this->getSession()->beat());
    }

    public function getLastSent()
    {
        return $this->getSession()->lastSent;
    }

    public function getLastReceived()
    {
        return $this->getSession()->lastReceived;
    }

    public function getClientHeartBeat()
    {
        return $this->getSession()->clientHeartBeat;
    }

    public function getServerHeartBeat()
    {
        return $this->getSession()->serverHeartBeat;
    }

    /**
     * Sets a logger instance on the object
     *
     * @param LoggerInterface $logger
     * @return null
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * System is unusable.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function emergency($message, array $context = array())
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * Example: Entire website down, database unavailable, etc. This should
     * trigger the SMS alerts and wake you up.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function alert($message, array $context = array())
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Critical conditions.
     *
     * Example: Application component unavailable, unexpected exception.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function critical($message, array $context = array())
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function error($message, array $context = array())
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function warning($message, array $context = array())
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function notice($message, array $context = array())
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Interesting events.
     *
     * Example: User logs in, SQL logs.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function info($message, array $context = array())
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function debug($message, array $context = array())
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        if (is_null($this->logger)) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}
