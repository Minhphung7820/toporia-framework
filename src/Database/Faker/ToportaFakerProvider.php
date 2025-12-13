<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Faker;

use Faker\Provider\Base;
use Faker\Generator;
use Toporia\Framework\Database\Contracts\FakerProviderInterface;

/**
 * Toporia Faker Provider
 *
 * Professional, enterprise-grade faker provider with high-performance string/number formatters
 * inspired by FakerPHP but optimized for Toporia Framework.
 *
 * Features:
 * - numerify(): Replace # with random digits (0-9)
 * - lexify(): Replace ? with random letters (a-z)
 * - bothify(): Replace # with digits, ? with letters, * with either
 * - asciify(): Replace * with random ASCII characters
 * - regexify(): Generate random string from regex pattern
 * - randomDigit(): Random digit 0-9
 * - randomDigitNotNull(): Random digit 1-9
 * - randomDigitNot(): Random digit excluding specific number
 * - randomLetter(): Random letter a-z
 * - randomElement(): Random element from array
 * - randomElements(): Multiple random elements from array
 * - randomKey(): Random key from array
 * - shuffle(): Shuffle string or array
 *
 * Performance Optimizations:
 * - Pre-computed lookup tables for character sets
 * - Efficient random number generation
 * - Minimal string allocations
 * - Optimized regex parsing (limited but fast)
 * - Cache-friendly data structures
 *
 * Clean Architecture:
 * - Extends Faker\Provider\Base for compatibility
 * - Implements FakerProviderInterface for Toporia integration
 * - Separation of concerns: Each method has single responsibility
 *
 * SOLID Principles:
 * - Single Responsibility: Only provides string/number formatting
 * - Open/Closed: Extensible via inheritance
 * - Liskov Substitution: Can replace Base provider
 * - Interface Segregation: Small, focused methods
 * - Dependency Inversion: Uses Generator abstraction
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
 * @see         https://fakerphp.org/formatters/numbers-and-strings/
 */
class ToportaFakerProvider extends Base implements FakerProviderInterface
{
    /**
     * Character sets (pre-computed for performance).
     */
    private const DIGITS = '0123456789';
    private const LETTERS = 'abcdefghijklmnopqrstuvwxyz';
    private const ASCII_PRINTABLE = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * Cached character arrays for fast random access.
     */
    private static ?array $digitsArray = null;
    private static ?array $lettersArray = null;
    private static ?array $asciiArray = null;

    /**
     * Register this provider with Faker generator.
     *
     * @param Generator $generator Faker generator instance
     * @return void
     */
    public function register(Generator $generator): void
    {
        $generator->addProvider($this);
    }

    /**
     * Initialize character arrays (lazy initialization for performance).
     *
     * Performance: O(1) amortized (only runs once)
     *
     * @return void
     */
    private static function initializeArrays(): void
    {
        if (self::$digitsArray === null) {
            self::$digitsArray = str_split(self::DIGITS);
            self::$lettersArray = str_split(self::LETTERS);
            self::$asciiArray = str_split(self::ASCII_PRINTABLE);
        }
    }

    // ================================================================
    // BASIC RANDOM GENERATORS
    // ================================================================

    /**
     * Generate a random digit (0-9).
     *
     * Performance: O(1)
     *
     * @return int Random digit between 0 and 9
     *
     * @example
     * ```php
     * $faker->randomDigit(); // 7
     * $faker->randomDigit(); // 0
     * ```
     */
    public function randomDigit(): int
    {
        return random_int(0, 9);
    }

    /**
     * Generate a random digit excluding zero (1-9).
     *
     * Performance: O(1)
     *
     * @return int Random digit between 1 and 9
     *
     * @example
     * ```php
     * $faker->randomDigitNotNull(); // 5
     * $faker->randomDigitNotNull(); // 9
     * ```
     */
    public function randomDigitNotNull(): int
    {
        return random_int(1, 9);
    }

    /**
     * Generate a random digit excluding a specific number.
     *
     * Performance: O(1)
     *
     * @param int $except Digit to exclude (0-9)
     * @return int Random digit between 0 and 9, excluding $except
     *
     * @example
     * ```php
     * $faker->randomDigitNot(5); // 0-9 except 5
     * $faker->randomDigitNot(0); // 1-9
     * ```
     */
    public function randomDigitNot(int $except): int
    {
        $digit = random_int(0, 8);
        if ($digit >= $except) {
            $digit++;
        }
        return $digit;
    }

