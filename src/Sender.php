<?php

namespace WebSocketPHP;

use Monolog\Logger;
use Throwable;

class Sender
{
    protected int $tcp_port;

    protected Logger $log;

    public function __construct(int $tcp_port, string $log_folder = '')
    {
        $this->tcp_port = $tcp_port;
        $this->log = $log_folder === ''
            ? Log::create(__DIR__ . '/../', 'WebSocketSender')
            : Log::create($log_folder, 'WebSocketSender');
    }

    public function send(int|array $user_id, string $type, array $data = []): int|bool
    {
        try {
            $instance = @stream_socket_client(
                'tcp://127.0.0.1:' . $this->tcp_port,
                $error_number,
                $error_string,
                3
            );

            if ($instance === false) {
                $this->log->error('TCP connection failed', [
                    'tcp_port' => $this->tcp_port,
                    'error_number' => $error_number,
                    'error_string' => $error_string,
                ]);

                return false;
            }

            $uids = is_array($user_id) ? array_values($user_id) : [$user_id];

            $payload = [
                'uids' => $uids,
                'message' => [
                    'type' => $type,
                    'data' => $data,
                ],
            ];

            $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            if ($payload_json === false) {
                $this->log->error('JSON encode failed', [
                    'tcp_port' => $this->tcp_port,
                    'type' => $type,
                    'uids' => $uids,
                ]);

                fclose($instance);

                return false;
            }

            $result = fwrite($instance, $payload_json . "\n");

            if ($result === false) {
                $this->log->error('TCP write failed', [
                    'tcp_port' => $this->tcp_port,
                    'type' => $type,
                    'uids' => $uids,
                ]);
            }

            fclose($instance);

            return $result;
        } catch (Throwable $throwable) {
            $this->log->error('Sender exception', [
                'class' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => $throwable->getTraceAsString(),
                'tcp_port' => $this->tcp_port,
            ]);

            return false;
        }
    }
}
