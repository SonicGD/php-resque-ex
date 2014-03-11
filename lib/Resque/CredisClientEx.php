<?php

class Resque_CredisClientEx extends Credis_Client
{
    protected $maxConnectRetries = 5;

    public function connect()
    {
        if ($this->connected) {
            return $this;
        }
        if (preg_match('#^(tcp|unix)://(.*)$#', $this->host, $matches)) {
            if ($matches[1] == 'tcp') {
                if (!preg_match('#^(.*)(?::(\d+))?(?:/(.*))?$#', $matches[2], $matches)) {
                    throw new CredisException('Invalid host format; expected tcp://host[:port][/persistent]');
                }
                $this->host = $matches[1];
                $this->port = (int)(isset($matches[2]) ? $matches[2] : 6379);
                $this->persistent = isset($matches[3]) ? $matches[3] : '';
            } else {
                $this->host = $matches[2];
                $this->port = null;
                if (substr($this->host, 0, 1) != '/') {
                    throw new CredisException('Invalid unix socket format; expected unix:///path/to/redis.sock');
                }
            }
        }
        if ($this->port !== null && substr($this->host, 0, 1) == '/') {
            $this->port = null;
        }
        if ($this->standalone) {
            $flags = STREAM_CLIENT_CONNECT;
            $remote_socket = $this->port === null
                ? 'unix://' . $this->host
                : 'tcp://' . $this->host . ':' . $this->port;
            if ($this->persistent) {
                if ($this->port === null) { // Unix socket
                    throw new CredisException(
                        'Persistent connections to UNIX sockets are not supported in standalone mode.'
                    );
                }
                $remote_socket .= '/' . $this->persistent;
                $flags = $flags | STREAM_CLIENT_PERSISTENT;
            }
            $result = $this->redis = @stream_socket_client(
                $remote_socket,
                $errno,
                $errstr,
                $this->timeout !== null ? $this->timeout : 2.5,
                $flags
            );
            var_dump($remote_socket, $errstr);
        } else {
            if (!$this->redis) {
                $this->redis = new Redis;
            }
            $result = $this->persistent
                ? $this->redis->pconnect($this->host, $this->port, $this->timeout, $this->persistent)
                : $this->redis->connect($this->host, $this->port, $this->timeout);
        }

        // Use recursion for connection retries
        if (!$result) {
            $this->connectFailures++;
            if ($this->connectFailures <= $this->maxConnectRetries) {
                sleep(1);
                return $this->connect();
            }
            $failures = $this->connectFailures;
            $this->connectFailures = 0;
            throw new CredisException("Connection to Redis failed after $failures failures.");
        }

        $this->connectFailures = 0;
        $this->connected = true;

        // Set read timeout
        if ($this->readTimeout) {
            $this->setReadTimeout($this->readTimeout);
        }

        return $this;
    }
}