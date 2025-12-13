<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail;

use Toporia\Framework\Mail\Contracts\MessageInterface;

/**
 * Abstract Class Mailable
 *
 * Abstract base class for Mailable implementations in the Email sending
 * and queuing layer providing common functionality and contracts.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
abstract class Mailable
{
    protected string $from = '';
    protected ?string $fromName = null;
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected ?string $replyTo = null;
    protected string $subject = '';
    protected ?string $view = null;
    protected array $data = [];
    protected array $attachments = [];

    /**
     * Build the message.
     *
     * Subclasses override this to configure the email.
     *
     * @return void
     */
    abstract public function buildMessage(): void;

    /**
     * Build and return the message instance.
     *
     * @return MessageInterface
     */
    final public function build(): MessageInterface
    {
        // Call subclass to build message
        $this->buildMessage();

        // Create message
        $message = new Message();
        $message->from($this->from, $this->fromName);
        $message->subject($this->subject);

        // Add recipients
        foreach ($this->to as $email) {
            $message->to($email);
        }

        foreach ($this->cc as $email) {
            $message->cc($email);
        }

        foreach ($this->bcc as $email) {
            $message->bcc($email);
        }

        if ($this->replyTo) {
            $message->replyTo($this->replyTo);
        }

        // Render view or use HTML directly
        if ($this->view) {
            $html = $this->renderView($this->view, $this->data);
            $message->html($html);
        }

        // Add attachments
        foreach ($this->attachments as $attachment) {
            $message->attach(
                $attachment['path'],
                $attachment['name'] ?? null,
                $attachment['mime'] ?? null
            );
        }

        return $message;
    }

    /**
     * Set sender.
     *
     * @param string $email
     * @param string|null $name
     * @return self
     */
    protected function from(string $email, ?string $name = null): self
    {
        $this->from = $email;
        $this->fromName = $name;
        return $this;
    }

    /**
     * Set recipient.
     *
     * @param string $email
     * @return self
     */
    protected function to(string $email): self
    {
        $this->to[] = $email;
        return $this;
    }

    /**
     * Set subject.
     *
     * @param string $subject
     * @return self
     */
    protected function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set view template.
     *
     * @param string $view View file path (relative to views directory).
     * @param array $data Data to pass to view.
     * @return self
     */
    protected function view(string $view, array $data = []): self
    {
        $this->view = $view;
        $this->data = $data;
        return $this;
    }

    /**
     * Add attachment.
     *
     * @param string $path
     * @param string|null $name
     * @param string|null $mime
     * @return self
     */
    protected function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
        ];
        return $this;
    }

    /**
     * Render view template.
     *
     * @param string $view View file path.
     * @param array $data Data for view.
     * @return string Rendered HTML.
     */
    private function renderView(string $view, array $data): string
    {
        $viewPath = $this->resolveViewPath($view);

        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        // Extract data to variables
        extract($data, EXTR_SKIP);

        // Render view
        ob_start();
        require $viewPath;
        return ob_get_clean();
    }

    /**
     * Resolve view file path.
     *
     * @param string $view
     * @return string
     */
    private function resolveViewPath(string $view): string
    {
        // Convert dot notation to path
        $path = str_replace('.', '/', $view);

        // View location - use APP_BASE_PATH or getcwd()
        $basePath = defined('APP_BASE_PATH') ? constant('APP_BASE_PATH') : getcwd();
        $locations = [
            "{$basePath}/app/Presentation/Views/emails/{$path}.php",
            "{$basePath}/resources/views/emails/{$path}.php",
        ];

        foreach ($locations as $location) {
            if (file_exists($location)) {
                return $location;
            }
        }

        return $locations[0]; // Return first location for error message
    }
}
