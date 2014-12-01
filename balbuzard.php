<?php
/*
 * Balbuzard is (c) 2014 by Emmanuel Leblond <emmanuel.leblond@gmail.com>
 * and Vincent Mezino <vincent.mezino@gmail.com>
 * 
 * It is licensed to you under the terms of the WTFPLv2 (see below).
 * 
 *            DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 *                    Version 2, December 2004
 * 
 * Copyright (C) 2004 Sam Hocevar <sam@hocevar.net>
 * 
 * Everyone is permitted to copy and distribute verbatim or modified
 * copies of this license document, and changing it is allowed as long
 * as the name is changed.
 * 
 *            DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
 *   TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION
 * 
 * 0. You just DO WHAT THE FUCK YOU WANT TO.
 *
 *
 * Balbuzard
 * Dead simple multi client php server
 *
 * "Ugly problems often require ugly solutions.
 *  Solving an ugly problem in a pure manner is bloody hard."
 * - Rasmus Lerdorf
 */
error_reporting(E_ALL);

// Address/port of the server
define('SOCKETADDRESS', '127.0.0.1');
define('SOCKETPORT', 8000);

/*
 * Balbuzard works as a proxy which dispach incomming connexion to
 * to multiple php server (i.e. `php -S`), a connexion between a client
 * and a php server is called a node.
 */
define('NODES_BASE_SOCKETPORT', 27000);
define('NODES_MAX', 15);

define('BUFFER_SIZE', 20000000);


class NodesPool {
    private $base_socket_port;
    private $node_socket_address;
    private $node_count = 0;
    public function __construct($socket_address, $base_socket_port) {
        $this->base_socket_port = $base_socket_port;
        $this->node_socket_address = $socket_address;
    }
    public function dispatch_request($server_socket, $request_socket, $handle_callback) {
        pcntl_waitpid(-1, $status, WNOHANG);
        $this->node_count += 1;
        $node = ['address' => $this->node_socket_address,
                 'port' => $this->node_count % NODES_MAX + $this->base_socket_port];
        $pid = pcntl_fork();
        if ($pid === -1) {
            print("Error: Cannot fork\n");
            die;
        } elseif ($pid === 0) {
            if ($this->node_count <= NODES_MAX) {
                pclose(popen("php -S " . $node['address'] . ":".$node['port']."&", 'w'));
                time_nanosleep(0, 100000000);
            }
            socket_close($server_socket);
            $handle_callback($request_socket, $node);
            socket_close($request_socket);
            die;
        } else {
            socket_close($request_socket);
            return $pid;
        }
    }
}

function launch() {
    // Spawn php single client servers
    $node_pool = new NodesPool(SOCKETADDRESS, NODES_BASE_SOCKETPORT);
    // create server socket
    $server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_set_option($server_socket, SOL_SOCKET, SO_REUSEADDR, 1);
    if(!socket_bind($server_socket, SOCKETADDRESS, SOCKETPORT)) {
        socket_close($server_socket);
        echo 'Error: Unable to bind '.SOCKETADDRESS.' reason : '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
        die;
    }
    if(!socket_listen($server_socket, 100)) {
        socket_close($server_socket);
        echo 'Error: Unable to listen on '.SOCKETADDRESS.':'.SOCKETPORT.', reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
        die;
    }

    $handle_request = function($request_socket, $node) {
        $php_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_block($php_socket);
        if (!socket_connect($php_socket, $node['address'], $node['port'])) {
            socket_close($php_socket);
            echo 'Error: Unable to connect to '.$node['address'].':'.$node['port'].', reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            return;
        }
        $request_message = socket_read($request_socket, BUFFER_SIZE);
        $is_head = substr($request_message, 0, 4) === 'HEAD';
        $head = strstr($request_message, "\n", true);
        if (strpos($head, 'HTTP/1.1') !== False and !$is_head) {
            // If HTTP/1.1, try to use content-length to get the end (except for HEAD)
            $content_length = stristr($request_message, 'content-length:');
            if ($content_length !== False) {
                $content_length = intval(trim(strstr(strstr($content_length, "\n", true), ' ')));
                while ((strlen(strstr($request_message, "\r\n\r\n")) - 4) < $content_length) {
                    $request_message .= socket_read($request_socket, BUFFER_SIZE);
                }
            }
        }
        if ($request_message === False) {
            echo 'Error: Unable to read from request socket, reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            socket_close($php_socket);
            return;
        }
        if (socket_write($php_socket, $request_message) === False) {
            echo 'Error: Unable to write in socket '.$node['address'].':'.$node['port'].', reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            socket_close($php_socket);
            return;
        }
        $answer_message = socket_read($php_socket, BUFFER_SIZE);
        $head = strstr($answer_message, "\n", true);
        if (strpos($head, 'HTTP/1.1') !== False and !$is_head) {
            // If HTTP/1.1, try to use content-length to get the end (except for HEAD)
            $content_length = stristr($answer_message, 'content-length:');
            if ($content_length !== False) {
                $content_length = intval(trim(strstr(strstr($content_length, "\n", true), ' ')));
                while ((strlen(strstr($answer_message, "\r\n\r\n")) - 4) < $content_length) {
                    $answer_message .= socket_read($php_socket, BUFFER_SIZE);
                }
            }
        }
        if ($answer_message === False) {
            echo 'Error: Unable to read from socket '.$node['address'].':'.$node['port'].', reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            socket_close($php_socket);
            return;
        }
        print('php client '.$node['port'].' handle : '.strstr($request_message, "\n", True)."\n");
        if (socket_write($request_socket, $answer_message) === False) {
            echo 'Error: Unable to write in request socket, reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            socket_close($php_socket);
            return;
        }
        socket_close($php_socket);
    };

    while (true) {
        if (($request_socket = socket_accept($server_socket)) === false) {
            echo 'Error: Unable to accept connexion on '.SOCKETADDRESS.':'.SOCKETPORT.', reason '.socket_last_error().': '.lcfirst(socket_strerror(socket_last_error()));
            die;
        }
        socket_set_block($request_socket);
        $node_pool->dispatch_request($server_socket, $request_socket, $handle_request);
    }
    print("Leaving...\n");
    socket_close($sock);
}

launch();
