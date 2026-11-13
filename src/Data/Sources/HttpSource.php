<?php
namespace Swagnar\Igapi\Data\Sources;

use Swagnar\Igapi\Data\DataSource;
use Swagnar\Igapi\Http\JsonClient;
use RuntimeException;

/**
 * Źródło opisane plikiem sources/<id>.json — nowy dodatek bez pisania PHP:
 *
 *   {
 *     "label": "NBP — kurs euro",
 *     "credit": "NBP",
 *     "url": "https://api.nbp.pl/api/exchangerates/rates/a/eur/",
 *     "params": { "format": "json", "api_key": "{{env.NASA_API_KEY}}", "date": "{{date:Y-m-d:-1 day}}" },
 *     "fields": { "rate": "rates.0.mid", "date": "rates.0.effectiveDate" },
 *     "maxFallbackAge": 86400,
 *     "docs": "https://api.nbp.pl/"
 *   }
 *
 * credit: dostawca do stopki slajdu ({{credits}}) — pokazywany, gdy dane źródła są na slajdzie.
 * fields: nazwa → ścieżka w odpowiedzi JSON (kropki, indeksy liczbowe). Brak pola w odpowiedzi = błąd,
 * żeby zmiana API była widoczna w edytorze, a nie jako pusty tekst na slajdzie. Bez "fields" → cała odpowiedź.
 */
final class HttpSource implements DataSource
{
    public function __construct(
        private readonly string $id,
        private readonly array $config,
        private readonly string $file,
        private readonly JsonClient $http,
    ) {
        if (empty($this->config['url'])) {
            throw new RuntimeException(basename($this->file) . ": brak pola 'url'");
        }
    }

    public static function fromFile(string $file, JsonClient $http): self
    {
        $config = json_decode((string) @file_get_contents($file), true);
        if (!is_array($config)) {
            throw new RuntimeException(basename($file) . ': nieprawidłowy JSON (' . json_last_error_msg() . ')');
        }
        return new self(basename($file, '.json'), $config, $file, $http);
    }

    public function id(): string { return $this->id; }

    public function label(): string { return $this->config['label'] ?? $this->id; }

    public function credit(): ?string { return $this->config['credit'] ?? null; }

    public function describe(): array
    {
        // Parametry w postaci z pliku — {{env.…}} zostaje nierozwinięte, więc klucze API nie trafiają do edytora
        return array_filter([
            'file'     => 'sources/' . basename($this->file),
            'endpoint' => $this->config['url'],
            'params'   => $this->config['params'] ?? null,
            'fields'   => $this->config['fields'] ?? null,
            'docs'     => $this->config['docs'] ?? null,
        ]);
    }

    public function maxFallbackAge(): ?int
    {
        return isset($this->config['maxFallbackAge']) ? (int) $this->config['maxFallbackAge'] : null;
    }

    public function fetch(): array
    {
        $params = array_map(fn($v) => $this->expand((string) $v), $this->config['params'] ?? []);
        $url    = $this->config['url'] . ($params ? (str_contains($this->config['url'], '?') ? '&' : '?') . http_build_query($params) : '');

        $response = $this->http->get($url);
        if (!isset($this->config['fields'])) {
            // Cała odpowiedź trafi do cache i edytora — bez kluczy, które API odsyła w linkach
            return JsonClient::maskSecretsIn($response);
        }

        $data = [];
        foreach ($this->config['fields'] as $name => $path) {
            $data[$name] = self::dig($response, (string) $path)
                ?? throw new RuntimeException("Brak pola '{$name}' (ścieżka {$path}) w odpowiedzi API");
        }
        return $data;
    }

    /** {{env.NAZWA}} → wartość z .env, {{date:FORMAT}} / {{date:FORMAT:modyfikator}} → data, np. {{date:Y-m-d:-1 day}} */
    private function expand(string $value): string
    {
        return preg_replace_callback('/\{\{\s*(env\.(\w+)|date:([^:}]+)(?::([^}]+))?)\s*\}\}/', function (array $m): string {
            if (($m[2] ?? '') !== '') {
                return $_ENV[$m[2]] ?? throw new RuntimeException("Brak {$m[2]} w .env");
            }
            return (new \DateTimeImmutable($m[4] ?? 'now'))->format($m[3]);
        }, $value);
    }

    private static function dig(mixed $value, string $path): mixed
    {
        foreach ($path === '' ? [] : explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
