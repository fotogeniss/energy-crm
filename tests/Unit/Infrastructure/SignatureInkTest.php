<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\SignatureInk;
use PHPUnit\Framework\TestCase;

final class SignatureInkTest extends TestCase
{
    /** Pad υπολογιστή σε 2x οθόνη (~1100px) σε κουτί 36mm: ~5px για 0,15mm. */
    public function testTheRadiusIsMeasuredInMillimetresOfPaper(): void
    {
        self::assertSame(5, SignatureInk::radiusPx(1100, 36.0));
        self::assertSame(2, SignatureInk::radiusPx(540, 36.0));
    }

    public function testTheRadiusNeverRunsAway(): void
    {
        self::assertSame(SignatureInk::MAX_RADIUS, SignatureInk::radiusPx(10000, 10.0));
        self::assertSame(0, SignatureInk::radiusPx(0, 36.0));
        self::assertSame(0, SignatureInk::radiusPx(1100, 0.0));
    }

    public function testTheOffsetsFillADiskWithoutTheCentre(): void
    {
        self::assertCount(4, SignatureInk::offsets(1));
        self::assertCount(12, SignatureInk::offsets(2));
        self::assertNotContains([0, 0], SignatureInk::offsets(2));
    }

    public function testAThinLineComesOutThicker(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Χωρίς GD.');
        }

        $src = $this->png(true);
        $out = SignatureInk::thicken($src, 2);

        self::assertNotNull($out);
        self::assertStringEndsWith('.png', (string) $out);
        self::assertGreaterThan($this->inkPixels($src) * 3, $this->inkPixels((string) $out));

        [$w, $h] = (array) getimagesize((string) $out);
        self::assertSame([100, 40], [$w, $h], 'Οι διαστάσεις αλλάζουν -- και μαζί η θέση στο κουτί.');

        @unlink($src);
        @unlink((string) $out);
    }

    /** Σε λευκό φόντο κάθε αντίγραφο θα έσβηνε το προηγούμενο: μένει ως έχει. */
    public function testAnOpaqueImageIsLeftAlone(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Χωρίς GD.');
        }

        $src = $this->png(false);

        self::assertNull(SignatureInk::thicken($src, 2));
        self::assertNull(SignatureInk::thicken($src, 0));

        @unlink($src);
    }

    /** Οριζόντια γραμμή πάχους 1px σε εικόνα 100x40. */
    private function png(bool $transparent): string
    {
        $im = imagecreatetruecolor(100, 40);

        if ($im === false) {
            self::fail('GD: δεν φτιάχτηκε εικόνα.');
        }

        imagealphablending($im, false);
        imagefill($im, 0, 0, (int) ($transparent
            ? imagecolorallocatealpha($im, 0, 0, 0, 127)
            : imagecolorallocate($im, 255, 255, 255)));
        imageline($im, 10, 20, 90, 20, (int) imagecolorallocatealpha($im, 20, 20, 20, 0));
        imagesavealpha($im, true);

        $path = sys_get_temp_dir() . '/ecrm-ink-test-' . uniqid() . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function inkPixels(string $path): int
    {
        $im = imagecreatefrompng($path);

        if ($im === false) {
            self::fail("Δεν διαβάστηκε το {$path}.");
        }

        $n = 0;

        for ($y = 0; $y < imagesy($im); $y++) {
            for ($x = 0; $x < imagesx($im); $x++) {
                if (imagecolorsforindex($im, (int) imagecolorat($im, $x, $y))['alpha'] < 64) {
                    $n++;
                }
            }
        }

        return $n;
    }
}
