<?php
namespace Swagnar\Igapi\Contracts;

use Swagnar\Igapi\Http\InstagramPublisher;
use Swagnar\Igapi\Contracts\StoryModule;
use Swagnar\Igapi\Helper\Console;
use Swagnar\Igapi\Layout\Slide;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;
use Swagnar\Igapi\Layout\WrappedText;

class StoryRunner
{
    public function __construct(
        private readonly array $modules,
        private readonly InstagramPublisher $publisher,
    ){}

    public function run(): void {
        if (!DEBUG) {
            $tokenStatus = $this->publisher->checkTokenStatus();

            if (!$tokenStatus['valid']) {
                Console::fatal("Token dostępu Meta jest nieprawidłowy/wygasły: " . $tokenStatus['message']);
                Console::fatal("Pomijam wszystkie moduły — publikacja i tak by się nie powiodła.");
                $this->saveTokenExpiredAlert($tokenStatus['message']);
                return;
            }

            Console::success("Token dostępu Meta jest ważny.");
        }

        foreach($this->modules as $module) {
            if (!$module instanceof StoryModule) {
                throw new \InvalidArgumentException("Module musi implementować StoryModule");
            }
            Console::boot("Uruchamiam moduł: " . $module->getLabel());
            try {
                $payload = $module->run();
                Console::module("Moduł " . $module->getLabel() . " zakończył działanie.");
            } catch (\Throwable $e) {
                Console::error("Błąd w module " . $module->getLabel() . ": " . $e->getMessage() . "\n" . $e->getTraceAsString());
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

    /**
     * Zapisuje lokalnie obrazek informujący o wygaśnięciu tokenu.
     * Nie próbujemy go publikować przez Graph API — token jest nieważny,
     * więc próba publikacji i tak zakończy się błędem.
     */
    private function saveTokenExpiredAlert(string $reason): void
    {
        $body = (new Stack(gap: 30))
            ->add(new Text('Kręcą korbą', size: HEADER_SIZE, color: '#FF6666', font: FONT_BOLD, align: 'center', height: 90, marginTop: 570))
            ->add(new WrappedText($reason, size: 22, color: '#AAAAAA', font: FONT, indent: 40, widthPadding: 80, lineHeight: 30));

        $slide = new Slide(footer: Slide::source(date('Y-m-d H:i:s'), color: '#556688'));
        [$path, $url] = $slide->render($body)->save('token-expired');

        Console::warn("Zapisano lokalny alert o wygasłym tokenie: {$path}");
    }
}