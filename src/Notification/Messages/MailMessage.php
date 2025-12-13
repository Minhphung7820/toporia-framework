<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class MailMessage
 *
 * Fluent builder for email notifications.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Messages
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MailMessage
{
    public string $subject = '';
    public string $greeting = '';
    public string $salutation = 'Regards';
    public array $introLines = [];
    public array $outroLines = [];
    public ?array $action = null;
    public string $level = 'info'; // info, success, error

    /**
     * Set email subject.
     *
     * @param string $subject
     * @return $this
     */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set greeting line.
     *
     * @param string $greeting
     * @return $this
     */
    public function greeting(string $greeting): self
    {
        $this->greeting = $greeting;
        return $this;
    }

    /**
     * Set salutation (closing).
     *
     * @param string $salutation
     * @return $this
     */
    public function salutation(string $salutation): self
    {
        $this->salutation = $salutation;
        return $this;
    }

    /**
     * Add a line of text.
     *
     * Lines are added before the action button.
     *
     * @param string $line
     * @return $this
     */
    public function line(string $line): self
    {
        if ($this->action === null) {
            $this->introLines[] = $line;
        } else {
            $this->outroLines[] = $line;
        }

        return $this;
    }

    /**
     * Add an action button.
     *
     * Only one action is supported per email.
     *
     * @param string $text Button text
     * @param string $url Button URL
     * @return $this
     */
    public function action(string $text, string $url): self
    {
        $this->action = [
            'text' => $text,
            'url' => $url
        ];

        return $this;
    }

    /**
     * Set message level (affects styling).
     *
     * @param string $level 'info', 'success', 'error'
     * @return $this
     */
    public function level(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Set as success message.
     *
     * @return $this
     */
    public function success(): self
    {
        return $this->level('success');
    }

    /**
     * Set as error message.
     *
     * @return $this
     */
    public function error(): self
    {
        return $this->level('error');
    }

    /**
     * Render message to HTML.
     *
     * Performance: O(N) where N = total lines
     *
     * @return string HTML content
     */
    public function render(): string
    {
        $html = '<div style="font-family: Arial, sans-serif; padding: 20px;">';

        // Greeting
        if ($this->greeting) {
            $html .= "<h2>{$this->greeting}</h2>";
        }

        // Intro lines
        foreach ($this->introLines as $line) {
            $html .= "<p>{$line}</p>";
        }

        // Action button
        if ($this->action) {
            $buttonColor = match ($this->level) {
                'success' => '#28a745',
                'error' => '#dc3545',
                default => '#007bff'
            };

            $html .= sprintf(
                '<div style="text-align: center; margin: 30px 0;">
                    <a href="%s" style="background-color: %s; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">%s</a>
                </div>',
                htmlspecialchars($this->action['url']),
                $buttonColor,
                htmlspecialchars($this->action['text'])
            );
        }

        // Outro lines
        foreach ($this->outroLines as $line) {
            $html .= "<p>{$line}</p>";
        }

        // Salutation
        if ($this->salutation) {
            $html .= "<p>{$this->salutation}</p>";
        }

        $html .= '</div>';

        return $html;
    }
}
