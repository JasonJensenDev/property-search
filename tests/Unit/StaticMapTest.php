<?php

namespace Tests\Unit;

use App\Support\StaticMap;
use PHPUnit\Framework\TestCase;

/**
 * The map preview is stitched from plain tile images, so the arithmetic that decides which
 * tiles to fetch and where to put them is what makes the pin land on the right house.
 */
class StaticMapTest extends TestCase
{
    private const LAT = 40.5984088;

    private const LON = -112.4606984;

    public function test_it_covers_the_whole_box_with_tiles(): void
    {
        $map = StaticMap::forPoint(self::LAT, self::LON, 360, 208);

        $this->assertNotEmpty($map['tiles']);

        // Every pixel of the box has to be behind a tile, or the map shows gaps.
        for ($x = 0; $x < 360; $x += 40) {
            for ($y = 0; $y < 208; $y += 40) {
                $covered = collect($map['tiles'])->contains(
                    fn ($tile) => $x >= $tile['left']
                        && $x < $tile['left'] + StaticMap::TILE_SIZE
                        && $y >= $tile['top']
                        && $y < $tile['top'] + StaticMap::TILE_SIZE
                );

                $this->assertTrue($covered, "pixel {$x},{$y} is not covered by any tile");
            }
        }
    }

    public function test_the_requested_point_sits_at_the_centre_of_the_box(): void
    {
        $width = 360;
        $height = 208;
        $zoom = 16;

        $map = StaticMap::forPoint(self::LAT, self::LON, $width, $height, $zoom);

        // Recompute where the point falls once the tiles are laid out, and check it is the
        // middle of the box, which is where the marker is drawn.
        $scale = StaticMap::TILE_SIZE * (2 ** $zoom);
        $pointX = (self::LON + 180) / 360 * $scale;
        $radians = deg2rad(self::LAT);
        $pointY = (1 - log(tan($radians) + 1 / cos($radians)) / M_PI) / 2 * $scale;

        $first = collect($map['tiles'])->sortBy([['top', 'asc'], ['left', 'asc']])->first();
        $tileX = (int) floor(($pointX - $width / 2) / StaticMap::TILE_SIZE);
        $tileY = (int) floor(($pointY - $height / 2) / StaticMap::TILE_SIZE);

        $originX = $tileX * StaticMap::TILE_SIZE - $first['left'];
        $originY = $tileY * StaticMap::TILE_SIZE - $first['top'];

        $this->assertEqualsWithDelta($width / 2, $pointX - $originX, 1.0);
        $this->assertEqualsWithDelta($height / 2, $pointY - $originY, 1.0);
    }

    public function test_it_asks_for_real_openstreetmap_tiles(): void
    {
        $map = StaticMap::forPoint(self::LAT, self::LON, 360, 208, 16);

        foreach ($map['tiles'] as $tile) {
            $this->assertMatchesRegularExpression(
                '#^https://tile\.openstreetmap\.org/16/\d+/\d+\.png$#',
                $tile['url']
            );
        }
    }

    public function test_a_tighter_box_needs_fewer_tiles(): void
    {
        $small = StaticMap::forPoint(self::LAT, self::LON, 200, 200);
        $large = StaticMap::forPoint(self::LAT, self::LON, 900, 600);

        $this->assertLessThan(count($large['tiles']), count($small['tiles']));
    }

    public function test_it_survives_the_poles_and_the_date_line(): void
    {
        // Nothing in Utah goes near either, but silently producing tile numbers outside the
        // world would give broken images rather than an error.
        foreach ([[89.9, 179.9], [-89.9, -179.9], [0.0, 180.0]] as [$lat, $lon]) {
            $map = StaticMap::forPoint($lat, $lon, 360, 208, 16);
            $maxTile = (2 ** 16) - 1;

            $this->assertNotEmpty($map['tiles']);

            foreach ($map['tiles'] as $tile) {
                preg_match('#/16/(\d+)/(\d+)\.png$#', $tile['url'], $parts);

                $this->assertLessThanOrEqual($maxTile, (int) $parts[1]);
                $this->assertLessThanOrEqual($maxTile, (int) $parts[2]);
            }
        }
    }
}
