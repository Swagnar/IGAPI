<?php
namespace Swagnar\Igapi\Plugins;

use Swagnar\Igapi\Contracts\AbstractPlugin;

use Swagnar\Igapi\Http\JsonClient;

use Swagnar\Igapi\Helper\Console;

use Swagnar\Igapi\Layout\Component;
use Swagnar\Igapi\Layout\LineChart;
use Swagnar\Igapi\Layout\Spacer;
use Swagnar\Igapi\Layout\Stack;
use Swagnar\Igapi\Layout\Text;

class BitcoinPlugin extends AbstractPlugin
{
    private const BTC_FILE  = ROOT_DIR . '/btc_data.txt';

    public function __construct(
        private readonly JsonClient $http,
    )
    {
        Console::boot("BitcoinPlugin zainicjalizowany");
    }

    public function getLabel(): string { return 'Bitcoin'; }

    public function fetchData(): array {
        Console::plugin("BitcoinPlugin: pobieram dane...");
        $dataUrl  = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=usd&include_24hr_change=true';
        $response = $this->http->getCurl($dataUrl);
        $data     = $response['data'];

        $price = $data['bitcoin']['usd'] ?? null;

        if ($price !== null) {
            $this->appendIfNeeded((float)$price);
        }

        return [
            'price'  => $price,
            'change' => $data['bitcoin']['usd_24h_change'] ?? null,
            'history' => $this->loadHistory(),
        ];
    }

    protected function content(array $data): Component
    {
        $priceStr = $data['price'] !== null ? '$' . number_format((float)$data['price'], 0, ',', ' ') : 'N/A';
        $points   = array_map(fn($p) => ['x' => $p['ts'], 'y' => $p['price']], $data['history']);

        $stack = (new Stack())
            ->add(new Spacer(30))
            ->add(new Text('₿  Bitcoin', size: 24, color: '#8899CC', font: FONT, height: 40))
            ->add(new Text($priceStr, size: 48, color: '#FFFFFF', font: FONT_BOLD, height: 70));

        if ($data['change'] !== null) {
            $change = (float)$data['change'];
            $stack->add(new Text(
                sprintf('%s%s%% w ciągu 24h', $change >= 0 ? '+' : '', number_format($change, 1, ',', '')),
                size: 22, color: $change >= 0 ? '#34D399' : '#F87171', font: FONT, height: 36,
            ));
        }

        return $stack
            ->add(new Spacer(30))
            // Wykres dostaje resztę bounds — cała zebrana historia, od pierwszego wpisu w pliku
            ->add(new LineChart(
                $points,
                formatY:   fn(float $price) => '$' . number_format($price, 0, ',', ' '),
                formatX:   fn(int $ts) => date('d.m.Y', $ts),
                emptyText: 'Brak danych historycznych',
            ));
    }

    /**
     * Dopisuje nową cenę do pliku historii — o ile nie zapisano już wpisu w tej samej minucie
     * i o ile cena nie wygląda na błędny odczyt (nagły, nierealny skok względem ostatniej wartości).
     */
    private function appendIfNeeded(float $price): void
    {
        $file = self::BTC_FILE;

        if ($price <= 0) {
            Console::error("BitcoinPlugin: odrzucono nieprawidłową cenę ($price)");
            return;
        }

        $lastLine = '';
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false && count($lines) > 0) {
                $lastLine = trim(end($lines));
            }
        }

        // Nie dopisuj, jeśli ostatni zapis jest z tej samej minuty
        $lastTimestamp = substr($lastLine, 0, 16); // "2026-06-25 20:26"
        $nowTimestamp  = date('Y-m-d H:i');
        if ($lastTimestamp === $nowTimestamp) {
            Console::debug("BitcoinPlugin: wpis z tej minuty już istnieje, pomijam.");
            return;
        }

        // Ochrona przed błędnymi odczytami API — zbyt duże odchylenie od ostatniej ceny.
        // Tylko gdy ostatni wpis jest świeży — po dłuższej przerwie duża zmiana ceny jest realna
        // i bez tego warunku plik historii zablokowałby się na zawsze.
        if ($lastLine !== '' && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) ([\d.]+)$/', $lastLine, $m)) {
            $lastPrice = (float)$m[2];
            $isRecent  = time() - strtotime($m[1]) < 86400;
            if ($lastPrice > 0 && $isRecent) {
                $deltaPct = abs($price - $lastPrice) / $lastPrice * 100;
                if ($deltaPct > 20) {
                    Console::error("BitcoinPlugin: odrzucono cenę $price — odchylenie {$deltaPct}% od ostatniej ($lastPrice)");
                    return;
                }
            }
        }

        $line   = date('Y-m-d H:i:s') . ' ' . $price;
        $result = file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        if ($result === false) {
            Console::error("BitcoinPlugin: nie udało się zapisać do pliku $file");
            return;
        }

        Console::info("BitcoinPlugin: dopisano wpis: $line");
    }

    private function loadHistory(): array
    {
        $file = self::BTC_FILE;
        if (!file_exists($file)) return [];

        $lines  = array_filter(array_map('trim', file($file)));

        $points = [];
        foreach ($lines as $line) {
            // Format: "2026-06-25 20:26:27 59543.26415465172"
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) ([\d.]+)$/', $line, $m)) continue;
            $points[] = [
                'ts'    => strtotime($m[1]),
                'price' => (float)$m[2],
            ];
        }
        return $points;
    }
}
