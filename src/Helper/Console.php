<?php

namespace Swagnar\Igapi\Helper;

class Console {

    private const RESET = "\033[0m";

    private const BLACK = "\033[30m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const MAGENTA = "\033[35m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";
    
    private const BRIGHT_BLACK = "\033[90m";
    private const BRIGHT_RED = "\033[91m";
    private const BRIGHT_GREEN = "\033[92m";
    private const BRIGHT_YELLOW = "\033[93m";
    private const BRIGHT_BLUE = "\033[94m";
    private const BRIGHT_MAGENTA = "\033[95m";
    private const BRIGHT_CYAN = "\033[96m";
    private const BRIGHT_WHITE = "\033[97m";


    private static function write(string $tag, string $color, string $message): void
    {
        echo "{$color}[{$tag}]\033[0m {$message}\n";
    }

    public static function boot(string $message): void
    {
        self::write('BOOT', "\033[95m", $message);
    }

    public static function module(string $message): void
    {
        self::write('MODULE', "\033[94m", $message);
    }

    public static function debug(string $message): void
    {
        self::write('DEBUG', "\033[90m", $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', "\033[96m", $message);
    }

    public static function success(string $message): void
    {
        self::write('SUCCESS', "\033[92m", $message);
    }

    public static function warn(string $message): void
    {
        self::write('WARN', "\033[93m", $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', "\033[91m", $message);
    }

    public static function fatal(string $message): void
    {
        self::write('FATAL', "\033[41;97m", $message);
    }
}


?>