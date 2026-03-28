<?php

namespace WebSocketPHP;

use Exception;
use Monolog\Logger;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

class Server
{
    protected Logger $log;

    protected string $pid_file;

    protected int $ws_port;

    protected int $tcp_port;

    protected Worker $ws_worker;

    protected Worker $tcp_worker;

    protected $func_get_user_id;

    protected array $users_connections = [];

    public function __construct(
        string $log_folder,
        string $pid_file,
        int $ws_port,
        int $tcp_port,
        ?callable $func_get_user_id = null
    ) {
        $this->log = Log::create($log_folder, 'WebSocketServer');
        $this->pid_file = $pid_file;
        $this->ws_port = $ws_port;
        $this->tcp_port = $tcp_port;
        $this->func_get_user_id = $func_get_user_id;
    }

    public function start(): void
    {
        Worker::$pidFile = $this->pid_file;

        $this->log->info('Server start', [
            'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
            'ws_port' => $this->ws_port,
            'tcp_port' => $this->tcp_port,
            'pid_file' => $this->pid_file,
        ]);

        $this->ws_worker = new Worker('websocket://0.0.0.0:' . $this->ws_port);

        $this->ws_worker->onWorkerStart = function (): void {
            try {
                $this->log->info('WebSocket worker started', [
                    'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
                    'ws_port' => $this->ws_port,
                    'tcp_port' => $this->tcp_port,
                ]);

                $this->initMessenger();
            } catch (Throwable $throwable) {
                $this->logThrowable('onWorkerStart error', $throwable);
            }
        };

        $this->ws_worker->onConnect = function (TcpConnection $connection): void {
            try {
                $this->log->info('New connection', [
                    'connection_id' => $connection->id,
                ]);

                $connection->onWebSocketConnect = function (TcpConnection $connection): void {
                    try {
                        $sid = $_GET['sid'] ?? '';

                        if ($sid === '') {
                            $this->log->warning('Connection rejected: sid is empty', [
                                'connection_id' => $connection->id,
                            ]);

                            $connection->close();

                            return;
                        }

                        if ($this->func_get_user_id === null) {
                            $uid = (int)$sid;
                        } else {
                            $uid = (int)call_user_func($this->func_get_user_id, $sid);
                        }

                        if ($uid <= 0) {
                            $this->log->warning('Connection rejected: uid is invalid', [
                                'connection_id' => $connection->id,
                                'sid' => $sid,
                                'uid' => $uid,
                            ]);

                            $connection->close();

                            return;
                        }

                        $this->users_connections[$connection->id] = $uid;

                        $this->log->info('User connected', [
                            'connection_id' => $connection->id,
                            'uid' => $uid,
                        ]);
                    } catch (Throwable $throwable) {
                        $this->logThrowable('onWebSocketConnect error', $throwable, [
                            'connection_id' => $connection->id,
                        ]);

                        $connection->close();
                    }
                };
            } catch (Throwable $throwable) {
                $this->logThrowable('onConnect error', $throwable, [
                    'connection_id' => $connection->id,
                ]);
            }
        };

        $this->ws_worker->onClose = function (TcpConnection $connection): void {
            try {
                $uid = $this->users_connections[$connection->id] ?? null;

                if ($uid !== null) {
                    unset($this->users_connections[$connection->id]);

                    $this->log->info('User disconnected', [
                        'connection_id' => $connection->id,
                        'uid' => $uid,
                    ]);

                    return;
                }

                $this->log->info('Connection closed', [
                    'connection_id' => $connection->id,
                ]);
            } catch (Throwable $throwable) {
                $this->logThrowable('onClose error', $throwable, [
                    'connection_id' => $connection->id,
                ]);
            }
        };

        Worker::runAll();
    }

    /**
     * @throws Exception
     */
    protected function initMessenger(): void
    {
        $this->tcp_worker = new Worker('tcp://127.0.0.1:' . $this->tcp_port);

        $this->tcp_worker->onWorkerStart = function (): void {
            $this->log->info('TCP worker started', [
                'pid' => function_exists('posix_getpid') ? posix_getpid() : getmypid(),
                'tcp_port' => $this->tcp_port,
            ]);
        };

        $this->tcp_worker->onMessage = function ($connection, $data): void {
            try {
                if (!$connection) {
                    $this->log->warning('TCP message skipped: empty connection');

                    return;
                }

                $payload = json_decode($data, true);

                if (!is_array($payload)) {
                    $this->log->warning('Invalid TCP payload: json decode failed', [
                        'raw_data' => $data,
                    ]);

                    return;
                }

                if (
                    !isset($payload['uids'])
                    || !is_array($payload['uids'])
                    || count($payload['uids']) === 0
                ) {
                    $this->log->warning('Invalid TCP payload: uids missing or empty', [
                        'payload' => $payload,
                    ]);

                    return;
                }

                if (!array_key_exists('message', $payload)) {
                    $this->log->warning('Invalid TCP payload: message missing', [
                        'payload' => $payload,
                    ]);

                    return;
                }

                $message_json = json_encode($payload['message'], JSON_UNESCAPED_UNICODE);

                if ($message_json === false) {
                    $this->log->warning('Invalid TCP payload: message json encode failed', [
                        'payload' => $payload,
                    ]);

                    return;
                }

                foreach ($payload['uids'] as $uid) {
                    $uid = (int)$uid;

                    if ($uid === 0) {
                        foreach ($this->ws_worker->connections as $web_connection) {
                            /** @var TcpConnection $web_connection */
                            $web_connection->send($message_json);
                        }

                        $this->log->info('Message sent to all users', [
                            'connections_count' => count($this->ws_worker->connections),
                        ]);

                        continue;
                    }

                    $user_connections = array_keys($this->users_connections, $uid, true);

                    if ($user_connections === []) {
                        $this->log->info('User not connected', [
                            'uid' => $uid,
                        ]);

                        continue;
                    }

                    foreach ($user_connections as $connection_id) {
                        if (!isset($this->ws_worker->connections[$connection_id])) {
                            continue;
                        }

                        /** @var TcpConnection $web_connection */
                        $web_connection = $this->ws_worker->connections[$connection_id];
                        $web_connection->send($message_json);
                    }

                    $this->log->info('Message sent to user', [
                        'uid' => $uid,
                        'connections_count' => count($user_connections),
                    ]);
                }
            } catch (Throwable $throwable) {
                $this->logThrowable('tcp onMessage error', $throwable, [
                    'raw_data' => $data,
                ]);
            }
        };

        $this->tcp_worker->listen();

        $this->log->info('TCP listener started', [
            'tcp_port' => $this->tcp_port,
        ]);
    }

    protected function logThrowable(
        string $message,
        Throwable $throwable,
        array $context = []
    ): void {
        $this->log->error($message, array_merge($context, [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ]));
    }
}
