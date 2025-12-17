<?php

declare(strict_types=1);

namespace Toporia\Framework\Error;

use Toporia\Framework\Error\Contracts\ErrorRendererInterface;
use Toporia\Framework\Http\Exceptions\HttpException;
use Throwable;

/**
 * Class HtmlErrorRenderer
 *
 * Beautiful error pages inspired by Whoops/Ignition.
 * Provides syntax-highlighted code context and full stack trace.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Error
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class HtmlErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        private bool $debug = true
    ) {
        // Security: Force debug=false in production to prevent information disclosure
        // Use $_ENV directly since env() helper may not be loaded yet
        $appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'local';
        if ($appEnv === 'production') {
            // Always disable debug in production (security)
            $this->debug = false;
        } else {
            // In non-production, respect the $debug parameter passed in
            $this->debug = $debug;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function render(Throwable $exception): void
    {
        $statusCode = $this->getStatusCode($exception);
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');

        // Set custom headers from HttpException
        if ($exception instanceof HttpException) {
            foreach ($exception->getHeaders() as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->debug) {
            echo $this->renderDebugPage($exception);
        } else {
            echo $this->renderProductionPage($exception, $statusCode);
        }
    }

    /**
     * Get HTTP status code for exception.
     *
     * @param Throwable $exception
     * @return int
     */
    private function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof HttpException) {
            return $exception->getStatusCode();
        }

        $code = $exception->getCode();
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 500;
    }

    /**
     * Render beautiful debug error page.
     *
     * @param Throwable $exception
     * @return string
     */
    private function renderDebugPage(Throwable $exception): string
    {
        $class = get_class($exception);
        $shortClass = basename(str_replace('\\', '/', $class));
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = $exception->getFile();
        $line = $exception->getLine();
        $relativeFile = $this->getRelativePath($file);

        $codeContext = $this->getCodeContext($file, $line);
        $stackTrace = $this->renderStackTrace($exception);
        $requestInfo = $this->renderRequestInfo();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$shortClass} - Toporia</title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-code: #1e293b;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --accent-red: #dc2626;
            --accent-red-light: #fef2f2;
            --accent-blue: #2563eb;
            --accent-orange: #ea580c;
            --code-line-error: rgba(220, 38, 38, 0.08);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --radius: 8px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        .topbar {
            background: var(--bg-primary);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 15px;
            color: var(--text-primary);
            text-decoration: none;
        }
        .topbar-brand svg {
            width: 28px;
            height: 28px;
        }
        .topbar-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .topbar-env {
            padding: 4px 10px;
            background: var(--accent-red-light);
            color: var(--accent-red);
            border-radius: 4px;
            font-weight: 500;
            font-size: 12px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px;
        }
        .error-header {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
        }
        .error-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--accent-red-light);
            color: var(--accent-red);
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .error-badge svg {
            width: 14px;
            height: 14px;
        }
        .error-class {
            font-size: 13px;
            color: var(--text-muted);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
            margin-bottom: 8px;
        }
        .error-message {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
            line-height: 1.4;
        }
        .error-location {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
            padding: 10px 14px;
            background: var(--bg-secondary);
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        .error-location svg {
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .error-location-file {
            color: var(--accent-blue);
        }
        .error-location-line {
            color: var(--accent-orange);
            font-weight: 600;
        }
        .section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .section-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-secondary);
        }
        .section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title svg {
            width: 16px;
            height: 16px;
            color: var(--text-muted);
        }
        .code-viewer {
            background: var(--bg-code);
            overflow-x: auto;
            padding: 16px 0;
        }
        .code-viewer pre {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
        }
        .code-line {
            display: flex;
            padding: 2px 20px;
            min-height: 26px;
            border-left: 3px solid transparent;
        }
        .code-line:hover {
            background: rgba(255, 255, 255, 0.04);
        }
        .code-line.error {
            background: rgba(239, 68, 68, 0.2);
            border-left-color: #ef4444;
        }
        .line-number {
            width: 50px;
            min-width: 50px;
            color: #6b7280;
            user-select: none;
            text-align: right;
            padding-right: 16px;
            flex-shrink: 0;
            font-size: 12px;
        }
        .code-line.error .line-number {
            color: #fca5a5;
            font-weight: 600;
        }
        .code-content {
            color: #f1f5f9;
            white-space: pre-wrap;
            word-break: break-all;
            flex: 1;
            padding-right: 20px;
        }
        .stack-list {
            padding: 0;
        }
        .stack-frame {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.15s ease;
        }
        .stack-frame:last-child {
            border-bottom: none;
        }
        .stack-frame:hover {
            background: var(--bg-secondary);
        }
        .frame-number {
            width: 28px;
            height: 28px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        .frame-content {
            flex: 1;
            min-width: 0;
        }
        .frame-function {
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 4px;
            word-break: break-all;
        }
        .frame-location {
            font-size: 12px;
            color: var(--text-muted);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
        }
        .frame-location-file {
            color: var(--text-secondary);
        }
        .frame-location-line {
            color: var(--accent-orange);
            font-weight: 500;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            font-size: 13px;
        }
        .info-row {
            display: contents;
        }
        .info-row:hover .info-label,
        .info-row:hover .info-value {
            background: var(--bg-secondary);
        }
        .info-label {
            padding: 10px 16px;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-primary);
        }
        .info-value {
            padding: 10px 16px;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
            word-break: break-all;
            background: var(--bg-primary);
        }
        .info-row:last-child .info-label,
        .info-row:last-child .info-value {
            border-bottom: none;
        }
        /* Syntax highlighting for dark code viewer */
        .keyword { color: #f472b6; font-weight: 500; }
        .string { color: #86efac; }
        .number { color: #fdba74; }
        .comment { color: #9ca3af; font-style: italic; }
        .variable { color: #93c5fd; }
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .error-header { padding: 16px; }
            .error-message { font-size: 18px; }
            .info-grid { grid-template-columns: 1fr; }
            .info-label { border-right: none; }
            .topbar-meta { display: none; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <a href="/" class="topbar-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
            Toporia
        </a>
        <div class="topbar-meta">
            <span class="topbar-env">DEBUG MODE</span>
            <span>PHP {$this->getPHPVersion()}</span>
        </div>
    </div>

    <div class="container">
        <div class="error-header">
            <div class="error-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                Exception
            </div>
            <div class="error-class">{$class}</div>
            <div class="error-message">{$message}</div>
            <div class="error-location">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span class="error-location-file">{$relativeFile}</span>
                <span>:</span>
                <span class="error-location-line">{$line}</span>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div class="section-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="16 18 22 12 16 6"/>
                        <polyline points="8 6 2 12 8 18"/>
                    </svg>
                    Code Preview
                </div>
            </div>
            <div class="code-viewer">
                <pre>{$codeContext}</pre>
            </div>
        </div>

        {$stackTrace}

        {$requestInfo}
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render production error page (simple, no details).
     *
     * @param Throwable $exception
     * @param int $statusCode HTTP status code
     * @return string
     */
    private function renderProductionPage(Throwable $exception, int $statusCode = 500): string
    {
        $title = $this->getStatusTitle($statusCode);
        $prodMessage = $this->getStatusMessage($statusCode);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - Toporia</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #0f172a;
        }
        .container {
            text-align: center;
            padding: 48px 24px;
            max-width: 480px;
        }
        .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon svg {
            width: 40px;
            height: 40px;
            color: #dc2626;
        }
        .status-code {
            font-size: 72px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
            margin-bottom: 8px;
        }
        .title {
            font-size: 24px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 12px;
        }
        .message {
            font-size: 16px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #0f172a;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: background 0.2s ease;
        }
        .btn:hover {
            background: #1e293b;
        }
        .btn svg {
            width: 16px;
            height: 16px;
        }
        .footer {
            margin-top: 48px;
            font-size: 13px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <div class="status-code">{$statusCode}</div>
        <h1 class="title">{$title}</h1>
        <p class="message">{$prodMessage}</p>
        <a href="/" class="btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Back to Home
        </a>
        <div class="footer">Powered by Toporia</div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get title for HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    private function getStatusTitle(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Error',
        };
    }

    /**
     * Get user-friendly message for HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    private function getStatusMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'The request could not be understood by the server.',
            401 => 'You need to be authenticated to access this resource.',
            403 => 'You don\'t have permission to access this resource.',
            404 => 'The page you\'re looking for doesn\'t exist.',
            405 => 'This request method is not supported.',
            409 => 'There was a conflict with the current state of the resource.',
            419 => 'Your session has expired. Please refresh the page and try again.',
            422 => 'The request was well-formed but contained invalid data.',
            429 => 'You\'ve made too many requests. Please try again later.',
            500 => 'Oops! Something went wrong on our end.',
            502 => 'The server received an invalid response.',
            503 => 'The service is temporarily unavailable. Please try again later.',
            504 => 'The server took too long to respond.',
            default => 'An unexpected error occurred.',
        };
    }

    /**
     * Get code context around the error line.
     *
     * @param string $file File path
     * @param int $line Line number
     * @param int $contextLines Number of lines before/after (default: 10)
     * @return string Formatted HTML
     */
    private function getCodeContext(string $file, int $line, int $contextLines = 10): string
    {
        if (!file_exists($file)) {
            return '<div class="code-line error"><span class="line-number">-</span><span class="code-content">Could not read file</span></div>';
        }

        $lines = file($file);
        $start = max(0, $line - $contextLines - 1);
        $end = min(count($lines), $line + $contextLines);

        $html = '';
        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $code = htmlspecialchars($lines[$i], ENT_QUOTES, 'UTF-8');
            $code = $this->highlightSyntax($code);

            $isError = $lineNumber === $line;
            $lineClass = $isError ? 'code-line error' : 'code-line';

            $html .= sprintf(
                '<div class="%s"><span class="line-number">%d</span><span class="code-content">%s</span></div>',
                $lineClass,
                $lineNumber,
                rtrim($code)
            );
        }

        return $html;
    }

    /**
     * Simple PHP syntax highlighting.
     *
     * Highlights PHP keywords, strings, comments and numbers.
     * Note: Input must be HTML-escaped BEFORE calling this method.
     *
     * @param string $code Code to highlight (already HTML-escaped)
     * @return string Highlighted code
     */
    private function highlightSyntax(string $code): string
    {
        // Order matters: strings first to avoid highlighting keywords inside strings
        // Comments (must come early to prevent other patterns matching inside comments)
        $code = preg_replace('#(//[^\n]*)#', '<span class="comment">$1</span>', $code);

        // Strings (single and double quoted) - use negative lookbehind for escaped quotes
        $code = preg_replace('/(&quot;[^&]*(?:&quot;)?|&#039;[^&]*(?:&#039;)?)/', '<span class="string">$1</span>', $code);

        // PHP keywords - use word boundaries, avoid matching inside HTML tags
        $keywords = 'function|class|public|private|protected|static|final|abstract|return|if|else|elseif|foreach|for|while|do|switch|case|break|continue|new|use|namespace|extends|implements|interface|trait|const|true|false|null|array|fn';
        $code = preg_replace('/(?<![a-zA-Z0-9_&;])(' . $keywords . ')(?![a-zA-Z0-9_])/', '<span class="keyword">$1</span>', $code);

        // Variables ($varname)
        $code = preg_replace('/(\$[a-zA-Z_][a-zA-Z0-9_]*)/', '<span class="variable">$1</span>', $code);

        // Numbers (integers and floats)
        $code = preg_replace('/(?<![a-zA-Z0-9_])(\d+(?:\.\d+)?)(?![a-zA-Z0-9_])/', '<span class="number">$1</span>', $code);

        return $code;
    }

    /**
     * Render stack trace.
     *
     * @param Throwable $exception
     * @return string HTML
     */
    private function renderStackTrace(Throwable $exception): string
    {
        $trace = $exception->getTrace();
        $frames = '';

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $fileLine = $frame['line'] ?? 0;
            $frameClass = $frame['class'] ?? '';
            $function = $frame['function'] ?? '';
            $type = $frame['type'] ?? '';

            $call = $frameClass ? "{$frameClass}{$type}{$function}()" : "{$function}()";
            $relativeFile = $this->getRelativePath($file);

            $frames .= sprintf(
                '<div class="stack-frame">
                    <div class="frame-number">%d</div>
                    <div class="frame-content">
                        <div class="frame-function">%s</div>
                        <div class="frame-location">
                            <span class="frame-location-file">%s</span>:<span class="frame-location-line">%d</span>
                        </div>
                    </div>
                </div>',
                $index + 1,
                htmlspecialchars($call, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($relativeFile, ENT_QUOTES, 'UTF-8'),
                $fileLine
            );
        }

        return <<<HTML
<div class="section">
    <div class="section-header">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            Stack Trace
        </div>
    </div>
    <div class="stack-list">
        {$frames}
    </div>
</div>
HTML;
    }

    /**
     * Render request information.
     *
     * @return string HTML
     */
    private function renderRequestInfo(): string
    {
        $method = htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? 'GET', ENT_QUOTES, 'UTF-8');
        $uri = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8');
        $protocol = htmlspecialchars($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1', ENT_QUOTES, 'UTF-8');
        $ip = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
        $userAgent = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="section">
    <div class="section-header">
        <div class="section-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            Request
        </div>
    </div>
    <div class="info-grid">
        <div class="info-row">
            <div class="info-label">Method</div>
            <div class="info-value">{$method}</div>
        </div>
        <div class="info-row">
            <div class="info-label">URI</div>
            <div class="info-value">{$uri}</div>
        </div>
        <div class="info-row">
            <div class="info-label">Protocol</div>
            <div class="info-value">{$protocol}</div>
        </div>
        <div class="info-row">
            <div class="info-label">IP Address</div>
            <div class="info-value">{$ip}</div>
        </div>
        <div class="info-row">
            <div class="info-label">User Agent</div>
            <div class="info-value">{$userAgent}</div>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Get PHP version string.
     *
     * @return string
     */
    private function getPHPVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
    }

    /**
     * Get relative path from project root.
     *
     * @param string $path Absolute path
     * @return string Relative path
     */
    private function getRelativePath(string $path): string
    {
        // Try to get project root from common patterns
        $projectRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (empty($projectRoot)) {
            // Try to detect from common framework paths
            $currentDir = dirname(__DIR__, 4); // Go up from packages/framework/src/Error
            if (is_dir($currentDir . '/app') || is_dir($currentDir . '/packages')) {
                $projectRoot = $currentDir;
            }
        } else {
            // Document root is usually /public, go up one level
            $projectRoot = dirname($projectRoot);
        }

        if (!empty($projectRoot) && str_starts_with($path, $projectRoot)) {
            return ltrim(substr($path, strlen($projectRoot)), '/\\');
        }

        return $path;
    }
}
