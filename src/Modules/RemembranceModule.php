<?php
namespace Swagnar\Igapi\Modules;

use Swagnar\Igapi\Http\JsonClient;
use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Contracts\StoryPayload;

class RemembranceModule implements StoryModule
{
    public function __construct(
        private readonly JsonClient $http,
    ){
        Console::module("Moduł RemembranceModule zainicjalizowany");
    }

    public function getLabel(): string { return 'Pamięć'; }

    public function run(): StoryPayload {
        Console::info("Moduł Pamięć jest w trakcie opracowywania i nie zawiera jeszcze funkcjonalności.");

        // Brak slajdów — runner nie ma czego publikować
        return new StoryPayload([], $this->getLabel());
    }
}
