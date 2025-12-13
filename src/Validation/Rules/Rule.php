<?php

declare(strict_types=1);

namespace Toporia\Framework\Validation\Rules;

/**
 * Class Rule
 *
 * Factory class for creating validation rules with a fluent API.
 *
 * Usage:
 *   Rule::required()
 *   Rule::email()
 *   Rule::min(10)
 *   Rule::max(255)
 *   Rule::between(1, 100)
 *   Rule::in(['active', 'inactive'])
 *   Rule::password()->min(8)->letters()->mixedCase()->numbers()->symbols()
 *   Rule::dimensions(['min_width' => 100, 'max_height' => 500])
 *   Rule::unique('users', 'email')
 *   Rule::exists('categories', 'id')
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Validation\Rules
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class Rule
{
    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct() {}

    // ==========================================
    // Basic Type Rules
    // ==========================================

    public static function required(): Required
    {
        return new Required();
    }

    public static function email(): Email
    {
        return new Email();
    }

    public static function string(): Str
    {
        return new Str();
    }

    public static function numeric(): Numeric
    {
        return new Numeric();
    }

    public static function integer(): Integer
    {
        return new Integer();
    }

    public static function boolean(): Boolean
    {
        return new Boolean();
    }

    public static function array(?int $min = null, ?int $max = null, ?array $keys = null): Arr
    {
        return new Arr($min, $max, $keys);
    }

    // ==========================================
    // Size/Length Rules
    // ==========================================

    public static function min(int|float $value): Min
    {
        return new Min($value);
    }

    public static function max(int|float $value): Max
    {
        return new Max($value);
    }

    public static function between(int|float $min, int|float $max): Between
    {
        return new Between($min, $max);
    }

    public static function size(int|float $size): Size
    {
        return new Size($size);
    }

    public static function digits(int $length): Digits
    {
        return new Digits($length);
    }

    public static function digitsBetween(int $min, int $max): DigitsBetween
    {
        return new DigitsBetween($min, $max);
    }

    // ==========================================
    // Format Rules
    // ==========================================

    public static function url(): Url
    {
        return new Url();
    }

    public static function activeUrl(): ActiveUrl
    {
        return new ActiveUrl();
    }

    public static function ip(?string $version = null): Ip
    {
        return new Ip($version);
    }

    public static function alpha(): Alpha
    {
        return new Alpha();
    }

    public static function alphaNum(): AlphaNum
    {
        return new AlphaNum();
    }

    public static function alphaDash(): AlphaDash
    {
        return new AlphaDash();
    }

    public static function regex(string $pattern): Regex
    {
        return new Regex($pattern);
    }

    public static function notRegex(string $pattern): NotRegex
    {
        return new NotRegex($pattern);
    }

    public static function json(): Json
    {
        return new Json();
    }

    public static function uuid(): Uuid
    {
        return new Uuid();
    }

    public static function ulid(): Ulid
    {
        return new Ulid();
    }

    public static function macAddress(): MacAddress
    {
        return new MacAddress();
    }

    public static function timezone(): Timezone
    {
        return new Timezone();
    }

    public static function lowercase(): Lowercase
    {
        return new Lowercase();
    }

    public static function uppercase(): Uppercase
    {
        return new Uppercase();
    }

    // ==========================================
    // List Rules
    // ==========================================

    public static function in(array $values): In
    {
        return new In($values);
    }

    public static function notIn(array $values): NotIn
    {
        return new NotIn($values);
    }

    // ==========================================
    // Comparison Rules
    // ==========================================

    public static function same(string $field): Same
    {
        return new Same($field);
    }

    public static function different(string $field): Different
    {
        return new Different($field);
    }

    public static function confirmed(): Confirmed
    {
        return new Confirmed();
    }

    public static function gt(string $field): Gt
    {
        return new Gt($field);
    }

    public static function gte(string $field): Gte
    {
        return new Gte($field);
    }

    public static function lt(string $field): Lt
    {
        return new Lt($field);
    }

    public static function lte(string $field): Lte
    {
        return new Lte($field);
    }

    // ==========================================
    // Date Rules
    // ==========================================

    public static function date(?string $format = null): Date
    {
        return new Date($format);
    }

    public static function dateFormat(string|array $formats): DateFormat
    {
        return new DateFormat($formats);
    }

    public static function dateEquals(string $date): DateEquals
    {
        return new DateEquals($date);
    }

    public static function after(string $dateOrField): After
    {
        return new After($dateOrField);
    }

    public static function afterOrEqual(string $dateOrField): AfterOrEqual
    {
        return new AfterOrEqual($dateOrField);
    }

    public static function before(string $dateOrField): Before
    {
        return new Before($dateOrField);
    }

    public static function beforeOrEqual(string $dateOrField): BeforeOrEqual
    {
        return new BeforeOrEqual($dateOrField);
    }

    // ==========================================
    // File Rules
    // ==========================================

    public static function file(): File
    {
        return new File();
    }

    public static function image(): Image
    {
        return new Image();
    }

    public static function mimes(string|array $types): Mimes
    {
        return new Mimes($types);
    }

    public static function extensions(string|array $extensions): Extensions
    {
        return new Extensions($extensions);
    }

    public static function dimensions(array $constraints): Dimensions
    {
        return new Dimensions($constraints);
    }

    // ==========================================
    // Conditional/Required Rules
    // ==========================================

    public static function requiredIf(string $field, mixed $values): RequiredIf
    {
        return new RequiredIf($field, $values);
    }

    public static function requiredUnless(string $field, mixed $values): RequiredUnless
    {
        return new RequiredUnless($field, $values);
    }

    public static function requiredWith(string|array $fields): RequiredWith
    {
        return new RequiredWith($fields);
    }

    public static function requiredWithAll(string|array $fields): RequiredWithAll
    {
        return new RequiredWithAll($fields);
    }

    public static function requiredWithout(string|array $fields): RequiredWithout
    {
        return new RequiredWithout($fields);
    }

    public static function requiredWithoutAll(string|array $fields): RequiredWithoutAll
    {
        return new RequiredWithoutAll($fields);
    }

    // ==========================================
    // State Rules
    // ==========================================

    public static function nullable(): Nullable
    {
        return new Nullable();
    }

    public static function present(): Present
    {
        return new Present();
    }

    public static function filled(): Filled
    {
        return new Filled();
    }

    public static function sometimes(): Sometimes
    {
        return new Sometimes();
    }

    public static function prohibited(): Prohibited
    {
        return new Prohibited();
    }

    public static function prohibitedIf(string $field, mixed $values): ProhibitedIf
    {
        return new ProhibitedIf($field, $values);
    }

    public static function prohibitedUnless(string $field, mixed $values): ProhibitedUnless
    {
        return new ProhibitedUnless($field, $values);
    }

    // ==========================================
    // Acceptance Rules
    // ==========================================

    public static function accepted(): Accepted
    {
        return new Accepted();
    }

    public static function acceptedIf(string $field, mixed $value): AcceptedIf
    {
        return new AcceptedIf($field, $value);
    }

    public static function declined(): Declined
    {
        return new Declined();
    }

    public static function declinedIf(string $field, mixed $value): DeclinedIf
    {
        return new DeclinedIf($field, $value);
    }

    // ==========================================
    // String Pattern Rules
    // ==========================================

    public static function startsWith(string|array $values): StartsWith
    {
        return new StartsWith($values);
    }

    public static function endsWith(string|array $values): EndsWith
    {
        return new EndsWith($values);
    }

    public static function doesntStartWith(string|array $values): DoesntStartWith
    {
        return new DoesntStartWith($values);
    }

    public static function doesntEndWith(string|array $values): DoesntEndWith
    {
        return new DoesntEndWith($values);
    }

    // ==========================================
    // Array Rules
    // ==========================================

    public static function distinct(?string $mode = null): Distinct
    {
        return new Distinct($mode);
    }

    // ==========================================
    // Database Rules
    // ==========================================

    public static function exists(string $table, ?string $column = null, mixed $connection = null): Exists
    {
        return new Exists($table, $column, $connection);
    }

    public static function unique(string $table, ?string $column = null): Unique
    {
        return new Unique($table, $column);
    }

    // ==========================================
    // Password Rule
    // ==========================================

    public static function password(): Password
    {
        return new Password();
    }
}
