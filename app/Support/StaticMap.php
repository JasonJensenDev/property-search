<?php

namespace App\Support;

/**
 * Works out which OpenStreetMap tiles cover a box centred on a point, and where to place
 * each one.
 *
 * Plain tile images are used rather than OpenStreetMap's embeddable map because that embed
 * now needs WebGL, and a map that silently refuses to draw is worse than no map. Images
 * always render, need no API key, and cost a handful of small requests.
 */
class StaticMap
{
    public const TILE_SIZE = 256;

    /**
     * @return array{
     *     tiles: array<int, array{url: string, left: int, top: int}>,
     *     width: int,
     *     height: int
     * }
     */
    public static function forPoint(
        float $latitude,
        float $longitude,
        int $width = 360,
        int $height = 208,
        int $zoom = 16,
    ): array {
        [$centreX, $centreY] = self::worldPixels($latitude, $longitude, $zoom);

        // Pixel coordinates of the box's top-left corner in the whole-world image.
        $originX = $centreX - $width / 2;
        $originY = $centreY - $height / 2;

        $maxTile = (2 ** $zoom) - 1;
        $tiles = [];

        $firstX = (int) floor($originX / self::TILE_SIZE);
        $lastX = (int) floor(($originX + $width) / self::TILE_SIZE);
        $firstY = (int) floor($originY / self::TILE_SIZE);
        $lastY = (int) floor(($originY + $height) / self::TILE_SIZE);

        for ($x = $firstX; $x <= $lastX; $x++) {
            for ($y = $firstY; $y <= $lastY; $y++) {
                // Off the top or bottom of the world there is nothing to draw. Longitude
                // wraps instead, so that is brought back into range.
                if ($y < 0 || $y > $maxTile) {
                    continue;
                }

                $wrappedX = (($x % ($maxTile + 1)) + $maxTile + 1) % ($maxTile + 1);

                $tiles[] = [
                    'url' => "https://tile.openstreetmap.org/{$zoom}/{$wrappedX}/{$y}.png",
                    'left' => (int) round($x * self::TILE_SIZE - $originX),
                    'top' => (int) round($y * self::TILE_SIZE - $originY),
                ];
            }
        }

        return ['tiles' => $tiles, 'width' => $width, 'height' => $height];
    }

    /**
     * Web Mercator: longitude maps straight across, latitude through a log tangent.
     *
     * @return array{0: float, 1: float}
     */
    private static function worldPixels(float $latitude, float $longitude, int $zoom): array
    {
        $scale = self::TILE_SIZE * (2 ** $zoom);

        // Clamped to the limit of the projection, past which the maths blows up.
        $latitude = max(-85.05112878, min(85.05112878, $latitude));
        $radians = deg2rad($latitude);

        $x = ($longitude + 180) / 360 * $scale;
        $y = (1 - log(tan($radians) + 1 / cos($radians)) / M_PI) / 2 * $scale;

        return [$x, $y];
    }
}