    /**
     * Generate a random letter (a-z lowercase).
     *
     * Performance: O(1)
     *
     * @return string Random letter from a to z
     *
     * @example
     * ```php
     * $faker->randomLetter(); // 'h'
     * $faker->randomLetter(); // 'q'
     * ```
     */
    public function randomLetter(): string
    {
        self::initializeArrays();
        return self::$lettersArray[array_rand(self::$lettersArray)];
    }

    /**
     * Get a random element from an array.
     *
     * Performance: O(1)
     *
     * @param array $array Source array
     * @return mixed Random element from the array
     * @throws \InvalidArgumentException If array is empty
     *
     * @example
     * ```php
     * $faker->randomElement(['a', 'b', 'c']); // 'b'
     * $faker->randomElement([1, 2, 3, 4, 5]); // 3
     * ```
     */
    public function randomElement(array $array): mixed
    {
        if (empty($array)) {
            throw new \InvalidArgumentException('Array cannot be empty');
        }

        return $array[array_rand($array)];
    }

    /**
     * Get multiple random elements from an array.
     *
     * Performance: O(n) where n = count
     *
     * @param array $array Source array
     * @param int|null $count Number of elements to return (null = random count)
     * @param bool $allowDuplicates Allow duplicate elements
     * @return array Random elements from the array
     * @throws \InvalidArgumentException If array is empty or count invalid
     *
     * @example
     * ```php
     * $faker->randomElements(['a', 'b', 'c', 'd', 'e'], 3);
     * // ['c', 'a', 'e']
     *
     * $faker->randomElements(['a', 'b', 'c'], null);
     * // ['c', 'a'] (random count)
     * ```
     */
    public function randomElements(array $array, ?int $count = 1, bool $allowDuplicates = false): array
    {
        if (empty($array)) {
            throw new \InvalidArgumentException('Array cannot be empty');
        }

        $arrayCount = count($array);

        if ($count === null) {
            $count = random_int(1, $arrayCount);
        }

        if ($count < 0 || (!$allowDuplicates && $count > $arrayCount)) {
            throw new \InvalidArgumentException('Invalid count specified');
        }

        if ($count === 0) {
            return [];
        }

        if ($allowDuplicates) {
            $elements = [];
            for ($i = 0; $i < $count; $i++) {
                $elements[] = $array[array_rand($array)];
            }
            return $elements;
        }

        $keys = array_rand($array, min($count, $arrayCount));
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        return array_map(fn($key) => $array[$key], $keys);
    }

    /**
     * Get a random key from an array.
     *
     * Performance: O(1)
     *
     * @param array $array Source array
     * @return int|string Random key from the array
     * @throws \InvalidArgumentException If array is empty
     *
     * @example
     * ```php
     * $faker->randomKey(['a' => 1, 'b' => 2, 'c' => 3]); // 'b'
     * ```
     */
    public function randomKey(array $array): int|string
    {
        if (empty($array)) {
            throw new \InvalidArgumentException('Array cannot be empty');
        }

        return array_rand($array);
    }

    /**
     * Shuffle a string or array.
     *
     * Performance: O(n) where n = string length or array size
     *
     * @param string|array $input String or array to shuffle
     * @return string|array Shuffled version
     *
     * @example
     * ```php
     * $faker->shuffle('hello-world'); // 'lrhoodl-ewl'
     * $faker->shuffle([1, 2, 3]);     // [3, 1, 2]
     * ```
     */
    public function shuffle(string|array $input): string|array
    {
        if (is_string($input)) {
            $array = str_split($input);
            shuffle($array);
            return implode('', $array);
        }

        $copy = $input;
        shuffle($copy);
        return $copy;
    }

    // ================================================================
    // STRING FORMATTERS
    // ================================================================

