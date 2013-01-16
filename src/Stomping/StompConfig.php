<?php

namespace Stomping;

/**
 * This is a container for those configuration options which are common to both clients (sync and async)
 * and are needed to establish a STOMP connection. All parameters are available as attributes with the
 * same name of this object.
 */
class StompConfig
{
    public $uri;

    public $login;

    public $passcode;

    public $version;

    public $check;

    /**
     * @param string $uri A failover URI as it is accepted by StompFailoverUri
     * @param string $login The login for the STOMP brokers. The default is null, which means that no **login** header will be sent.
     * @param string $passcode The passcode for the STOMP brokers. The default is null, which means that no **passcode** header will be sent.
     * @param string $version A valid STOMP protocol version, or null (equivalent to the DEFAULT_VERSION attribute of the StompSpec class).
     * @param bool $check Decides whether the StompSession object which is used to represent the STOMP session should be
     *                    strict about the session's state: (e.g., whether to allow calling the session's StompSession::send when disconnected).
     *
     * @note Login and passcode have to be the same for all brokers because they are not part of the failover URI scheme.
     *
     * @seealso The StompFailoverTransport class which tells you which broker to use and how long you should wait to connect to it.
     * @seealso The StompFailoverUri which parses failover transport URIs.
     */
    public function __construct($uri, $login = null, $passcode = null, $version = null, $check = true)
    {
        $this->uri = $uri;
        $this->login = $login;
        $this->passcode = $passcode;
        $this->version = $version;
        $this->check = $check;
    }
}