<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class SmsMessage
 *
 * Fluent SMS message builder with smart character counting and segmentation.
 *
 * SMS Character Limits:
 * - GSM-7 (ASCII): 160 chars for single, 153 chars per segment for concatenated
 * - Unicode (emoji, non-Latin): 70 chars for single, 67 chars per segment
 *
 * Usage:
 * ```php
 * return (new SmsMessage)
 *     ->content('Your order #123 has shipped!')
 *     ->from('MyApp');
 *
 * // With unicode support check
 * $sms = (new SmsMessage)->content($message);
 * if ($sms->getSegmentCount() > 3) {
 *     // Message will be expensive, consider shortening
 * }
 * ```
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.1.0
 * @package     toporia/framework
 * @subpackage  Notification\Messages
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SmsMessage
{
    /** GSM-7 single message limit */
    private const GSM_SINGLE_LIMIT = 160;

    /** GSM-7 concatenated message limit per segment */
    private const GSM_CONCAT_LIMIT = 153;

    /** Unicode single message limit */
    private const UNICODE_SINGLE_LIMIT = 70;

    /** Unicode concatenated message limit per segment */
    private const UNICODE_CONCAT_LIMIT = 67;

    /** GSM-7 character set (basic Latin + common symbols) */
    private const GSM_CHARSET = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    public string $content = '';
    public ?string $from = null;

    /** @var string|null Recipient phone number (for on-demand sending) */
    public ?string $to = null;

    /** @var bool Force unicode encoding */
    private bool $forceUnicode = false;

    /** @var int|null Custom character limit */
    private ?int $customLimit = null;

    /**
     * Set message content.
     *
     * @param string $content
     * @return $this
     */
    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Set sender ID/name.
     *
     * Most carriers support alphanumeric sender IDs (max 11 chars).
     *
     * @param string $from
     * @return $this
     */
    public function from(string $from): self
    {
        $this->from = $from;
        return $this;
    }

    /**
     * Set recipient phone number.
     *
     * @param string $to Phone number in E.164 format (e.g., +1234567890)
     * @return $this
     */
    public function to(string $to): self
    {
        $this->to = $to;
        return $this;
    }

    /**
     * Force unicode encoding (for emoji or special characters).
     *
     * @param bool $force
     * @return $this
     */
    public function unicode(bool $force = true): self
    {
        $this->forceUnicode = $force;
        return $this;
    }

    /**
     * Set custom character limit.
     *
     * Useful for providers with different limits.
     *
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->customLimit = max(1, $limit);
        return $this;
    }

    /**
     * Get message character count.
     *
     * @return int
     */
    public function getLength(): int
    {
        return mb_strlen($this->content, 'UTF-8');
    }

    /**
     * Check if message contains unicode characters (requires UCS-2 encoding).
     *
     * @return bool
     */
    public function isUnicode(): bool
    {
        if ($this->forceUnicode) {
            return true;
        }

        // Check each character against GSM-7 charset
        $length = mb_strlen($this->content, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($this->content, $i, 1, 'UTF-8');
            if (mb_strpos(self::GSM_CHARSET, $char, 0, 'UTF-8') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the character limit for a single SMS.
     *
     * @return int
     */
    public function getSingleLimit(): int
    {
        if ($this->customLimit !== null) {
            return $this->customLimit;
        }

        return $this->isUnicode() ? self::UNICODE_SINGLE_LIMIT : self::GSM_SINGLE_LIMIT;
    }

    /**
     * Get the character limit per segment for concatenated SMS.
     *
     * @return int
     */
    public function getConcatLimit(): int
    {
        if ($this->customLimit !== null) {
            return (int) ($this->customLimit * 0.95); // ~5% overhead for headers
        }

        return $this->isUnicode() ? self::UNICODE_CONCAT_LIMIT : self::GSM_CONCAT_LIMIT;
    }

    /**
     * Check if message exceeds single SMS limit.
     *
     * @return bool
     */
    public function exceedsLimit(): bool
    {
        return $this->getLength() > $this->getSingleLimit();
    }

    /**
     * Get number of SMS segments required.
     *
     * Each segment is billed separately by most carriers.
     *
     * @return int Number of segments (1 = single SMS, 2+ = concatenated)
     */
    public function getSegmentCount(): int
    {
        $length = $this->getLength();
        $singleLimit = $this->getSingleLimit();

        if ($length <= $singleLimit) {
            return 1;
        }

        $concatLimit = $this->getConcatLimit();
        return (int) ceil($length / $concatLimit);
    }

    /**
     * Get estimated cost multiplier.
     *
     * Useful for cost estimation before sending.
     *
     * @return int Number of SMS credits this message will consume
     */
    public function getCostMultiplier(): int
    {
        return $this->getSegmentCount();
    }

    /**
     * Check if message fits in a single SMS.
     *
     * @return bool
     */
    public function isSingleMessage(): bool
    {
        return $this->getSegmentCount() === 1;
    }

    /**
     * Truncate message to fit within a single SMS.
     *
     * @param string $suffix Suffix to append (e.g., '...')
     * @return $this
     */
    public function truncate(string $suffix = '...'): self
    {
        $limit = $this->getSingleLimit();
        $suffixLength = mb_strlen($suffix, 'UTF-8');

        if ($this->getLength() <= $limit) {
            return $this;
        }

        $this->content = mb_substr($this->content, 0, $limit - $suffixLength, 'UTF-8') . $suffix;

        return $this;
    }

    /**
     * Get encoding type string.
     *
     * @return string 'GSM-7' or 'UCS-2'
     */
    public function getEncoding(): string
    {
        return $this->isUnicode() ? 'UCS-2' : 'GSM-7';
    }

    /**
     * Get remaining characters before exceeding single SMS limit.
     *
     * @return int Negative if already exceeded
     */
    public function getRemainingCharacters(): int
    {
        return $this->getSingleLimit() - $this->getLength();
    }

    /**
     * Validate message for sending.
     *
     * @return array<string> List of validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty(trim($this->content))) {
            $errors[] = 'SMS content cannot be empty';
        }

        if ($this->getLength() > 1600) {
            $errors[] = 'SMS content exceeds maximum length (1600 characters)';
        }

        if ($this->from !== null && mb_strlen($this->from, 'UTF-8') > 11) {
            $errors[] = 'Sender ID cannot exceed 11 characters';
        }

        return $errors;
    }

    /**
     * Check if message is valid for sending.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Convert message to array format.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'from' => $this->from,
            'to' => $this->to,
            'length' => $this->getLength(),
            'encoding' => $this->getEncoding(),
            'segments' => $this->getSegmentCount(),
            'is_unicode' => $this->isUnicode()
        ];
    }

    /**
     * Create message from string content.
     *
     * @param string $content
     * @return self
     */
    public static function create(string $content): self
    {
        return (new self())->content($content);
    }
}
