<?php
namespace Swagnar\Igapi\Http;

use RuntimeException;

class JsonClient {
    /** Odpowiedzi większe niż to zapisujemy w nagraniu bez treści — cache ma zostać lekki */
    private const RECORD_MAX_BYTES = 262144;

    /** @var list<array>|null null = nie nagrywamy */
    private ?array $recording = null;

    /** Od teraz każde zapytanie trafia do nagrania (DataCache pokazuje je potem w edytorze). */
    public function startRecording(): void
    {
        $this->recording = [];
    }

    /** @return list<array{method: string, url: string, status: ?int, ms: int, bytes: int, error: ?string, body: mixed}> */
    public function stopRecording(): array
    {
        $calls = $this->recording ?? [];
        $this->recording = null;
        return $calls;
    }

    private function record(string $method, string $url, float $started, ?int $status, string|false $raw, ?string $error): void
    {
        if ($this->recording === null) {
            return;
        }

        $bytes = $raw === false ? 0 : strlen($raw);
        $body  = null;
        if ($raw !== false && $bytes <= self::RECORD_MAX_BYTES) {
            // API potrafią odesłać klucz w treści (NeoWs: links.next z api_key) — maskujemy przed zapisem
            $masked = self::maskSecrets($raw);
            $body   = json_decode($masked, true) ?? mb_substr($masked, 0, 2000);
        }

        $this->recording[] = [
            'method' => $method,
            'url'    => self::maskSecrets($url),
            'status' => $status,
            'ms'     => (int) round((microtime(true) - $started) * 1000),
            'bytes'  => $bytes,
            'error'  => $error,
            'body'   => $body,
        ];
    }

    /**
     * Pobiera dane z podanego URL za pomocą file_get_contents i zwraca je jako tablicę.
     * @param string $url URL do pobrania danych
     * @param list<string> $headers dodatkowe nagłówki, np. 'Authorization: Bearer …'
     * @return array Dane w formie tablicy
     * @throws RuntimeException Jeśli nie można pobrać danych lub jeśli JSON jest nieprawidłowy
     */
    public function get(string $url, array $headers = []): array
    {
        $started = microtime(true);
        $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true, 'header' => implode("\r\n", $headers)]]);
        $json    = @file_get_contents($url, false, $context);
        $status  = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : null;

        if ($json === false || ($status !== null && $status >= 400)) {
            $error = "Nie udało się pobrać: " . self::maskSecrets($url) . ($status ? " (HTTP {$status})" : '');
            $this->record('GET', $url, $started, $status, $json, $error);
            throw new RuntimeException($error);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->record('GET', $url, $started, $status, $json, 'Nieprawidłowy JSON');
            throw new RuntimeException("Nieprawidłowy JSON z: " . self::maskSecrets($url));
        }
        $this->record('GET', $url, $started, $status, $json, null);
        return $data;
    }

    /** Jak get(), ale zwraca surowy tekst — dla źródeł w CSV albo TXT. */
    public function getText(string $url): string
    {
        $started = microtime(true);
        $context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true, 'user_agent' => 'IGAPI']]);
        $text    = @file_get_contents($url, false, $context);
        $status  = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : null;

        if ($text === false || $text === '' || ($status !== null && $status >= 400)) {
            $error = "Nie udało się pobrać: " . self::maskSecrets($url) . ($status ? " (HTTP {$status})" : '');
            $this->record('GET', $url, $started, $status, $text, $error);
            throw new RuntimeException($error);
        }
        $this->record('GET', $url, $started, $status, $text, null);
        return $text;
    }

    /**
     * Ukrywa tokeny/klucze API w URL-ach i treści odpowiedzi, żeby nie trafiały do logów ani do cache:
     * parametry access_token/api_key oraz dosłowne wartości sekretów z .env (*KEY*, *TOKEN*, *SECRET*).
     */
    public static function maskSecrets(string $text): string
    {
        $text = preg_replace('/(access_token|api_key)=[^&"\s]*/', '$1=***', $text);

        foreach ($_ENV as $name => $value) {
            if (is_string($value) && strlen($value) >= 8 && preg_match('/KEY|TOKEN|SECRET/i', $name)) {
                $text = str_replace($value, '***', $text);
            }
        }
        return $text;
    }

    /** maskSecrets dla wszystkich tekstów w zdekodowanej odpowiedzi. */
    public static function maskSecretsIn(array $data): array
    {
        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                $value = self::maskSecrets($value);
            }
        });
        return $data;
    }

    public function post(string $url, array $params): array
    {
        $started = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if($response === false) {
            $this->record('POST', $url, $started, null, false, $curlErr);
            throw new RuntimeException("CURL POST error: " . $curlErr);
        }

        $data = json_decode($response, true);
        $this->record('POST', $url, $started, $httpCode, $response, is_array($data) ? null : 'Nieprawidłowy JSON');
        if(!is_array($data)) {
            throw new RuntimeException("Nieprawidłowy JSON z: " . self::maskSecrets($url));
        }

        return [
            'http_code' => $httpCode,
            'data' => $data,
        ];
    }

    public function translate(string $text): string {
        return \Swagnar\Igapi\Helper\GdImageHelper::translateToPolish($text);
    }

    public function getCurl(string $url): array 
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);

        $started  = microtime(true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $this->record('GET', $url, $started, null, false, curl_error($ch));
            throw new RuntimeException("CURL error: " . curl_error($ch));
        }

        $data = json_decode($response, true);
        $this->record('GET', $url, $started, $httpCode, $response, is_array($data) ? null : 'Nieprawidłowy JSON');

        if (!is_array($data)) {
            throw new RuntimeException("Nieprawidłowy JSON z: " . self::maskSecrets($url));
        }

        return [
            'http_code' => $httpCode,
            'data' => $data,
        ];
    }
}