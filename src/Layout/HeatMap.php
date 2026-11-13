<?php
namespace Swagnar\Igapi\Layout;

use Swagnar\Igapi\Contracts\Bounds;
use Swagnar\Igapi\Helper\GdImageHelper;
use GdImage;

/**
 * Mapa cieplna obszaru (np. Polski albo stref cenowych Europy): wartości z punktów interpolowane metodą IDW
 * wewnątrz wskazanych wielokątów, kolory z palety z przezroczystością, punkty z etykietami, które
 * omijają siebie nawzajem i trzymają się wnętrza obszaru.
 *
 *   new HeatMap(
 *       points:  [['lon' => 21.0, 'lat' => 52.2, 'value' => 9.5, 'label' => 'Warszawa 10°C', 'sub' => '85%', 'color' => '#88dd00']],
 *       area:    [$polandRing],                 // wielokąty, w których rysujemy ciepło (płaskie [lon, lat, lon, lat, …])
 *       palette: [[0, [200, 230, 255], 90], [20, [100, 200, 100], 110], [35, [255, 80, 80], 90]],
 *       view:    [14.1, 24.2, 49.0, 54.9],      // lonMin, lonMax, latMin, latMax
 *   )
 *
 * Wielokąty w `land` to tło (np. kraje bez danych) — rysowane neutralnie, bez koloru wartości.
 *
 * `area` i `land` mogą też być nazwą konturów — tak podaje się je w szablonach JSON:
 *   "poland" (geo/poland-border.php), "europe:PL,DE" (te kraje z geo/europe.json), "europe:!PL,DE" (wszystkie poza nimi)
 *
 * `paths` to ślady na mapie (np. przelot ISS): punkty [lon, lat] albo [lon, lat, 1/0] — 1 = część wyróżniona
 * (jasna, gruba linia ze strzałką kierunku na końcu — biała albo w kolorze `core`), 0 = reszta śladu, przygaszona. Opcjonalny `label`
 * (np. numer przelotu) stoi przy początku wyróżnionej części. Przygaszona część ma grubość `width` (domyślnie 2)
 * i przezroczystość `alpha` (0–127, domyślnie 85) — przy tysiącach śladów (loty nad Polską) wyższa alfa robi z nich mapę gęstości.
 *
 * `glow` to zorza od północnej krawędzi mapy do szerokości `lat`: {lat, strength 0–1} — pionowe kurtyny,
 * zielone na dole, fioletowe u góry, tylko wewnątrz obszaru z danymi.
 */
final class HeatMap implements Component
{
    /** IDW liczone na siatce co tyle pikseli i wygładzane przy powiększaniu — przy ~30 punktach to kilka razy szybciej */
    private const GRID = 3;

    /**
     * @param list<array{lon: float, lat: float, value: float, label?: string, sub?: string, color?: string, priority?: bool}> $points
     * @param list<list<float>>|string $area wielokąty z ciepłem albo nazwa konturów (patrz wyżej)
     * @param list<list<float>>|string $land wielokąty tła (ląd bez danych) albo nazwa konturów
     * @param list<list<float>> $outlines dodatkowe linie (granice) rysowane cienko
     * @param list<array{0: float, 1: array{0: int, 1: int, 2: int}, 2: int}> $palette [wartość, [r, g, b], alfa 0–127]
     * @param array{0: float, 1: float, 2: float, 3: float} $view
     * @param string $projection 'plate' (równoodległościowe ze skalą cos φ) | 'laea' (Lamberta, równopolowe — dla Europy)
     * @param list<array{points: list<array{0: float, 1: float, 2?: int}>, label?: ?string, color?: string, core?: ?string, width?: int, alpha?: int}> $paths
     */
    public function __construct(
        private readonly array $points,
        private readonly array|string $area,
        private readonly array $palette,
        private readonly array $view,
        private readonly array|string $land = [],
        private readonly array $outlines = [],
        private readonly string $projection = 'plate',
        private readonly ?int $maxHeight = null,
        private readonly float $power = 2.0,
        private readonly int $labelSize = 22,
        private readonly int $subSize = 16,
        private readonly string $areaOutline = '#E6E6E6',
        private readonly string $landColor = '#1A2440',
        private readonly string $lineColor = '#5A6B8F',
        private readonly array $paths = [],
        private readonly string $pathColor = '#7DD3FC',
        private readonly ?array $glow = null,
    ) {}

