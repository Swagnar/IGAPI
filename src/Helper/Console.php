<?php

namespace Swagnar\Igapi\Helper;

class Console {

    public const RESET = "\033[0m";

    public const BLACK = "\033[30m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";             //
    public const MAGENTA = "\033[35m";
    public const CYAN = "\033[36m";
    public const WHITE = "\033[37m";
    
    public const BRIGHT_BLACK = "\033[90m";     //
    public const BRIGHT_RED = "\033[91m";       //
    public const BRIGHT_GREEN = "\033[92m";     //
    public const BRIGHT_YELLOW = "\033[93m";    //
    public const BRIGHT_BLUE = "\033[94m";      //
    public const BRIGHT_MAGENTA = "\033[95m";   //
    public const BRIGHT_CYAN = "\033[96m";      //
    public const BRIGHT_WHITE = "\033[97m";


    public static function write(string $tag, string $color, string $message): void
    {
        echo "{$color}[{$tag}]\033[0m {$message}\n";
    }

    public static function boot(string $message): void
    {
        self::write('BOOT', Console::BRIGHT_MAGENTA, $message);
    }

    public static function module(string $message): void
    {
        self::write('MODULE', Console::BRIGHT_BLUE, $message);
    }

    public static function plugin(string $message): void
    {
        self::write('PLUGIN', Console::BLUE, $message);
    }

    public static function debug(string $message): void
    {
        self::write('DEBUG', Console::BRIGHT_BLACK, $message);
    }

    public static function info(string $message): void
    {
        self::write('INFO', Console::BRIGHT_CYAN, $message);
    }

    public static function success(string $message): void
    {
        self::write('SUCCESS', Console::BRIGHT_GREEN, $message);
    }

    public static function warn(string $message): void
    {
        self::write('WARN', Console::BRIGHT_YELLOW, $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', Console::BRIGHT_RED, $message);
    }

    public static function fatal(string $message): void
    {
        self::write('FATAL', "\033[41;97m", $message);
    }
}
