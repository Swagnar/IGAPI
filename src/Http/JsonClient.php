<?php
namespace Swagnar\Igapi\Http;

use RuntimeException;

class JsonClient {
    /**
     * Pobiera dane z podanego URL za pomocą file_get_contents i zwraca je jako tablicę.
     * @param string $url URL do pobrania danych
     * @return array Dane w formie tablicy
     * @throws RuntimeException Jeśli nie można pobrać danych lub jeśli JSON jest nieprawidłowy
     */
    public function get(string $url): array
    {
        $json = file_get_contents($url);
        if ($json === false) {
            throw new RuntimeException("Nie udało się pobrać: $url");
        }
        $data = json_decode($json, true); 
        if (!is_array($data)) {
            throw new RuntimeException("Nieprawidłowy JSON z: $url");
        }
        return $data;
    }

    public function post(string $url, array $params): array
    {
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
        curl_close($ch);

        if($response === false) {
            throw new RuntimeException("CURL POST error: " . curl_error($ch));
        }

        $data = json_decode($response, true);
        if(!is_array($data)) {
            throw new RuntimeException("Nieprawidłowy JSON z: $url");
        }

        return [
            'http_code' => $httpCode,
            'data' => $data,
        ];
    }

    public function translate(string $text): string {
        $url  = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=pl&dt=t&q=' . urlencode($text);
        $data = $this->get($url);
        return $data[0][0][0] ?? $text;
    }

    public function getCurl(string $url): array 
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new RuntimeException("CURL error: " . curl_error($ch));
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException("Nieprawidłowy JSON z: $url");
        }

        return [
            'http_code' => $httpCode,
            'data' => $data,
        ];
    }
}