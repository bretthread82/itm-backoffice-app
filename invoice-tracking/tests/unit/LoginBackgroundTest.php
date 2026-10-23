<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The sign-in background must stay light enough to load before anyone has signed in, and the CSS and layout must still point at it.
 *
 * @internal
 */
final class LoginBackgroundTest extends CIUnitTestCase
{
    public function testImagesExistAndAreLightweight(): void
    {
        foreach (['login-bg.jpg' => 600_000, 'login-bg-sm.jpg' => 250_000] as $file => $budget) {
            $path = FCPATH . 'assets/img/' . $file;
            $this->assertFileExists($path);
            $this->assertLessThan($budget, filesize($path), "{$file} should stay under {$budget} bytes");
            $info = getimagesize($path);
            $this->assertSame('image/jpeg', $info['mime']);
        }
        $this->assertGreaterThan(getimagesize(FCPATH . 'assets/img/login-bg-sm.jpg')[0], getimagesize(FCPATH . 'assets/img/login-bg.jpg')[0]);
    }

    public function testCssUsesBothImagesAndAFallbackColour(): void
    {
        $css = (string) file_get_contents(FCPATH . 'assets/css/maloney/layout.css');

        $this->assertStringContainsString('url("../../img/login-bg.jpg")', $css);
        $this->assertStringContainsString('url("../../img/login-bg-sm.jpg")', $css);
        $this->assertMatchesRegularExpression('/\.auth-body\s*\{[^}]*background-color:/', $css, 'a solid fallback while the picture loads');
        $this->assertFileExists(FCPATH . 'assets/img/' . 'login-bg.jpg');
    }

    public function testEveryAuthPageStillUsesTheAuthBodyClass(): void
    {
        $this->assertStringContainsString('class="auth-body"', (string) file_get_contents(APPPATH . 'Views/auth/layout.php'));
    }

    public function testPoweredByLogoIsInTheFooterOfEveryAuthPage(): void
    {
        $path = FCPATH . 'assets/img/powered-by-itm.png';
        $this->assertFileExists($path);
        $this->assertLessThan(150_000, filesize($path));
        $info = getimagesize($path);
        $this->assertSame([440, 102], [$info[0], $info[1]], 'the width and height written in the layout');
        $this->assertSame(6, ord((string) file_get_contents($path, false, null, 25, 1)), 'colour type 6 = RGBA: the white background is transparent, not painted white');

        $layout = (string) file_get_contents(APPPATH . 'Views/auth/layout.php');
        $this->assertStringContainsString('powered-by-itm.png', $layout);
        $this->assertStringContainsString('alt="Powered by ITM', $layout);
        $this->assertMatchesRegularExpression('/\.auth-footer\s*\{/', (string) file_get_contents(FCPATH . 'assets/css/maloney/layout.css'));
    }
}
