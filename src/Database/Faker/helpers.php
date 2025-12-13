<?php

declare(strict_types=1);

use Faker\Generator;
use Toporia\Framework\Database\Faker\ToportaFakerProvider;

/**
 * Toporia Faker Helper Functions
 *
 * Global helper functions for quick access to Toporia Faker formatters.
 * Provides convenient shortcuts without needing to create Factory instances.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Database\Faker
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */

if (!function_exists('faker')) {
    /**
     * Get a Faker generator instance with Toporia provider registered.
     *
     * Performance: O(1) - Singleton pattern (cached after first call)
     *
     * @param string|null $locale Locale for faker (default: 'en_US')
     * @return Generator Faker generator with Toporia provider
     *
     * @example
     * ```php
     * $faker = faker();
     * echo $faker->numerify('ID-###'); // 'ID-482'
     * echo $faker->bothify('??##');    // 'ab45'
     * ```
     */
    function faker(?string $locale = null): Generator
    {
        static $instances = [];

        $locale = $locale ?? 'en_US';

        if (!isset($instances[$locale])) {
            $faker = \Faker\Factory::create($locale);
            $provider = new ToportaFakerProvider($faker);
            $provider->register($faker);
            $instances[$locale] = $faker;
        }

        return $instances[$locale];
    }
}

if (!function_exists('numerify')) {
    /**
     * Replace # with random digits.
     *
     * Performance: O(n) where n = string length
     *
     * @param string $string String template (default: '###')
     * @return string String with # replaced by digits
     *
     * @example
     * ```php
     * echo numerify('user-####'); // 'user-4928'
     * echo numerify('###-###');   // '482-719'
     * ```
     */
    function numerify(string $string = '###'): string
    {
        return faker()->numerify($string);
    }
}

if (!function_exists('lexify')) {
    /**
     * Replace ? with random letters.
     *
     * Performance: O(n) where n = string length
     *
     * @param string $string String template (default: '????')
     * @return string String with ? replaced by letters
     *
     * @example
     * ```php
     * echo lexify('code-????'); // 'code-xoqe'
     * echo lexify('????');      // 'sakh'
     * ```
     */
    function lexify(string $string = '????'): string
    {
        return faker()->lexify($string);
    }
}

if (!function_exists('bothify')) {
    /**
     * Replace # with digits, ? with letters, * with either.
     *
     * Performance: O(n) where n = string length
     *
     * @param string $string String template (default: '## ??')
     * @return string String with placeholders replaced
     *
     * @example
     * ```php
     * echo bothify('?????-#####'); // 'lsadj-10298'
     * echo bothify('***-***');     // 'a8x-4k2'
     * ```
     */
    function bothify(string $string = '## ??'): string
    {
        return faker()->bothify($string);
    }
}

if (!function_exists('asciify')) {
    /**
     * Replace * with random ASCII printable characters.
     *
     * Performance: O(n) where n = string length
     *
     * @param string $string String template (default: '****')
     * @return string String with * replaced by ASCII characters
     *
     * @example
     * ```php
     * echo asciify('user-****'); // 'user-nTw{'
     * echo asciify('****');      // '%Y+!'
     * ```
     */
    function asciify(string $string = '****'): string
    {
        return faker()->asciify($string);
    }
}

if (!function_exists('regexify')) {
    /**
     * Generate random string from regex pattern.
     *
     * Performance: O(n*m) where n = pattern complexity, m = result length
     *
     * Supported patterns: [a-z], [A-Z], [0-9], {n}, {n,m}
     *
     * @param string $pattern Regex pattern
     * @return string Generated random string
     *
     * @example
     * ```php
     * echo regexify('[A-Z]{5}[0-4]{3}');  // 'DRSQX201'
     * echo regexify('[a-z]{3}-[0-9]{4}'); // 'abc-1234'
     * ```
     */
    function regexify(string $pattern = ''): string
    {
        return faker()->regexify($pattern);
    }
}

