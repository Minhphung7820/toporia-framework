<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser;

use PHPUnit\Framework\TestCase;

/**
 * Class BrowserTestCase
 *
 * Base test case for browser testing with WebDriver integration and automatic screenshot capture on failures.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing\Browser
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class BrowserTestCase extends TestCase
{
    /**
     * The WebDriver instance.
     *
     * @var WebDriver|null
     */
    protected static ?WebDriver $driver = null;

    /**
     * The browser instances.
     *
     * @var array<Browser>
     */
    protected array $browsers = [];

    /**
     * The base URL for testing.
     *
     * @var string
     */
    protected string $baseUrl = 'http://localhost:8000';

    /**
     * The WebDriver server URL.
     *
     * @var string
     */
    protected string $driverUrl = 'http://localhost:9515';

    /**
     * Browser capabilities.
     *
     * @var array<string, mixed>
     */
    protected array $capabilities = [];

    /**
     * Setup before class.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    /**
     * Teardown after class.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        if (static::$driver !== null) {
            static::$driver->quit();
            static::$driver = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Setup before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->browsers = [];
    }

    /**
     * Teardown after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->browsers as $browser) {
            $browser->quit();
        }

        $this->browsers = [];

        parent::tearDown();
    }

    /**
     * Create a new browser instance.
     *
     * @return Browser
     */
    protected function newBrowser(): Browser
    {
        $driver = new WebDriver($this->driverUrl);
        $driver->createSession($this->capabilities);

        $browser = new Browser($driver, $this->baseUrl);
        $this->browsers[] = $browser;

        return $browser;
    }

    /**
     * Browse using a callback.
     *
     * @param callable $callback
     * @return void
     */
    protected function browse(callable $callback): void
    {
        $browser = $this->newBrowser();

        try {
            $callback($browser);
        } catch (\Throwable $e) {
            $this->captureFailureScreenshot($browser);
            throw $e;
        }
    }

    /**
     * Browse with multiple browsers.
     *
     * @param int $count
     * @param callable $callback
     * @return void
     */
    protected function browseMultiple(int $count, callable $callback): void
    {
        $browsers = [];

        for ($i = 0; $i < $count; $i++) {
            $browsers[] = $this->newBrowser();
        }

        try {
            $callback(...$browsers);
        } catch (\Throwable $e) {
            foreach ($browsers as $browser) {
                $this->captureFailureScreenshot($browser);
            }
            throw $e;
        }
    }

    /**
     * Capture screenshot on failure.
     *
     * @param Browser $browser
     * @return void
     */
    protected function captureFailureScreenshot(Browser $browser): void
    {
        $name = sprintf(
            'failure-%s-%s',
            str_replace('\\', '_', get_class($this)),
            $this->getName()
        );

        $browser->screenshot($name);
        $browser->storeConsoleLog($name);
    }

    /**
     * Set headless mode for Chrome.
     *
     * @return static
     */
    protected function headless(): static
    {
        $this->capabilities['capabilities']['alwaysMatch']['goog:chromeOptions']['args'][] = '--headless';

        return $this;
    }

    /**
     * Set window size.
     *
     * @param int $width
     * @param int $height
     * @return static
     */
    protected function windowSize(int $width, int $height): static
    {
        $this->capabilities['capabilities']['alwaysMatch']['goog:chromeOptions']['args'][] =
            "--window-size={$width},{$height}";

        return $this;
    }

    /**
     * Get the base URL.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Set the base URL.
     *
     * @param string $url
     * @return static
     */
    protected function setBaseUrl(string $url): static
    {
        $this->baseUrl = $url;

        return $this;
    }
}
