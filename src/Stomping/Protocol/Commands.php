<?php

namespace Stomping\Protocol;

use Stomping\Error\StompProtocolError;
use Stomping\Protocol\StompSpec;
use Stomping\Protocol\StompFrame;
use Stomping\Protocol\StompHeartBeat;

class Commands
{
    // outgoing frames

    /**
     * Create a **STOMP** frame.
     *
     * Not supported in STOMP protocol 1.0, synonymous to `connect` for STOMP protocol 1.1 and higher.
     *
     * @param string $login
     * @param string $passcode
     * @param array $headers
     * @param array $versions
     * @param string $host
     * @param array $heartBeats
     * @throws \Stomping\Error\StompProtocolError
     * @return \Stomping\Protocol\StompFrame
     */
    public static function stomp($login = null, $passcode = null, $headers = null, $versions = null, $host = null, $heartBeats = null)
    {
        if (is_null($versions) || array($versions) == array(StompSpec::VERSION_1_0)) {
            throw new StompProtocolError(sprintf('Unsupported command (version %s): %s', StompSpec::VERSION_1_0, StompSpec::STOMP));
        }
        $frame = static::connect($login, $passcode, $headers, $versions, $host, $heartBeats);
        return new StompFrame(StompSpec::STOMP, $frame->headers, $frame->body);
    }