if (!function_exists('fake_id')) {
    /**
     * Generate a fake ID with specified format.
     *
     * Common formats:
     * - 'uuid': UUID v4
     * - 'numeric-8': 8-digit number (e.g., '12345678')
     * - 'alphanumeric-8': 8 alphanumeric chars (e.g., 'a8x4k2m1')
     * - 'custom': Use custom pattern with bothify
     *
     * Performance: O(1) for uuid, O(n) for patterns
     *
     * @param string $format ID format type
     * @param string|null $pattern Custom pattern for 'custom' format
     * @return string Generated fake ID
     *
     * @example
     * ```php
     * echo fake_id('uuid');              // '550e8400-e29b-41d4-a716-446655440000'
     * echo fake_id('numeric-8');         // '12345678'
     * echo fake_id('alphanumeric-8');    // 'a8x4k2m1'
     * echo fake_id('custom', 'ID-###-???'); // 'ID-482-xyz'
     * ```
     */
    function fake_id(string $format = 'numeric-8', ?string $pattern = null): string
    {
        $faker = faker();

        return match ($format) {
            'uuid' => $faker->uuid(),
            'numeric-8' => $faker->numerify('########'),
            'numeric-10' => $faker->numerify('##########'),
            'alphanumeric-8' => $faker->bothify('********'),
            'alphanumeric-10' => $faker->bothify('**********'),
            'custom' => $pattern ? $faker->bothify($pattern) : $faker->bothify('********'),
            default => $faker->numerify('########'),
        };
    }
}

if (!function_exists('fake_code')) {
    /**
     * Generate a fake code/coupon/voucher with specified format.
     *
     * Common formats:
     * - 'coupon': XXXXX-##### (e.g., 'ABCDE-12345')
     * - 'voucher': ????-####-???? (e.g., 'abcd-1234-wxyz')
     * - 'license': #####-#####-#####-##### (e.g., '12345-67890-12345-67890')
     * - 'serial': Custom pattern
     *
     * Performance: O(n) where n = code length
     *
     * @param string $format Code format type
     * @param string|null $pattern Custom pattern for 'serial' format
     * @return string Generated fake code
     *
     * @example
     * ```php
     * echo fake_code('coupon');   // 'ABCDE-12345'
     * echo fake_code('voucher');  // 'abcd-1234-wxyz'
     * echo fake_code('license');  // '12345-67890-12345-67890'
     * echo fake_code('serial', '??##-??##'); // 'ab12-cd34'
     * ```
     */
    function fake_code(string $format = 'coupon', ?string $pattern = null): string
    {
        $faker = faker();

        return match ($format) {
            'coupon' => strtoupper($faker->bothify('?????-#####')),
            'voucher' => $faker->bothify('????-####-????'),
            'license' => $faker->numerify('#####-#####-#####-#####'),
            'promo' => strtoupper($faker->bothify('????##')),
            'serial' => $pattern ? $faker->bothify($pattern) : $faker->bothify('********'),
            default => strtoupper($faker->bothify('?????-#####')),
        };
    }
}

if (!function_exists('fake_username')) {
    /**
     * Generate a fake username with specified format.
     *
     * Formats:
     * - 'simple': lowercase letters (e.g., 'john')
     * - 'numbered': letters + numbers (e.g., 'user1234')
     * - 'underscore': letters_numbers (e.g., 'user_1234')
     * - 'custom': Use custom pattern
     *
     * Performance: O(n) where n = username length
     *
     * @param string $format Username format
     * @param int $length Username length (for simple format)
     * @param string|null $pattern Custom pattern
     * @return string Generated fake username
     *
     * @example
     * ```php
     * echo fake_username();                    // 'user1234'
     * echo fake_username('simple', 8);         // 'johndoe'
     * echo fake_username('underscore');        // 'user_1234'
     * echo fake_username('custom', 0, '???###'); // 'abc123'
     * ```
     */
    function fake_username(string $format = 'numbered', int $length = 8, ?string $pattern = null): string
    {
        $faker = faker();

        return match ($format) {
            'simple' => $faker->lexify(str_repeat('?', $length)),
            'numbered' => $faker->bothify(str_repeat('?', $length - 4) . '####'),
            'underscore' => $faker->bothify(str_repeat('?', $length - 5) . '_####'),
            'custom' => $pattern ? $faker->bothify($pattern) : $faker->bothify('????####'),
            default => $faker->bothify('????####'),
        };
    }
}
