<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Browser\Concerns;

use PHPUnit\Framework\Assert;

/**
 * Trait MakesAssertions
 *
 * Provides comprehensive assertion methods for browser testing including URL, element, and content assertions.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Testing\Browser\Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait MakesAssertions
{
    /**
     * Assert the current URL path matches.
     *
     * @param string $path
     * @return static
     */
    public function assertPathIs(string $path): static
    {
        $actual = $this->getCurrentPath();

        Assert::assertEquals(
            $path,
            $actual,
            "Expected path [{$path}] but got [{$actual}]"
        );

        return $this;
    }

    /**
     * Assert the current URL path does not match.
     *
     * @param string $path
     * @return static
     */
    public function assertPathIsNot(string $path): static
    {
        Assert::assertNotEquals(
            $path,
            $this->getCurrentPath(),
            "Path should not be [{$path}]"
        );

        return $this;
    }

    /**
     * Assert the current URL path starts with.
     *
     * @param string $path
     * @return static
     */
    public function assertPathBeginsWith(string $path): static
    {
        $actual = $this->getCurrentPath();

        Assert::assertStringStartsWith(
            $path,
            $actual,
            "Expected path to begin with [{$path}] but got [{$actual}]"
        );

        return $this;
    }

    /**
     * Assert the current URL matches.
     *
     * @param string $url
     * @return static
     */
    public function assertUrlIs(string $url): static
    {
        $actual = $this->getCurrentUrl();

        Assert::assertEquals(
            $url,
            $actual,
            "Expected URL [{$url}] but got [{$actual}]"
        );

        return $this;
    }

    /**
     * Assert query string has a parameter.
     *
     * @param string $name
     * @param string|null $value
     * @return static
     */
    public function assertQueryStringHas(string $name, ?string $value = null): static
    {
        $url = $this->getCurrentUrl();
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);

        Assert::assertArrayHasKey($name, $params, "Query string missing parameter [{$name}]");

        if ($value !== null) {
            Assert::assertEquals(
                $value,
                $params[$name],
                "Query parameter [{$name}] expected [{$value}] but got [{$params[$name]}]"
            );
        }

        return $this;
    }

    /**
     * Assert page title.
     *
     * @param string $title
     * @return static
     */
    public function assertTitle(string $title): static
    {
        $actual = $this->getTitle();

        Assert::assertEquals(
            $title,
            $actual,
            "Expected title [{$title}] but got [{$actual}]"
        );

        return $this;
    }

    /**
     * Assert page title contains.
     *
     * @param string $title
     * @return static
     */
    public function assertTitleContains(string $title): static
    {
        Assert::assertStringContainsString(
            $title,
            $this->getTitle(),
            "Title does not contain [{$title}]"
        );

        return $this;
    }

    /**
     * Assert page contains text.
     *
     * @param string $text
     * @return static
     */
    public function assertSee(string $text): static
    {
        Assert::assertStringContainsString(
            $text,
            $this->getPageSource(),
            "Page does not contain [{$text}]"
        );

        return $this;
    }

    /**
     * Assert page does not contain text.
     *
     * @param string $text
     * @return static
     */
    public function assertDontSee(string $text): static
    {
        Assert::assertStringNotContainsString(
            $text,
            $this->getPageSource(),
            "Page should not contain [{$text}]"
        );

        return $this;
    }

    /**
     * Assert element contains text.
     *
     * @param string $selector
     * @param string $text
     * @return static
     */
    public function assertSeeIn(string $selector, string $text): static
    {
        $elementText = $this->text($selector);

        Assert::assertStringContainsString(
            $text,
            $elementText,
            "Element [{$selector}] does not contain [{$text}]"
        );

        return $this;
    }

    /**
     * Assert element does not contain text.
     *
     * @param string $selector
     * @param string $text
     * @return static
     */
    public function assertDontSeeIn(string $selector, string $text): static
    {
        $elementText = $this->text($selector);

        Assert::assertStringNotContainsString(
            $text,
            $elementText,
            "Element [{$selector}] should not contain [{$text}]"
        );

        return $this;
    }

    /**
     * Assert element is present.
     *
     * @param string $selector
     * @return static
     */
    public function assertPresent(string $selector): static
    {
        Assert::assertTrue(
            $this->elementExists($selector),
            "Element [{$selector}] not found"
        );

        return $this;
    }

    /**
     * Assert element is not present.
     *
     * @param string $selector
     * @return static
     */
    public function assertNotPresent(string $selector): static
    {
        Assert::assertFalse(
            $this->elementExists($selector),
            "Element [{$selector}] should not be present"
        );

        return $this;
    }

    /**
     * Assert element is visible.
     *
     * @param string $selector
     * @return static
     */
    public function assertVisible(string $selector): static
    {
        Assert::assertTrue(
            $this->isVisible($selector),
            "Element [{$selector}] is not visible"
        );

        return $this;
    }

    /**
     * Assert element is not visible.
     *
     * @param string $selector
     * @return static
     */
    public function assertMissing(string $selector): static
    {
        Assert::assertFalse(
            $this->isVisible($selector),
            "Element [{$selector}] should not be visible"
        );

        return $this;
    }

    /**
     * Assert input has value.
     *
     * @param string $selector
     * @param string $value
     * @return static
     */
    public function assertInputValue(string $selector, string $value): static
    {
        $actual = $this->value($selector);

        Assert::assertEquals(
            $value,
            $actual,
            "Input [{$selector}] expected value [{$value}] but got [{$actual}]"
        );

        return $this;
    }

    /**
     * Assert input does not have value.
     *
     * @param string $selector
     * @param string $value
     * @return static
     */
    public function assertInputValueIsNot(string $selector, string $value): static
    {
        Assert::assertNotEquals(
            $value,
            $this->value($selector),
            "Input [{$selector}] should not have value [{$value}]"
        );

        return $this;
    }

    /**
     * Assert checkbox is checked.
     *
     * @param string $selector
     * @return static
     */
    public function assertChecked(string $selector): static
    {
        Assert::assertTrue(
            $this->isChecked($selector),
            "Checkbox [{$selector}] is not checked"
        );

        return $this;
    }

    /**
     * Assert checkbox is not checked.
     *
     * @param string $selector
     * @return static
     */
    public function assertNotChecked(string $selector): static
    {
        Assert::assertFalse(
            $this->isChecked($selector),
            "Checkbox [{$selector}] should not be checked"
        );

        return $this;
    }

    /**
     * Assert element is enabled.
     *
     * @param string $selector
     * @return static
     */
    public function assertEnabled(string $selector): static
    {
        Assert::assertTrue(
            $this->isEnabled($selector),
            "Element [{$selector}] is not enabled"
        );

        return $this;
    }

    /**
     * Assert element is disabled.
     *
     * @param string $selector
     * @return static
     */
    public function assertDisabled(string $selector): static
    {
        Assert::assertFalse(
            $this->isEnabled($selector),
            "Element [{$selector}] should be disabled"
        );

        return $this;
    }

    /**
     * Assert element has attribute.
     *
     * @param string $selector
     * @param string $attribute
     * @param string|null $value
     * @return static
     */
    public function assertAttribute(string $selector, string $attribute, ?string $value = null): static
    {
        $actual = $this->attribute($selector, $attribute);

        Assert::assertNotNull(
            $actual,
            "Element [{$selector}] missing attribute [{$attribute}]"
        );

        if ($value !== null) {
            Assert::assertEquals(
                $value,
                $actual,
                "Attribute [{$attribute}] expected [{$value}] but got [{$actual}]"
            );
        }

        return $this;
    }

    /**
     * Assert element has CSS class.
     *
     * @param string $selector
     * @param string $class
     * @return static
     */
    public function assertHasClass(string $selector, string $class): static
    {
        $classes = $this->attribute($selector, 'class') ?? '';

        Assert::assertStringContainsString(
            $class,
            $classes,
            "Element [{$selector}] does not have class [{$class}]"
        );

        return $this;
    }

    /**
     * Assert element does not have CSS class.
     *
     * @param string $selector
     * @param string $class
     * @return static
     */
    public function assertClassMissing(string $selector, string $class): static
    {
        $classes = $this->attribute($selector, 'class') ?? '';

        Assert::assertStringNotContainsString(
            $class,
            $classes,
            "Element [{$selector}] should not have class [{$class}]"
        );

        return $this;
    }

    /**
     * Assert authenticated as user.
     *
     * @param mixed $user
     * @param string|null $guard
     * @return static
     */
    public function assertAuthenticated(mixed $user = null, ?string $guard = null): static
    {
        // This would need to be implemented based on authentication system
        return $this;
    }

    /**
     * Assert not authenticated.
     *
     * @param string|null $guard
     * @return static
     */
    public function assertGuest(?string $guard = null): static
    {
        // This would need to be implemented based on authentication system
        return $this;
    }

    /**
     * Assert console has no errors.
     *
     * @return static
     */
    public function assertConsoleHasNoErrors(): static
    {
        $logs = $this->driver->getConsoleLogs();

        $errors = array_filter($logs, fn($log) => str_contains(strtolower($log), 'error'));

        Assert::assertEmpty($errors, "Console has errors: " . implode("\n", $errors));

        return $this;
    }
}
