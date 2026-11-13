<?php
namespace Swagnar\Igapi\Helper;

/**
 * Położenie satelity na niskiej orbicie z elementów TLE (format GP z CelesTrak) — uproszczone SGP4:
 * mean motion po korekcie Brouwera, wiekowe zmiany węzła, perygeum i anomalii od J2 oraz opór przez ṅ.
 * Bez krótkookresowych zaburzeń i głębokiego kosmosu, więc tylko dla LEO i do ~1–2 dni od epoki TLE —
 * dla ISS błąd to kilka–kilkanaście km i kilka sekund, czyli dokładnie tyle, ile trzeba na mapę i godzinę przelotu.
 *
 * Do tego pozycja Słońca (wzór niskiej precyzji) — żeby wiedzieć, czy satelita jest oświetlony, a niebo ciemne.
 */
final class Orbit
{
    private const KE      = 0.0743669161;     // √(GM) w (promień Ziemi)^1.5 / min
    private const K2      = 5.413080e-4;      // ½·J2
    private const RE      = 6378.137;         // promień równikowy, km
    private const F       = 1 / 298.257223563; // spłaszczenie WGS84
    private const MINUTES = 1440.0;

    private float $n;      // mean motion Brouwera, rad/min
    private float $a;      // półoś wielka, promienie Ziemi
    private float $mDot;
    private float $wDot;
    private float $oDot;
    private float $epoch;  // unix timestamp

    /**
     * @param array{EPOCH: string, MEAN_MOTION: float, ECCENTRICITY: float, INCLINATION: float, RA_OF_ASC_NODE: float,
     *              ARG_OF_PERICENTER: float, MEAN_ANOMALY: float, MEAN_MOTION_DOT: float} $gp
     */
    public function __construct(private readonly array $gp)
    {
        $this->epoch = (float) (new \DateTimeImmutable($gp['EPOCH'], new \DateTimeZone('UTC')))->format('U.u');
        $e    = (float) $gp['ECCENTRICITY'];
        $cosi = cos(deg2rad((float) $gp['INCLINATION']));
        $n0   = (float) $gp['MEAN_MOTION'] * 2 * M_PI / self::MINUTES;

        // TLE podaje mean motion Kozaia — SGP4 odzyskuje z niego oryginalne n i a (Brouwera)
        $beta  = (1 - $e * $e) ** 1.5;
        $a1    = (self::KE / $n0) ** (2 / 3);
        $d1    = 1.5 * self::K2 * (3 * $cosi ** 2 - 1) / $beta / $a1 ** 2;
        $a0    = $a1 * (1 - $d1 / 3 - $d1 ** 2 - 134 / 81 * $d1 ** 3);
        $d0    = 1.5 * self::K2 * (3 * $cosi ** 2 - 1) / $beta / $a0 ** 2;
        $this->n = $n0 / (1 + $d0);
        $this->a = $a0 / (1 - $d0);

        $p2         = ($this->a * (1 - $e * $e)) ** 2;
        $this->mDot = $this->n * (1 + 1.5 * self::K2 * (3 * $cosi ** 2 - 1) * sqrt(1 - $e * $e) / $p2);
        $this->wDot = $this->n * 1.5 * self::K2 * (5 * $cosi ** 2 - 1) / $p2;
        $this->oDot = -$this->n * 3 * self::K2 * $cosi / $p2;
    }

    public function epoch(): int
    {
        return (int) $this->epoch;
    }

    /** Pozycja w układzie inercjalnym (TEME), km. */
    public function eci(float $ts): array
    {
        $t = ($ts - $this->epoch) / 60;   // minuty od epoki
        $e = (float) $this->gp['ECCENTRICITY'];
        $i = deg2rad((float) $this->gp['INCLINATION']);

        // ṅ/2 z TLE w obr/dzień² — opór przyspiesza satelitę, anomalia rośnie kwadratowo
        $drag = 2 * M_PI * (float) $this->gp['MEAN_MOTION_DOT'] * ($t / self::MINUTES) ** 2;
        $m    = deg2rad((float) $this->gp['MEAN_ANOMALY']) + $this->mDot * $t + $drag;
        $w    = deg2rad((float) $this->gp['ARG_OF_PERICENTER']) + $this->wDot * $t;
        $o    = deg2rad((float) $this->gp['RA_OF_ASC_NODE']) + $this->oDot * $t;

        $E = $m;
        for ($k = 0; $k < 8; $k++) {
            $E -= ($E - $e * sin($E) - $m) / (1 - $e * cos($E));
        }
        $nu = 2 * atan2(sqrt(1 + $e) * sin($E / 2), sqrt(1 - $e) * cos($E / 2));
        $r  = $this->a * (1 - $e * cos($E)) * self::RE;
        $u  = $w + $nu;

        return [
            $r * (cos($o) * cos($u) - sin($o) * sin($u) * cos($i)),
            $r * (sin($o) * cos($u) + cos($o) * sin($u) * cos($i)),
            $r * sin($u) * sin($i),
        ];
    }

