<?php

namespace WebSocketPHP;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;

class Log
{
    public static function create(
        string $log_folder,
        string $channel_name = 'WebSocketPHP',
        int|Level $log_level = Level::Debug,
        string $file_name = 'websocket-php.log'
    ): Logger {
        $log_folder = LogFolder::validate($log_folder);

        $log = new Logger($channel_name);

        $filename = $log_folder . $file_name;

        $handler = new RotatingFileHandler(
            $filename,
            30,
            $log_level
        );

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