    public function render(GdImage $img, Bounds $bounds): int
    {
        $area = self::geometry($this->area);
        $land = self::geometry($this->land);
        [$lonMin, $lonMax, $latMin, $latMax] = $this->view;
        $lon0 = ($lonMin + $lonMax) / 2;
        $lat0 = ($latMin + $latMax) / 2;
        $raw  = fn(float $lon, float $lat): array => $this->project($lon, $lat, $lon0, $lat0);

        // Zasięg widoku po rzutowaniu — z krawędzi prostokąta lon/lat (w Lambercie to krzywe)
        $xs = $ys = [];
        for ($i = 0; $i <= 20; $i++) {
            foreach ([[$lonMin + ($lonMax - $lonMin) * $i / 20, $latMin], [$lonMin + ($lonMax - $lonMin) * $i / 20, $latMax],
                      [$lonMin, $latMin + ($latMax - $latMin) * $i / 20], [$lonMax, $latMin + ($latMax - $latMin) * $i / 20]] as [$lo, $la]) {
                [$x, $y] = $raw($lo, $la);
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        $spanX = max($xs) - min($xs);
        $spanY = max($ys) - min($ys);
        $maxH  = min($bounds->height, $this->maxHeight ?? PHP_INT_MAX);
        $scale = min($bounds->width / $spanX, $maxH / $spanY);
        $w     = (int) round($spanX * $scale);
        $h     = (int) round($spanY * $scale);
        $ox    = $bounds->x + intdiv($bounds->width - $w, 2);
        $oy    = $bounds->y;
        $minX  = min($xs);
        $maxY  = max($ys);
        $to    = function (float $lon, float $lat) use ($raw, $scale, $minX, $maxY): array {
            [$x, $y] = $raw($lon, $lat);
            return [(int) round(($x - $minX) * $scale), (int) round(($maxY - $y) * $scale)];
        };
        $flat = fn(array $ring): array => array_merge(...array_map(fn($p) => $to($p[0], $p[1]), array_chunk($ring, 2)));

        // Ląd w tle (np. Rosja, Afryka) sięga daleko poza widok — wszystko poza prostokątem mapy przycinamy
        imagesetclip($img, $ox, $oy, $ox + $w, $oy + $h);

        // Tło: ląd bez danych
        $layerLand = GdImageHelper::hexToGdColor($img, $this->landColor);
        foreach ($land as $ring) {
            if (count($ring) >= 6) {
                imagefilledpolygon($img, $this->offset($flat($ring), $ox, $oy), $layerLand);
            }
        }

        // Maska obszaru z danymi (biały = rysujemy ciepło)
        $mask  = imagecreatetruecolor($w + 1, $h + 1);
        $white = imagecolorallocate($mask, 255, 255, 255);
        foreach ($area as $ring) {
            if (count($ring) >= 6) {
                imagefilledpolygon($mask, $flat($ring), $white);
            }
        }

        $points = [];
        foreach ($this->points as $p) {
            [$x, $y]  = $to((float) $p['lon'], (float) $p['lat']);
            $points[] = $p + ['x' => $x, 'y' => $y];
        }

        if ($points) {
            $this->drawHeat($img, $mask, $points, $w, $h, $ox, $oy);
        }
        if ($this->glow !== null) {
            $this->drawGlow($img, $mask, $this->glow, $to($lon0, (float) $this->glow['lat'])[1], $w, $h, $ox, $oy);
        }

        // Granice: cienkie linie (np. państw), potem wyraźny obrys obszaru z danymi
        imagesetthickness($img, 1);
        $line = GdImageHelper::hexToGdColor($img, $this->lineColor);
        foreach (array_merge($this->outlines, $land) as $ring) {
            if (count($ring) >= 4) {
                $this->polyline($img, $this->offset($flat($ring), $ox, $oy), $line);
            }
        }
        imagesetthickness($img, 2);
        $edge = GdImageHelper::hexToGdColor($img, $this->areaOutline);
        foreach ($area as $ring) {
            if (count($ring) >= 6) {
                imagepolygon($img, $this->offset($flat($ring), $ox, $oy), $edge);
            }
        }
        imagesetthickness($img, 1);

        $pathLabels = [];
        foreach ($this->paths as $path) {
            $pathLabels[] = $this->drawPath($img, $path, $to, $ox, $oy, $w, $h);
        }
        imagesetclip($img, 0, 0, imagesx($img) - 1, imagesy($img) - 1);

        $this->drawPathLabels($img, array_filter($pathLabels));
        $this->drawLabels($img, $mask, $points, $ox, $oy);
        imagedestroy($mask);

        return $h;
    }

    /** Lista płaskich pierścieni [lon, lat, …] z tablicy albo z nazwy konturów. */
    public static function geometry(array|string $spec): array
    {
        if (is_array($spec)) {
            return $spec;
        }
        if ($spec === 'poland') {
            $border = require ROOT_DIR . '/geo/poland-border.php';
            return [array_merge(...array_map(fn($p) => [(float) $p[1], (float) $p[0]], $border))];
        }
        if (preg_match('/^europe:(!?)([A-Z,]*)$/', $spec, $m)) {
            static $europe = null;
            $europe ??= json_decode((string) file_get_contents(ROOT_DIR . '/geo/europe.json'), true)['countries'];
            $codes = array_filter(explode(',', $m[2]));
            $isos  = $m[1] === '!' ? array_diff(array_keys($europe), $codes) : $codes;
            $rings = [];
            foreach ($isos as $iso) {
                foreach ($europe[$iso]['polygons'] ?? [] as $polygon) {
                    $rings[] = $polygon[0];
                }
            }
            return $rings;
        }
        throw new \InvalidArgumentException("HeatMap: nieznane kontury '{$spec}' (dostępne: poland, europe:KODY, europe:!KODY)");
    }

    private function project(float $lon, float $lat, float $lon0, float $lat0): array
    {
        if ($this->projection === 'laea') {
            $phi  = deg2rad($lat);
            $phi0 = deg2rad($lat0);
            $dl   = deg2rad($lon - $lon0);
            $k    = sqrt(2 / (1 + sin($phi0) * sin($phi) + cos($phi0) * cos($phi) * cos($dl)));
            return [$k * cos($phi) * sin($dl), $k * (cos($phi0) * sin($phi) - sin($phi0) * cos($phi) * cos($dl))];
        }
        return [deg2rad($lon - $lon0) * cos(deg2rad($lat0)), deg2rad($lat)];
    }

    /**
     * Zorza od górnej krawędzi do linii $edgeY (lekko falującej), tylko wewnątrz obszaru z danymi. Na dole zieleń,
     * wyżej fiolet — jak w prawdziwej zorzy, której dolna krawędź świeci na zielono, a górne partie na fioletowo.
     */
    private function drawGlow(GdImage $img, GdImage $mask, array $glow, int $edgeY, int $w, int $h, int $ox, int $oy): void
    {
        // Rysujemy na przezroczystej warstwie, a na mapę przenosimy tylko piksele wewnątrz maski (jak ciepło)
        $layer = imagecreatetruecolor($w + 1, $h + 1);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefilledrectangle($layer, 0, 0, $w, $h, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagealphablending($layer, true);
        $this->paintGlow($layer, $glow, $edgeY, $w, 0, 0);

        for ($y = 0; $y <= min($h, $edgeY + 30); $y++) {
            for ($x = 0; $x <= $w; $x++) {
                $c = imagecolorat($layer, $x, $y);
                if (($c >> 24 & 0x7F) < 127 && (imagecolorat($mask, $x, $y) & 0xFF) >= 200) {
                    imagesetpixel($img, $ox + $x, $oy + $y, $c);
                }
            }
        }
        imagedestroy($layer);
    }

    /**
     * Kurtyny zorzy: pionowe promienie różnej jasności i długości zwisające ku linii widoczności. Dół promienia
     * wchodzi miękko (smoothstep na FADE px), a końce są rozrzucone — bez ostrej krawędzi.
     */
    private function paintGlow(GdImage $img, array $glow, int $edgeY, int $w, int $ox, int $oy): void
    {
        $strength = max(0.0, min(1.0, (float) ($glow['strength'] ?? 0.7)));
        $green    = [70, 255, 150];
        $violet   = [170, 90, 255];
        $mix      = fn(float $k) => array_map(fn($a, $b) => (int) round($a + ($b - $a) * max(0, min(1, $k))), $green, $violet);
        $edge     = fn(int $x): int => $edgeY + (int) round(9 * sin($x / 63) + 5 * sin($x / 21 + 1.3));
        // Deterministyczny „szum” 0–1 dla kolumny — kurtyny wyglądają tak samo przy każdym renderze
        $noise    = fn(float $v): float => fmod(abs(sin($v * 12.9898) * 43758.5453), 1);
        $fade     = 30;

        for ($x = 0; $x < $w; $x += 2) {
            $bright = 0.35 + 0.65 * (0.5 + 0.5 * sin($x / 37 + 2 * $noise(intdiv($x, 6)))) * (0.6 + 0.4 * $noise($x));
            $bottom = $edge($x) + (int) round(22 * ($noise($x + 7) - 0.3));
            $top    = (int) round($bottom * (0.15 + 0.6 * $noise($x / 9 + 3)));
            for ($y = max(0, $top); $y < $bottom; $y++) {
                $t     = ($bottom - $y) / max(1, $bottom - $top);    // 0 na dole promienia, 1 u góry
                $d     = min(1, ($bottom - $y) / $fade);
                $soft  = $d * $d * (3 - 2 * $d);                     // smoothstep — miękki dół
                $alpha = 127 - (int) round(127 * $strength * $bright * (1 - $t) ** 0.7 * $soft);
                [$r, $g, $b] = $mix($t);
                imageline($img, $ox + $x, $oy + $y, $ox + $x + 1, $oy + $y, imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $alpha))));
            }
        }
    }

