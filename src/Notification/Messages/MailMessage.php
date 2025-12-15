<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Messages;

/**
 * Class MailMessage
 *
 * Fluent builder for email notifications with support for
 * rich HTML content, action buttons, and common email features.
 *
 * Usage:
 * ```php
 * return (new MailMessage)
 *     ->subject('Order Shipped')
 *     ->greeting('Hello John!')
 *     ->line('Your order has been shipped.')
 *     ->action('Track Order', url('/orders/123'))
 *     ->line('Thank you for shopping with us!')
 *     ->cc('manager@example.com')
 *     ->success();
 * ```
 *
 * Performance: O(N) where N = total lines for rendering
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
final class MailMessage
{
    public string $subject = '';
    public string $greeting = '';
    public string $salutation = 'Regards';
    public array $introLines = [];
    public array $outroLines = [];
    public ?array $action = null;
    public string $level = 'info'; // info, success, error, warning

    /** @var array<string> CC recipients */
    public array $cc = [];

    /** @var array<string> BCC recipients */
    public array $bcc = [];

    /** @var string|null Reply-To address */
    public ?string $replyTo = null;

    /** @var int|null Priority (1 = high, 3 = normal, 5 = low) */
    public ?int $priority = null;

    /** @var array<array{path: string, name: string|null}> Attachments */
    public array $attachments = [];

    /** @var string|null Custom view template path */
    public ?string $view = null;

    /** @var array View data for custom templates */
    public array $viewData = [];

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
     * Lines added before action() go to intro,
     * lines added after action() go to outro.
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
     * Add multiple lines at once.
     *
     * @param array<string> $lines
     * @return $this
     */
    public function lines(array $lines): self
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
        return $this;
    }

    /**
     * Add an action button.
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
     * Set message level (affects button styling).
     *
     * @param string $level 'info', 'success', 'error', 'warning'
     * @return $this
     */
    public function level(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    /**
     * Set as success message (green button).
     *
     * @return $this
     */
    public function success(): self
    {
        return $this->level('success');
    }

    /**
     * Set as error message (red button).
     *
     * @return $this
     */
    public function error(): self
    {
        return $this->level('error');
    }

    /**
     * Set as warning message (orange button).
     *
     * @return $this
     */
    public function warning(): self
    {
        return $this->level('warning');
    }

    /**
     * Add CC recipient(s).
     *
     * @param string|array<string> $address
     * @return $this
     */
    public function cc(string|array $address): self
    {
        $addresses = is_array($address) ? $address : [$address];
        $this->cc = array_merge($this->cc, $addresses);
        return $this;
    }

    /**
     * Add BCC recipient(s).
     *
     * @param string|array<string> $address
     * @return $this
     */
    public function bcc(string|array $address): self
    {
        $addresses = is_array($address) ? $address : [$address];
        $this->bcc = array_merge($this->bcc, $addresses);
        return $this;
    }

    /**
     * Set Reply-To address.
     *
     * @param string $address
     * @return $this
     */
    public function replyTo(string $address): self
    {
        $this->replyTo = $address;
        return $this;
    }

    /**
     * Set email priority.
     *
     * @param int $priority 1 = high, 3 = normal, 5 = low
     * @return $this
     */
    public function priority(int $priority): self
    {
        $this->priority = max(1, min(5, $priority));
        return $this;
    }

    /**
     * Mark as high priority.
     *
     * @return $this
     */
    public function highPriority(): self
    {
        return $this->priority(1);
    }

    /**
     * Mark as low priority.
     *
     * @return $this
     */
    public function lowPriority(): self
    {
        return $this->priority(5);
    }

    /**
     * Add file attachment.
     *
     * @param string $path File path
     * @param string|null $name Display name (optional)
     * @return $this
     */
    public function attach(string $path, ?string $name = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name
        ];
        return $this;
    }

    /**
     * Use custom view template instead of default rendering.
     *
     * @param string $view View path
     * @param array $data View data
     * @return $this
     */
    public function view(string $view, array $data = []): self
    {
        $this->view = $view;
        $this->viewData = $data;
        return $this;
    }

    /**
     * Add data for custom view.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function with(string $key, mixed $value): self
    {
        $this->viewData[$key] = $value;
        return $this;
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
        // Use custom view if specified
        if ($this->view !== null) {
            return $this->renderView();
        }

        return $this->renderDefault();
    }

    /**
     * Render using default template.
     *
     * @return string
     */
    private function renderDefault(): string
    {
        $html = $this->getOpeningStyles();

        // Greeting
        if ($this->greeting) {
            $html .= sprintf('<h2 style="color: #333; margin-bottom: 20px;">%s</h2>', e($this->greeting));
        }

        // Intro lines
        foreach ($this->introLines as $line) {
            $html .= sprintf('<p style="color: #555; line-height: 1.6; margin: 10px 0;">%s</p>', e($line));
        }

        // Action button
        if ($this->action) {
            $html .= $this->renderActionButton();
        }

        // Outro lines
        foreach ($this->outroLines as $outroLine) {
            $html .= sprintf('<p style="color: #555; line-height: 1.6; margin: 10px 0;">%s</p>', e($outroLine));
        }

        // Salutation
        if ($this->salutation) {
            $html .= sprintf(
                '<p style="color: #555; margin-top: 30px;">%s</p>',
                e($this->salutation)
            );
        }

        $html .= '</div></div>';

        return $html;
    }

    /**
     * Render using custom view template.
     *
     * @return string
     */
    private function renderView(): string
    {
        if (function_exists('view')) {
            $data = array_merge($this->viewData, [
                'greeting' => $this->greeting,
                'introLines' => $this->introLines,
                'outroLines' => $this->outroLines,
                'action' => $this->action,
                'salutation' => $this->salutation,
                'level' => $this->level
            ]);

            return view($this->view, $data);
        }

        // Fallback to default if view helper not available
        return $this->renderDefault();
    }

    /**
     * Get opening HTML and styles.
     *
     * @return string
     */
    private function getOpeningStyles(): string
    {
        return '<div style="background-color: #f4f4f4; padding: 40px 0;">' .
            '<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; ' .
            'border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 40px;">';
    }

    /**
     * Render action button with appropriate styling.
     *
     * @return string
     */
    private function renderActionButton(): string
    {
        $buttonColor = match ($this->level) {
            'success' => '#28a745',
            'error' => '#dc3545',
            'warning' => '#ffc107',
            default => '#007bff'
        };

        $textColor = $this->level === 'warning' ? '#212529' : '#ffffff';

        return sprintf(
            '<div style="text-align: center; margin: 30px 0;">' .
            '<a href="%s" style="background-color: %s; color: %s; padding: 14px 32px; ' .
            'text-decoration: none; border-radius: 6px; display: inline-block; ' .
            'font-weight: bold; font-size: 14px;">%s</a>' .
            '</div>',
            htmlspecialchars($this->action['url']),
            $buttonColor,
            $textColor,
            htmlspecialchars($this->action['text'])
        );
    }

    /**
     * Convert message to array format.
     *
     * Useful for serialization and debugging.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'greeting' => $this->greeting,
            'salutation' => $this->salutation,
            'introLines' => $this->introLines,
            'outroLines' => $this->outroLines,
            'action' => $this->action,
            'level' => $this->level,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'replyTo' => $this->replyTo,
            'priority' => $this->priority,
            'attachments' => $this->attachments
        ];
    }
}
