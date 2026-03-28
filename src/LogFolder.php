<?php

namespace WebSocketPHP;

use RuntimeException;

class LogFolder
{
    public static function validate(string $log_folder): string
    {
        $log_folder = trim($log_folder);

        if ($log_folder === '') {
            throw new RuntimeException('Log folder path is empty');
        }

        $last_symbol = substr($log_folder, -1);

        if ($last_symbol !== '/' && $last_symbol !== '\\') {
            $log_folder .= DIRECTORY_SEPARATOR;
        }

        if (!is_dir($log_folder) && !mkdir($log_folder, 0777, true) && !is_dir($log_folder)) {
            throw new RuntimeException('Unable to create log folder: ' . $log_folder);
        }

        return $log_folder;
    }
}