    /** IDW na rzadkiej siatce → wygładzone powiększenie → przeniesienie na obraz tylko wewnątrz maski. */
    private function drawHeat(GdImage $img, GdImage $mask, array $points, int $w, int $h, int $ox, int $oy): void
    {
        $gw   = intdiv($w, self::GRID) + 2;
        $gh   = intdiv($h, self::GRID) + 2;
        $grid = imagecreatetruecolor($gw, $gh);
        imagealphablending($grid, false);
        imagesavealpha($grid, true);
        for ($gy = 0; $gy < $gh; $gy++) {
            for ($gx = 0; $gx < $gw; $gx++) {
                [$r, $g, $b, $a] = $this->color($this->idw($gx * self::GRID, $gy * self::GRID, $points));
                imagesetpixel($grid, $gx, $gy, imagecolorallocatealpha($grid, $r, $g, $b, $a));
            }
        }

        $layer = imagecreatetruecolor($w + 1, $h + 1);
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagecopyresampled($layer, $grid, 0, 0, 0, 0, ($gw - 1) * self::GRID, ($gh - 1) * self::GRID, $gw - 1, $gh - 1);
        imagedestroy($grid);

        for ($y = 0; $y <= $h; $y++) {
            for ($x = 0; $x <= $w; $x++) {
                if ((imagecolorat($mask, $x, $y) & 0xFF) >= 200) {
                    imagesetpixel($img, $ox + $x, $oy + $y, imagecolorat($layer, $x, $y));
                }
            }
        }
        imagedestroy($layer);
    }