    /** Punkt pod satelitą: [lon, lat] w stopniach (WGS84). */
    public function subpoint(float $ts): array
    {
        [$x, $y, $z] = $this->eci($ts);
        $lon = rad2deg(atan2($y, $x) - self::gmst($ts));
        $lon = fmod($lon + 540, 360) - 180;

        $p   = sqrt($x * $x + $y * $y);
        $e2  = self::F * (2 - self::F);
        $lat = atan2($z, $p);
        for ($k = 0; $k < 4; $k++) {
            $nRad = self::RE / sqrt(1 - $e2 * sin($lat) ** 2);
            $lat  = atan2($z + $e2 * $nRad * sin($lat), $p);
        }
        return [$lon, rad2deg($lat)];
    }

    /** Wysokość nad horyzontem i azymut (stopnie) satelity widzianego z [lon, lat]. */
    public function lookAngles(float $ts, float $lon, float $lat): array
    {
        $obs = self::observer($ts, $lon, $lat);
        $sat = $this->eci($ts);
        return self::topocentric(array_map(fn($s, $o) => $s - $o, $sat, $obs), $ts, $lon, $lat);
    }

    /** Czy satelita jest w słońcu (cylindryczny cień Ziemi). */
    public function sunlit(float $ts): bool
    {
        $r   = $this->eci($ts);
        $sun = self::sun($ts);
        $dot = $r[0] * $sun[0] + $r[1] * $sun[1] + $r[2] * $sun[2];
        if ($dot > 0) {
            return true;
        }
        $perp = [$r[0] - $dot * $sun[0], $r[1] - $dot * $sun[1], $r[2] - $dot * $sun[2]];
        return sqrt($perp[0] ** 2 + $perp[1] ** 2 + $perp[2] ** 2) > self::RE;
    }

    /** Wysokość Słońca nad horyzontem w [lon, lat], stopnie. */
    public static function sunElevation(float $ts, float $lon, float $lat): float
    {
        return self::topocentric(self::sun($ts), $ts, $lon, $lat)[0];
    }

    /** Kierunek do Słońca w układzie inercjalnym (wektor jednostkowy) — Astronomical Almanac, niska precyzja. */
    private static function sun(float $ts): array
    {
        $d   = $ts / 86400 + 2440587.5 - 2451545.0;
        $g   = deg2rad(357.529 + 0.98560028 * $d);
        $lam = deg2rad(280.459 + 0.98564736 * $d + 1.915 * sin($g) + 0.020 * sin(2 * $g));
        $eps = deg2rad(23.439 - 0.00000036 * $d);
        return [cos($lam), cos($eps) * sin($lam), sin($eps) * sin($lam)];
    }

    /** Czas gwiazdowy Greenwich, radiany. */
    private static function gmst(float $ts): float
    {
        $d = $ts / 86400 + 2440587.5 - 2451545.0;
        return deg2rad(fmod(280.46061837 + 360.98564736629 * $d, 360));
    }

    /** Pozycja obserwatora na powierzchni (WGS84, wysokość 0) w układzie inercjalnym, km. */
    private static function observer(float $ts, float $lon, float $lat): array
    {
        $phi  = deg2rad($lat);
        $th   = self::gmst($ts) + deg2rad($lon);
        $e2   = self::F * (2 - self::F);
        $nRad = self::RE / sqrt(1 - $e2 * sin($phi) ** 2);
        return [
            $nRad * cos($phi) * cos($th),
            $nRad * cos($phi) * sin($th),
            $nRad * (1 - $e2) * sin($phi),
        ];
    }

    /** Wektor z układu inercjalnego → [wysokość, azymut] dla obserwatora. */
    private static function topocentric(array $v, float $ts, float $lon, float $lat): array
    {
        $phi = deg2rad($lat);
        $th  = self::gmst($ts) + deg2rad($lon);
        $s   = sin($phi) * cos($th) * $v[0] + sin($phi) * sin($th) * $v[1] - cos($phi) * $v[2];
        $e   = -sin($th) * $v[0] + cos($th) * $v[1];
        $z   = cos($phi) * cos($th) * $v[0] + cos($phi) * sin($th) * $v[1] + sin($phi) * $v[2];
        $len = sqrt($s * $s + $e * $e + $z * $z);
        return [rad2deg(asin($z / $len)), fmod(rad2deg(atan2($e, -$s)) + 360, 360)];
    }
}
