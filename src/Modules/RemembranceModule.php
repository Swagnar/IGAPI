<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Contracts\StoryModule;

class RemembranceModule implements StoryModule
{
    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł RemembranceModule zainicjalizowany");
    }

    public function getLabel(): string { return 'Pamięć'; }
    
    public function run(): void {
        Console::info("Moduł Pamięć jest w trakcie opracowywania i nie zawiera jeszcze funkcjonalności.");
    }
}



?>