    /**
     * Replace # characters with random digits (0-9).
     *
     * Performance: O(n) where n = string length
     * Memory: O(1) extra space (in-place replacement)
     *
     * @param string $string String template (default: '###')
     * @return string String with # replaced by digits
     *
     * @example
     * ```php
     * $faker->numerify();           // '912'
     * $faker->numerify('user-####'); // 'user-4928'
     * $faker->numerify('ID-###-###'); // 'ID-482-719'
     * ```
     */
    public function numerify(string $string = '###'): string
    {
        self::initializeArrays();

        return preg_replace_callback('/#/', function () {
            return self::$digitsArray[array_rand(self::$digitsArray)];
        }, $string);
    }

    /**
     * Replace ? characters with random letters (a-z).
     *
     * Performance: O(n) where n = string length
     * Memory: O(1) extra space (in-place replacement)
     *
     * @param string $string String template (default: '????')
     * @return string String with ? replaced by letters
     *
     * @example
     * ```php
     * $faker->lexify();           // 'sakh'
     * $faker->lexify('id-????');   // 'id-xoqe'
     * $faker->lexify('???-###');   // 'abc-123' (can combine with numerify)
     * ```
     */
    public function lexify(string $string = '????'): string
    {
        self::initializeArrays();

        return preg_replace_callback('/\?/', function () {
            return self::$lettersArray[array_rand(self::$lettersArray)];
        }, $string);
    }

    /**
     * Replace # with digits, ? with letters, and * with either.
     *
     * Performance: O(n) where n = string length
     * Memory: O(1) extra space (single-pass replacement)
     *
     * Replacement rules:
     * - # → Random digit (0-9)
     * - ? → Random letter (a-z)
     * - * → Random digit or letter
     *
     * @param string $string String template (default: '## ??')
     * @return string String with placeholders replaced
     *
     * @example
     * ```php
     * $faker->bothify();                // '46 hd'
     * $faker->bothify('?????-#####');   // 'lsadj-10298'
     * $faker->bothify('***-***');       // 'a8x-4k2'
     * $faker->bothify('user-**##');     // 'user-x1837'
     * ```
     */
    public function bothify(string $string = '## ??'): string
    {
        self::initializeArrays();

        return preg_replace_callback('/[#\?\*]/', function ($matches) {
            $char = $matches[0];

            if ($char === '#') {
                return self::$digitsArray[array_rand(self::$digitsArray)];
            }

            if ($char === '?') {
                return self::$lettersArray[array_rand(self::$lettersArray)];
            }

            // * can be either digit or letter
            $combined = array_merge(self::$digitsArray, self::$lettersArray);
            return $combined[array_rand($combined)];
        }, $string);
    }

    /**
     * Replace * characters with random ASCII printable characters.
     *
     * Performance: O(n) where n = string length
     * Memory: O(1) extra space
     *
     * ASCII printable range: 0-9, a-z, A-Z, and symbols
     *
     * @param string $string String template (default: '****')
     * @return string String with * replaced by ASCII characters
     *
     * @example
     * ```php
     * $faker->asciify();           // '%Y+!'
     * $faker->asciify('user-****'); // 'user-nTw{'
     * $faker->asciify('****-****'); // 'A8x!-Zk@2'
     * ```
     */
    public function asciify(string $string = '****'): string
    {
        self::initializeArrays();

        return preg_replace_callback('/\*/', function () {
            return self::$asciiArray[array_rand(self::$asciiArray)];
        }, $string);
    }