    /**
     * @param string $login
     * @param string $passcode
     * @param array $headers
     * @param array $versions
     * @param string $host
     * @param array $heartBeats
     * @return \Stomping\Protocol\StompFrame
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function connect($login = null, $passcode = null, $headers = null, $versions = null, $host = null, $heartBeats = null)
    {
        $headers = $headers ?: array();

        if (!is_null($login)) {
            $headers[StompSpec::LOGIN_HEADER] = $login;
        }

        if (!is_null($passcode)) {
            $headers[StompSpec::PASSCODE_HEADER] = $passcode;
        }

        if (is_null($versions)) {
            $versions = array(StompSpec::VERSION_1_0);
        } else {
            $versions = array_map('\Stomping\Protocol\Commands::version', $versions);
            sort($versions);
        }

        if ($versions != array(StompSpec::VERSION_1_0)) {
            $headers[StompSpec::ACCEPT_VERSION_HEADER] = implode(',', array_map('\Stomping\Protocol\Commands::version', $versions));

            if (is_null($host)) {
                $host = gethostname();
            }

            $headers[StompSpec::HOST_HEADER] = $host;
        }

        if ($heartBeats) {
            if ($versions == array(StompSpec::VERSION_1_0)) {
                throw new StompProtocolError(sprintf('Heart-beating not supported (version %s)', StompSpec::VERSION_1_0));
            }

            $heartBeats = array_map('intval', $heartBeats);

            if (min($heartBeats) < 0) {
                throw new StompProtocolError(sprintf(
                    'Invalid heart-beats (two non-negative integers required): [%s]',
                    implode(',', $heartBeats)
                ));
            }

            $headers[StompSpec::HEART_BEAT_HEADER] = implode(',', $heartBeats);
        }

        return new StompFrame(StompSpec::CONNECT, $headers);
    }

    /**
     * @param string $receipt
     * @return \Stomping\Protocol\StompFrame
     */
    public static function disconnect($receipt = null)
    {
        $headers = array();
        $frame = new StompFrame(StompSpec::DISCONNECT, $headers);
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $destination
     * @param string $body
     * @param array $headers
     * @param string $receipt
     * @return \Stomping\Protocol\StompFrame
     */
    public static function send($destination, $body = '', $headers = null, $receipt = null)
    {
        $frame = new StompFrame(StompSpec::SEND, (array) $headers, $body);
        $frame->headers[StompSpec::DESTINATION_HEADER] = $destination;
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $destination
     * @param array $headers
     * @param string $receipt
     * @param string $version
     * @return array
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function subscribe($destination, $headers, $receipt = null, $version = null)
    {
        $version = static::version($version);
        $frame = new StompFrame(StompSpec::SUBSCRIBE, (array) $headers);
        $frame->headers[StompSpec::DESTINATION_HEADER] = $destination;
        static::addReceiptHeader($frame, $receipt);

        $subscription = null;

        try {
            $subscription = static::checkHeader($frame, StompSpec::ID_HEADER, $version);
        } catch (StompProtocolError $e) {
            if ($version != StompSpec::VERSION_1_0) {
                throw $e;
            }
        }

        $token = is_null($subscription)
            ? array(StompSpec::DESTINATION_HEADER => $destination)
            : array(StompSpec::ID_HEADER => $subscription);

        return array($frame, $token);
    }

    /**
     * @param array $token
     * @param string $receipt
     * @param string $version
     * @return \Stomping\Protocol\StompFrame
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function unsubscribe($token, $receipt = null, $version = null)
    {
        $version = static::version($version);
        $frame = new StompFrame(StompSpec::UNSUBSCRIBE, $token);
        static::addReceiptHeader($frame, $receipt);

        try {
            static::checkHeader($frame, StompSpec::ID_HEADER, $version);
        } catch (StompProtocolError $e) {
            if ($version != StompSpec::VERSION_1_0) {
                throw $e;
            }
            static::checkHeader($frame, StompSpec::DESTINATION_HEADER);
        }

        return $frame;
    }

    /**
     * @param StompFrame $frame
     * @param array $transactions
     * @param string $receipt
     * @param string $version
     * @return \Stomping\Protocol\StompFrame
     */
    public static function ack(StompFrame $frame, $transactions = null, $receipt = null, $version = null)
    {
        $frame = new StompFrame(StompSpec::ACK, static::ackHeaders($frame, $transactions, $version));
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param StompFrame $frame
     * @param array $transactions
     * @param string $receipt
     * @param string $version
     * @return \Stomping\Protocol\StompFrame
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function nack(StompFrame $frame, $transactions = null, $receipt = null, $version = null)
    {
        $version = static::version($version);
        if ($version === StompSpec::VERSION_1_0) {
            throw new StompProtocolError(sprintf('%s not supported (version %s)', StompSpec::NACK, $version));
        }
        $frame = new StompFrame(StompSpec::NACK, static::ackHeaders($frame, $transactions, $version));
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $transaction
     * @param string $receipt
     * @return \Stomping\Protocol\StompFrame
     */
    public static function begin($transaction, $receipt = null)
    {
        $frame = new StompFrame(StompSpec::BEGIN, array(StompSpec::TRANSACTION_HEADER => $transaction));
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $transaction
     * @param string $receipt
     * @return \Stomping\Protocol\StompFrame
     */
    public static function abort($transaction, $receipt = null)
    {
        $frame = new StompFrame(StompSpec::ABORT, array(StompSpec::TRANSACTION_HEADER => $transaction));
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $transaction
     * @param string $receipt
     * @return \Stomping\Protocol\StompFrame
     */
    public static function commit($transaction, $receipt = null)
    {
        $frame = new StompFrame(StompSpec::COMMIT, array(StompSpec::TRANSACTION_HEADER => $transaction));
        static::addReceiptHeader($frame, $receipt);

        return $frame;
    }

    /**
     * @param string $version
     * @return \Stomping\Protocol\StompHeartBeat
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function beat($version = null)
    {
        $version = static::version($version);

        if ($version == StompSpec::VERSION_1_0) {
            throw new StompProtocolError("Heartbeat not supported (version {$version})");
        }

        return new StompHeartBeat();
    }

    // Incoming frames

    /**
     * @param StompFrame $frame
     * @param array $versions
     * @return array [$version, $server, $id, $heartBeats]
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function connected(StompFrame $frame, $versions = null)
    {
        if (is_null($versions)) {
            $versions = array(StompSpec::VERSION_1_0);
        } else {
            $versions = array_map('\Stomping\Protocol\Commands::version', $versions);
            sort($versions);
        }

        $version = end($versions);

        static::checkCommand($frame, array(StompSpec::CONNECTED));
        $headers = $frame->headers;

        try {
            if (StompSpec::VERSION_1_0 != $version) {
                $version = static::version(isset($headers[StompSpec::VERSION_HEADER]) ? $headers[StompSpec::VERSION_HEADER] : StompSpec::VERSION_1_0);

                if (!in_array($version, $versions)) {
                    throw new StompProtocolError('');
                }
            }
        } catch (StompProtocolError $e) {
            throw new StompProtocolError(
                sprintf(
                    'Server version incompatible with accepted versions [%s] [headers=%s]',
                    implode(',', $versions),
                    json_encode($headers)
                )
            );
        }

        $server = ($version == StompSpec::VERSION_1_0) ? null : $headers[StompSpec::SERVER_HEADER];
        $heartBeats = array(0,0);

        if ($version != StompSpec::VERSION_1_0 && isset($headers[StompSpec::HEART_BEAT_HEADER])) {
            $heartBeats = array_map('intval', explode(',', $headers[StompSpec::HEART_BEAT_HEADER]));

            if (count($heartBeats) < 2 || min($heartBeats) < 0) {
                throw new StompProtocolError(sprintf(
                    'Invalid %s header (two comma-separated and non-negative integers required): [%s]',
                    StompSpec::HEART_BEAT_HEADER,
                    implode(',', $heartBeats)
                ));
            }
        }

        if (isset($headers[StompSpec::SESSION_HEADER])) {
            $id = $headers[StompSpec::SESSION_HEADER];
        } else {
            if ($version == StompSpec::VERSION_1_0) {
                throw new StompProtocolError(sprintf(
                    'Invalid %s frame (%s header is missing) [headers=%s]',
                    StompSpec::CONNECTED,
                    StompSpec::SESSION_HEADER,
                    json_encode($headers)
                ));
            } else {
                $id = null;
            }
        }

        return array($version, $server, $id, $heartBeats);
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param string $version
     * @return array Subscription Token [dest/sub header, value]
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function message(StompFrame $frame, $version)
    {
        $version = static::version($version);
        static::checkCommand($frame, array(StompSpec::MESSAGE));
        static::checkHeader($frame, StompSpec::MESSAGE_ID_HEADER);
        $destination = static::checkHeader($frame, StompSpec::DESTINATION_HEADER);
        $subscription = null;

        try {
            $subscription = static::checkHeader($frame, StompSpec::SUBSCRIPTION_HEADER, $version);
        } catch (StompProtocolError $e) {
            if ($version != StompSpec::VERSION_1_0) {
                throw $e;
            }
        }

        $token = is_null($subscription)
            ? array(StompSpec::DESTINATION_HEADER, $destination)
            : array(StompSpec::SUBSCRIPTION_HEADER, $subscription);

        return $token;
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param string $version
     * @return string
     */
    public static function receipt(StompFrame $frame, $version)
    {
        $version = static::version($version);
        static::checkCommand($frame, array(StompSpec::RECEIPT));
        static::checkHeader($frame, StompSpec::RECEIPT_ID_HEADER);

        return $frame->headers[StompSpec::RECEIPT_ID_HEADER];
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param string $version
     */
    public static function error(StompFrame $frame, $version)
    {
        $version = static::version($version);
        static::checkCommand($frame, array(StompSpec::ERROR));
    }

    /**
     * @param string $version
     * @return string
     * @throws \Stomping\Error\StompProtocolError
     */
    public static function version($version = null)
    {
        if (is_null($version)) {
            $version = StompSpec::DEFAULT_VERSION;
        }

        $valid = StompSpec::versions();

        if (!isset($valid[$version])) {
            throw new StompProtocolError("Version is not supported [{$version}]");
        }

        return $version;
    }

    /**
     * @param string $version
     * @return array
     */
    public static function versions($version)
    {
        $version = static::version($version);
        $ret = array();

        foreach (array_keys(StompSpec::versions()) as $v) {
            $ret[] = $v;
            if ($v == $version) {
                break;
            }
        }

        return $ret;
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param array $transactions
     * @param string $version
     * @return array
     */
    protected static function ackHeaders(StompFrame $frame, $transactions, $version)
    {
        $version = static::version($version);
        static::checkCommand($frame, array(StompSpec::MESSAGE));
        static::checkHeader($frame, StompSpec::MESSAGE_ID_HEADER, $version);

        if ($version != StompSpec::VERSION_1_0) {
            static::checkHeader($frame, StompSpec::SUBSCRIPTION_HEADER, $version);
        }

        $keys = array(StompSpec::SUBSCRIPTION_HEADER => true, StompSpec::MESSAGE_ID_HEADER => true);

        if (isset($frame->headers[StompSpec::TRANSACTION_HEADER])) {
            $transaction = $frame->headers[StompSpec::TRANSACTION_HEADER];

            if (in_array($transaction, (array) $transactions)) {
                $keys[StompSpec::TRANSACTION_HEADER] = true;
            }
        }

        return array_intersect_key($frame->headers, $keys);
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param string $receipt
     * @throws \Stomping\Error\StompProtocolError
     */
    protected static function addReceiptHeader(StompFrame $frame, $receipt)
    {
        if (!$receipt) {
            return;
        }

        if (!is_string($receipt)) {
            throw new StompProtocolError(sprintf(
                'Invalid receipt header (not a string): %s',
                json_encode($receipt)
            ));
        }

        $frame->headers[StompSpec::RECEIPT_HEADER] = $receipt;
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param array $commands
     * @throws \Stomping\Error\StompProtocolError
     */
    protected static function checkCommand(StompFrame $frame, $commands = array())
    {
//        $valid = StompSpec::commands();
//        if (!isset($valid[$frame->command]) || !in_array($frame->command, $commands)) {
        if (!in_array($frame->command, $commands)) {
            throw new StompProtocolError(sprintf(
                'Cannot handle command %s [expected=%s, headers=%s]',
                $frame->command,
                implode(', ', $commands),
                json_encode($frame->headers)
            ));
        }
    }

    /**
     * @param \Stomping\Protocol\StompFrame $frame
     * @param string $header
     * @param string $version
     * @return string
     * @throws \Stomping\Error\StompProtocolError
     */
    protected static function checkHeader(StompFrame $frame, $header, $version = null)
    {
        if (!isset($frame->headers[$header])) {
            if ($version) {
                $version = " in version {$version}";
            } else {
                $version = '';
            }

            throw new StompProtocolError(sprintf(
                'Invalid %s frame (%s header mandatory%s) [headers=%s]',
                $frame->command,
                $header,
                $version,
                json_encode($frame->headers)
            ));
        }

        return $frame->headers[$header];
    }
}