    private function idw(int $x, int $y, array $points): float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($points as $p) {
            $d2 = ($x - $p['x']) ** 2 + ($y - $p['y']) ** 2;
            if ($d2 < 1) {
                return (float) $p['value'];
            }
            $weight = 1 / ($this->power === 2.0 ? $d2 : $d2 ** ($this->power / 2));
            $num   += $weight * $p['value'];
            $den   += $weight;
        }
        return $den > 0 ? $num / $den : 0.0;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} r, g, b, alfa — liniowo między przystankami palety */
    public function color(float $value): array
    {
        $palette = $this->palette;
        if ($value <= $palette[0][0]) {
            return [...$palette[0][1], $palette[0][2]];
        }
        $last = $palette[count($palette) - 1];
        if ($value >= $last[0]) {
            return [...$last[1], $last[2]];
        }
        for ($i = 0; $i < count($palette) - 1; $i++) {
            [$v1, $c1, $a1] = $palette[$i];
            [$v2, $c2, $a2] = $palette[$i + 1];
            if ($value >= $v1 && $value <= $v2) {
                $k = $v2 > $v1 ? ($value - $v1) / ($v2 - $v1) : 0;
                return [
                    (int) round($c1[0] + ($c2[0] - $c1[0]) * $k),
                    (int) round($c1[1] + ($c2[1] - $c1[1]) * $k),
                    (int) round($c1[2] + ($c2[2] - $c1[2]) * $k),
                    (int) round($a1 + ($a2 - $a1) * $k),
                ];
            }
        }
        return [255, 255, 255, 100];
    }

    /**
     * Punkty i etykiety: kilka przejść, w których każda etykieta wybiera najlepszy narożnik względem pozostałych.
     * Etykieta, która mimo to na coś nachodzi, jest pomijana — chyba że ma priority (np. Polska na mapie Europy).
     */
    private function drawLabels(GdImage $img, GdImage $mask, array $points, int $ox, int $oy): void
    {
        $labelled = array_values(array_filter($points, fn($p) => ($p['label'] ?? '') !== ''));
        if (!$labelled) {
            return;
        }

        $labels = array_map(function (array $p): array {
            $sub = $p['sub'] ?? null;
            return [
                'w' => max($this->textWidth($p['label'], $this->labelSize), $sub !== null ? $this->textWidth($sub, $this->subSize) : 0),
                'h' => $sub !== null ? $this->labelSize + $this->subSize + 14 : $this->labelSize + 6,
            ];
        }, $labelled);

        $dots      = array_map(fn($p) => [$p['x'] - 6, $p['y'] - 6, $p['x'] + 6, $p['y'] + 6], $labelled);
        $boxOf     = fn(array $pos, array $l) => [$pos[0] - 6, $pos[1] - 4, $pos[0] + $l['w'] + 6, $pos[1] + $l['h'] + 4];
        $positions = array_map(fn($p, $l) => [$p['x'] + 12, $p['y'] - $l['h'] - 4], $labelled, $labels);
        $scores    = array_fill(0, count($labelled), 0);

        // Etykiety z priority wybierają pierwsze — reszta się do nich dopasowuje
        $order = array_keys($labelled);
        usort($order, fn($a, $b) => (int) !empty($labelled[$b]['priority']) <=> (int) !empty($labelled[$a]['priority']));

        for ($pass = 0; $pass < 4; $pass++) {
            $changed = false;
            foreach ($order as $i) {
                $others = $dots;
                foreach ($positions as $j => $pos) {
                    if ($j !== $i && $scores[$j] >= 0) {
                        $others[] = $boxOf($pos, $labels[$j]);
                    }
                }
                [$best, $score] = $this->bestLabelPosition($mask, $labelled[$i]['x'], $labelled[$i]['y'], $labels[$i]['w'], $labels[$i]['h'], $others);
                $changed       = $changed || $best !== $positions[$i];
                $positions[$i] = $best;
                $scores[$i]    = !empty($labelled[$i]['priority']) ? max(0, $score) : $score;
            }
            if (!$changed) {
                break;
            }
        }

        $dot     = imagecolorallocate($img, 255, 255, 255);
        $labelBg = imagecolorallocatealpha($img, 8, 12, 32, 40);
        foreach ($labelled as $i => $p) {
            imagefilledellipse($img, $ox + $p['x'], $oy + $p['y'], 8, 8, $dot);
            if ($scores[$i] < 0) {
                continue;
            }
            $l         = $labels[$i];
            [$lx, $ly] = [$positions[$i][0] + $ox, $positions[$i][1] + $oy];
            [$x1, $y1, $x2, $y2] = $boxOf([$lx, $ly], $l);
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $labelBg);
            if (isset($p['sub'])) {
                GdImageHelper::drawText($img, $p['sub'], $lx, $ly + $this->subSize + 2, $this->subSize, '#7FA8C9', FONT);
            }
            GdImageHelper::drawText($img, $p['label'], $lx, $ly + $l['h'] - 4, $this->labelSize, $p['color'] ?? '#FFFFFF', FONT);
        }
    }

    /**
     * Narożnik (prawo/lewo × nad/pod punktem), w którym ramka etykiety najbardziej leży wewnątrz obszaru.
     * Nachodzenie na inne etykiety lub punkty: −100 za każdą przeszkodę.
     *
     * @return array{0: array{0: int, 1: int}, 1: int} [lewy górny róg, wynik]
     */
    private function bestLabelPosition(GdImage $mask, int $x, int $y, int $w, int $h, array $obstacles): array
    {
        $candidates = [
            [$x + 12,      $y - $h - 4],
            [$x + 12,      $y + 8],
            [$x - 12 - $w, $y - $h - 4],
            [$x - 12 - $w, $y + 8],
        ];
        $mw = imagesx($mask);
        $mh = imagesy($mask);

        $best      = $candidates[0];
        $bestScore = PHP_INT_MIN;
        foreach ($candidates as [$cx, $cy]) {
            $score = 0;
            for ($sx = 0; $sx <= 4; $sx++) {
                for ($sy = 0; $sy <= 2; $sy++) {
                    $px = $cx + intdiv($w * $sx, 4);
                    $py = $cy + intdiv($h * $sy, 2);
                    if ($px >= 0 && $py >= 0 && $px < $mw && $py < $mh && (imagecolorat($mask, $px, $py) & 0xFF) >= 200) {
                        $score++;
                    }
                }
            }
            if ($cx < 0 || $cy < 0 || $cx + $w > $mw || $cy + $h > $mh) {
                $score -= 100; // poza mapą
            }
            foreach ($obstacles as [$ox1, $oy1, $ox2, $oy2]) {
                if ($cx - 6 < $ox2 && $cx + $w + 6 > $ox1 && $cy - 4 < $oy2 && $cy + $h + 4 > $oy1) {
                    $score -= 100;
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [[$cx, $cy], $score];
            }
        }
        return [$best, $bestScore];
    }

    /**
     * Ślad: najpierw cały przygaszony, potem wyróżnione odcinki z poświatą i strzałka na końcu.
     * Odcinki przeskakujące przez antypołudnik (|Δlon| > 180°) są pomijane.
     *
     * @return ?array{0: int, 1: int, 2: string, 3: string} [x, y, etykieta, kolor] przy początku wyróżnionej części
     */
    private function drawPath(GdImage $img, array $path, callable $to, int $ox, int $oy, int $w, int $h): ?array
    {
        $pts = [];
        foreach ($path['points'] ?? [] as $p) {
            [$x, $y] = $to((float) $p[0], (float) $p[1]);
            $pts[]   = [$x + $ox, $y + $oy, (float) $p[0], (int) ($p[2] ?? 1)];
        }
        if (count($pts) < 2) {
            return null;
        }
        [$r, $g, $b] = sscanf($path['color'] ?? $this->pathColor, '#%02x%02x%02x');
        [$cr, $cg, $cb] = sscanf($path['core'] ?? '#FFFFFF', '#%02x%02x%02x');

        $segments = function (bool $highlighted) use ($pts): \Generator {
            for ($i = 1, $n = count($pts); $i < $n; $i++) {
                [$x1, $y1, $lon1, $h1] = $pts[$i - 1];
                [$x2, $y2, $lon2, $h2] = $pts[$i];
                if (abs($lon2 - $lon1) <= 180 && (!$highlighted || ($h1 && $h2))) {
                    yield [$x1, $y1, $x2, $y2];
                }
            }
        };

        imagesetthickness($img, (int) ($path['width'] ?? 2));
        $dim = imagecolorallocatealpha($img, $r, $g, $b, (int) ($path['alpha'] ?? 85));
        foreach ($segments(false) as [$x1, $y1, $x2, $y2]) {
            imageline($img, $x1, $y1, $x2, $y2, $dim);
        }

        $lit = array_values(array_filter($pts, fn($p) => $p[3]));
        if (count($lit) < 2) {
            imagesetthickness($img, 1);
            return null;
        }
        foreach ([[12, 112], [7, 90], [4, 0]] as [$width, $alpha]) {
            imagesetthickness($img, $width);
            $color = imagecolorallocatealpha($img, $alpha ? $r : $cr, $alpha ? $g : $cg, $alpha ? $b : $cb, $alpha);
            foreach ($segments(true) as [$x1, $y1, $x2, $y2]) {
                imageline($img, $x1, $y1, $x2, $y2, $color);
            }
        }
        imagesetthickness($img, 1);

        // Strzałka na końcu wyróżnionej części — kierunek z ostatnich kilku punktów (pojedynczy odcinek bywa krótki)
        [$ex, $ey] = end($lit);
        [$sx, $sy] = $lit[max(0, count($lit) - 4)];
        $angle     = atan2($ey - $sy, $ex - $sx);
        $head      = [];
        foreach ([0, 2.5, -2.5] as $k => $turn) {
            $len    = $k === 0 ? 14 : 16;
            $head[] = (int) round($ex + ($k === 0 ? $len * cos($angle) : $len * cos($angle + $turn)));
            $head[] = (int) round($ey + ($k === 0 ? $len * sin($angle) : $len * sin($angle + $turn)));
        }
        imagefilledpolygon($img, $head, imagecolorallocate($img, $cr, $cg, $cb));

        // Numer przy pierwszym punkcie na mapie — wyróżnionym, jeśli któryś się mieści
        $label = $path['label'] ?? null;
        if ($label === null || $label === '') {
            return null;
        }
        $inside = fn($p) => $p[0] >= $ox + 20 && $p[1] >= $oy + 20 && $p[0] <= $ox + $w - 20 && $p[1] <= $oy + $h - 20;
        $anchor = array_values(array_filter($lit, $inside))[0] ?? array_values(array_filter($pts, $inside))[0] ?? null;
        return $anchor === null ? null : [$anchor[0], $anchor[1], (string) $label, sprintf('#%02X%02X%02X', $r, $g, $b)];
    }

    /** Numery przy początkach śladów, w kółku w kolorze śladu. */
    private function drawPathLabels(GdImage $img, array $labels): void
    {
        foreach ($labels as [$x, $y, $text, $color]) {
            imagefilledellipse($img, $x, $y, 36, 36, GdImageHelper::hexToGdColor($img, $color));
            $tw = $this->textWidth($text, 18);
            GdImageHelper::drawText($img, $text, $x - intdiv($tw, 2) - 1, $y + 9, 18, '#0B1426', FONT_BOLD);
        }
    }

    private function offset(array $flat, int $ox, int $oy): array
    {
        foreach ($flat as $i => $v) {
            $flat[$i] = $v + ($i % 2 === 0 ? $ox : $oy);
        }
        return $flat;
    }

    private function polyline(GdImage $img, array $flat, int $color): void
    {
        for ($i = 2, $n = count($flat); $i + 1 < $n; $i += 2) {
            imageline($img, $flat[$i - 2], $flat[$i - 1], $flat[$i], $flat[$i + 1], $color);
        }
    }

    private function textWidth(string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, FONT, $text);
        return $box[2] - $box[0];
    }
}
