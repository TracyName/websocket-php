<?php

namespace WebSocketPHP;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class Log
{
    public static function create(
        string $log_folder,
        string $channel_name = 'WebSocketPHP',
        int $log_level = Logger::DEBUG,
        string $file_name = 'websocket-php.log'
    ): Logger {
        $log_folder = LogFolder::validate($log_folder);

        $log = new Logger($channel_name);

        $handler = new RotatingFileHandler(
            $log_folder . $file_name,
            30,
            $log_level
        );

        $handler->setFilenameFormat('{date}-{filename}', 'Y/m');

        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );

        $handler->setFormatter($formatter);

        $log->pushHandler($handler);

        return $log;
    }
}