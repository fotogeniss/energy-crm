<?php

/**
 * Οι φωτογραφίες κινητού μικραίνουν πριν σταλούν στο API (308).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class ExtractorShrinksPhotosTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        require_once dirname(__DIR__, 3) . '/includes/class-ecrm-extractor.php';
    }

    protected function setUp(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Χωρίς GD ο server στέλνει την εικόνα όπως είναι.');
        }
    }

    public function testABigPhotoIsScaledToTheLimitAndStaysReadable(): void
    {
        [$bytes, $mime] = \ECRM_Extractor::shrink_image($this->noisyJpeg(2400, 1600), 'image/jpeg');

        $size = getimagesizefromstring($bytes);

        self::assertSame('image/jpeg', $mime);
        self::assertSame(\ECRM_Extractor::MAX_IMAGE_EDGE, max($size[0], $size[1]));
        // Η αναλογία διατηρείται (3:2).
        self::assertEqualsWithDelta(1.5, $size[0] / $size[1], 0.01);
    }

    public function testASmallPhotoIsLeftAlone(): void
    {
        $raw = $this->noisyJpeg(800, 600);

        self::assertSame([$raw, 'image/jpeg'], \ECRM_Extractor::shrink_image($raw, 'image/jpeg'));
    }

    public function testBrokenBytesComeBackUntouched(): void
    {
        $junk = str_repeat('x', 400000);

        self::assertSame([$junk, 'image/jpeg'], \ECRM_Extractor::shrink_image($junk, 'image/jpeg'));
    }

    /** Εικόνα με περιεχόμενο, ώστε να μικραίνει πραγματικά το αρχείο. */
    private function noisyJpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);

        for ($i = 0; $i < 1500; $i++) {
            $c = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagefilledrectangle(
                $img,
                random_int(0, $w - 40),
                random_int(0, $h - 40),
                random_int(0, $w),
                random_int(0, $h),
                $c
            );
        }

        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }
}