    /**
     * Generate a random string based on a regex pattern.
     *
     * Performance: O(n*m) where n = pattern complexity, m = result length
     * Memory: O(m) for result string
     *
     * Supported patterns (simplified regex):
     * - [abc] → One of: a, b, or c
     * - [a-z] → Lowercase letter range
     * - [A-Z] → Uppercase letter range
     * - [0-9] → Digit range
     * - {n} → Exactly n repetitions
     * - {n,m} → Between n and m repetitions
     *
     * Note: This is a simplified regex implementation for common patterns.
     * For complex regex, consider using external libraries.
     *
     * @param string $pattern Regex pattern
     * @return string Generated random string
     *
     * @example
     * ```php
     * $faker->regexify('[A-Z]{5}[0-4]{3}');
     * // 'DRSQX201'
     *
     * $faker->regexify('[a-z]{3}-[0-9]{4}');
     * // 'abc-1234'
     *
     * $faker->regexify('[A-Z]{2}[0-9]{6}');
     * // 'AB123456'
     * ```
     */
    public function regexify(string $pattern = ''): string
    {
        if ($pattern === '') {
            return '';
        }

        $result = '';
        $length = strlen($pattern);
        $i = 0;

        while ($i < $length) {
            $char = $pattern[$i];

            // Character class [...]
            if ($char === '[') {
                $closePos = strpos($pattern, ']', $i);
                if ($closePos === false) {
                    $i++;
                    continue;
                }

                $class = substr($pattern, $i + 1, $closePos - $i - 1);
                $result .= $this->generateFromCharClass($class);
                $i = $closePos + 1;
                continue;
            }

            // Quantifier {n} or {n,m}
            if ($char === '{' && $result !== '') {
                $closePos = strpos($pattern, '}', $i);
                if ($closePos === false) {
                    $i++;
                    continue;
                }

                $quantifier = substr($pattern, $i + 1, $closePos - $i - 1);
                $lastChar = substr($result, -1);
                $result = substr($result, 0, -1);

                if (str_contains($quantifier, ',')) {
                    [$min, $max] = array_map('intval', explode(',', $quantifier));
                    $count = random_int($min, $max);
                } else {
                    $count = (int)$quantifier;
                }

                $result .= str_repeat($lastChar, $count);
                $i = $closePos + 1;
                continue;
            }

            // Regular character
            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * Generate a random character from a character class.
     *
     * Performance: O(n) where n = class length
     *
     * @param string $class Character class content (without brackets)
     * @return string Random character from class
     */
    private function generateFromCharClass(string $class): string
    {
        // Handle ranges (a-z, A-Z, 0-9)
        if (preg_match('/^(.)\-(.)$/', $class, $matches)) {
            $start = ord($matches[1]);
            $end = ord($matches[2]);
            return chr(random_int($start, $end));
        }

        // Handle multiple characters
        if (strlen($class) > 0) {
            return $class[random_int(0, strlen($class) - 1)];
        }

        return '';
    }

    // ================================================================
    // ADVANCED UTILITIES
    // ================================================================

    /**
     * Generate a random number with specified digits.
     *
     * Performance: O(n) where n = nbDigits
     *
     * @param int $nbDigits Number of digits
     * @param bool $strict If true, always return exactly nbDigits digits
     * @return int Random number
     *
     * @example
     * ```php
     * $faker->randomNumber(5);        // 12043 (1-5 digits)
     * $faker->randomNumber(5, true);  // 42931 (exactly 5 digits)
     * ```
     */
    public function randomNumber(int $nbDigits = 5, bool $strict = false): int
    {
        if ($nbDigits < 1) {
            return 0;
        }

        if ($strict) {
            $min = (int)str_repeat('1', $nbDigits - 1) . '0';
            if ($nbDigits === 1) {
                $min = 1;
            }
            $max = (int)str_repeat('9', $nbDigits);
            return random_int($min, $max);
        }

        $max = (int)str_repeat('9', $nbDigits);
        return random_int(0, $max);
    }

    /**
     * Generate a random float.
     *
     * Performance: O(1)
     *
     * @param int|null $nbMaxDecimals Maximum number of decimals
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float Random float
     *
     * @example
     * ```php
     * $faker->randomFloat();          // 12.9830
     * $faker->randomFloat(2);         // 43.23
     * $faker->randomFloat(1, 20, 30); // 27.2
     * ```
     */
    public function randomFloat(?int $nbMaxDecimals = null, float $min = 0, float $max = 9999999999.0): float
    {
        $value = $min + mt_rand() / mt_getrandmax() * ($max - $min);

        if ($nbMaxDecimals !== null) {
            $value = round($value, $nbMaxDecimals);
        }

        return $value;
    }

    /**
     * Generate a random integer between min and max.
     *
     * Performance: O(1)
     *
     * @param int $min Minimum value (default: 0)
     * @param int $max Maximum value (default: 2147483647)
     * @return int Random integer
     *
     * @example
     * ```php
     * $faker->numberBetween();        // 120378987
     * $faker->numberBetween(0, 100);  // 32
     * $faker->numberBetween(1, 10);   // 7
     * ```
     */
    public function numberBetween(int $min = 0, int $max = 2147483647): int
    {
        return random_int($min, $max);
    }
}
