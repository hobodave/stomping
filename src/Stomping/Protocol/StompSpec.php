<?php

namespace Stomping\Protocol;

/**
 *  This class hosts all constants related to the STOMP protocol specification in its various versions.
 *
 * There really isn't much to document, but you are invited to take a look at all available constants
 * in the source code. Wait a minute ... one attribute is particularly noteworthy, name `DEFAULT_VERSION` ---
 * which currently is '1.0' (but this may change in upcoming releases, so you're advised to always
 * explicitly define which STOMP protocol version you are going to use).
 */
class StompSpec
{
    // specification of the STOMP protocol: http://stomp.github.com//index.html
    const VERSION_1_0 = '1.0';
    const VERSION_1_1 = '1.1';
    const DEFAULT_VERSION = self::VERSION_1_0;

    public static function versions()
    {
        return array(self::VERSION_1_0 => true, self::VERSION_1_1 => true);
    }

    const ABORT = 'ABORT';
    const ACK = 'ACK';
    const BEGIN = 'BEGIN';
    const COMMIT = 'COMMIT';
    const CONNECT = 'CONNECT';
    const DISCONNECT = 'DISCONNECT';
    const NACK = 'NACK';
    const SEND = 'SEND';
    const STOMP = 'STOMP';
    const SUBSCRIBE = 'SUBSCRIBE';
    const UNSUBSCRIBE = 'UNSUBSCRIBE';

    public static function clientCommands($version = null)
    {
        $commands = array(
            '1.0' => array(
                self::ABORT => true, self::ACK => true, self::BEGIN => true, self::COMMIT => true,
                self::CONNECT => true, self::DISCONNECT => true, self::SEND => true,
                self::SUBSCRIBE => true, self::UNSUBSCRIBE => true
            ),
            '1.1' => array(
                self::ABORT => true, self::ACK => true, self::BEGIN => true, self::COMMIT => true,
                self::CONNECT => true, self::DISCONNECT => true, self::NACK => true, self::SEND => true,
                self::STOMP => true, self::SUBSCRIBE => true, self::UNSUBSCRIBE => true
            )
        );

        return ($version) ? $commands[$version] : $commands;
    }

    const CONNECTED = 'CONNECTED';
    const ERROR = 'ERROR';
    const MESSAGE = 'MESSAGE';
    const RECEIPT = 'RECEIPT';

    public static function serverCommands($version = null)
    {
        $commands = array(
            '1.0' => array(
                self::CONNECTED => true, self::ERROR => true, self::MESSAGE => true, self::RECEIPT => true
            ),
            '1.1' => array(
                self::CONNECTED => true, self::ERROR => true, self::MESSAGE => true, self::RECEIPT => true
            )
        );

        return ($version) ? $commands[$version] : $commands;
    }

    public static function commands($version = null)
    {
        $commands =  array_merge_recursive(static::clientCommands(), static::serverCommands());

        return ($version) ? $commands[$version] : $commands;
    }

    const LINE_DELIMITER = "\n";
    const FRAME_DELIMITER = "\x00";
    const HEADER_SEPARATOR = ':';

    const ACCEPT_VERSION_HEADER = 'accept-version';
    const ACK_HEADER = 'ack';
    const CONTENT_LENGTH_HEADER = 'content-length';
    const CONTENT_TYPE_HEADER = 'content-type';
    const DESTINATION_HEADER = 'destination';
    const HEART_BEAT_HEADER = 'heart-beat';
    const HOST_HEADER = 'host';
    const ID_HEADER = 'id';
    const LOGIN_HEADER = 'login';
    const MESSAGE_ID_HEADER = 'message-id';
    const PASSCODE_HEADER = 'passcode';
    const RECEIPT_HEADER = 'receipt';
    const RECEIPT_ID_HEADER = 'receipt-id';
    const SESSION_HEADER = 'session';
    const SERVER_HEADER = 'server';
    const SUBSCRIPTION_HEADER = 'subscription';
    const TRANSACTION_HEADER = 'transaction';
    const VERSION_HEADER = 'version';

    const ACK_AUTO = 'auto';
    const ACK_CLIENT = 'client';
    const ACK_CLIENT_INDIVIDUAL = 'client-individual';

    public static function clientAckModes()
    {
        return array(self::ACK_CLIENT => true, self::ACK_CLIENT_INDIVIDUAL => true);
    }

    const HEART_BEAT_SEPARATOR = ',';
}