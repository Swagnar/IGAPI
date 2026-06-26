<?php
namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Http\InstagramPublisher;
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Helper\Console;

use RuntimeException;

class StoryRunner
{
    public function __construct(
        private readonly array $modules,
        private readonly InstagramPublisher $publisher,
    ){}

    public function run(): void {
        foreach($this->modules as $module) {
            if (!$module instanceof StoryModule) {
                throw new \InvalidArgumentException("Module musi implementować StoryModule");
            }
            Console::boot("Uruchamiam moduł: " . $module->getLabel());
            try {
                $payload = $module->run();
                Console::module("Moduł " . $module->getLabel() . " zakończył działanie.");
            } catch (\Throwable $e) {
                Console::error("Błąd w module " . $module->getLabel() . ": " . $e->getMessage() . '\n' . $e->getTraceAsString());
                continue;
            }
            foreach($payload->slides as $slide) {
                if(!DEBUG) {
                    try {
                        Console::module("Moduł " . $module->getLabel() . " rozpoczyna publikowanie.");
                        $this->publisher->publish($slide['url']);
                        Console::module("Moduł " . $module->getLabel() . " zakończył publikowanie.");
                        sleep(10);
                    } catch (\Throwable $e) {
                        Console::error("Błąd publikowania " . $module->getLabel() . ": " . $e->getMessage());
                        break;
                    }
                }
            }
        }
    }
}


?>