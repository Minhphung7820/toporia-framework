<?php

declare(strict_types=1);

namespace Toporia\Framework\Support;

/**
 * VarDumper - Beautiful variable dumper
 *
 * Inspired by Symfony VarDumper and Toporia's dump implementation.
 * Provides beautiful HTML output with syntax highlighting and collapsible sections.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Support
 * @since       2025-01-10
 */
class VarDumper
{
    /** @var array<string, int> References to prevent circular references */
    private static array $seen = [];

    /** @var int Dump counter for unique IDs */
    private static int $dumpId = 0;

    /** @var bool Whether styles have been output */
    private static bool $stylesOutput = false;

    /**
     * Dump a variable with beautiful formatting.
     *
     * @param mixed $var Variable to dump
     * @param int $depth Current recursion depth (internal use)
     * @return mixed Returns the dumped variable for chaining
     */
    public static function dump(mixed $var, int $depth = 0): mixed
    {
        $isCli = php_sapi_name() === 'cli';

        if ($isCli) {
            // CLI output - clean and readable
            echo "\n";
            var_dump($var);
            echo "\n";
            return $var;
        }

        // Web output - beautiful HTML formatting
        self::$dumpId++;
        $id = 'dump-' . self::$dumpId . '-' . uniqid();
        $output = self::format($var, $depth, self::$seen, $id);

        // Output with inline styles for standalone usage
        if (!self::$stylesOutput) {
            echo self::getInlineStyles();
            self::$stylesOutput = true;
        }

        echo '<pre class="dump-output">' . $output . '</pre>';

        return $var;
    }

    /**
     * Dump variables and die.
     *
     * @param mixed ...$vars Variables to dump
     * @return never
     */
    public static function dd(mixed ...$vars): never
    {
        $isCli = php_sapi_name() === 'cli';

        if (!$isCli) {
            // Detect if this is an API request (expects JSON)
            $isApiRequest = (
                isset($_SERVER['HTTP_ACCEPT']) &&
                str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            ) || (
                isset($_SERVER['CONTENT_TYPE']) &&
                str_contains($_SERVER['CONTENT_TYPE'], 'application/json')
            ) || (
                str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            );

            // Send proper HTTP status and headers
            if (!headers_sent()) {
                http_response_code(500);

                if ($isApiRequest) {
                    // API request - output JSON
                    header('Content-Type: application/json; charset=UTF-8');

                    // Clear any existing output buffers
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // Output JSON format
                    $output = [];
                    foreach ($vars as $index => $var) {
                        $output['dump_' . ($index + 1)] = $var;
                    }

                    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit(1);
                } else {
                    // Web request - output HTML
                    header('Content-Type: text/html; charset=UTF-8');
                }
            }

            // Clear any existing output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Output beautiful HTML wrapper
            echo self::getHtmlHeader();
        }

        // Dump all variables
        foreach ($vars as $index => $var) {
            if (!$isCli && count($vars) > 1) {
                echo '<div class="dump-wrapper">';
                echo '<div class="dump-header">Variable #' . ($index + 1) . '</div>';
            }

            self::dump($var);

            if (!$isCli && count($vars) > 1) {
                echo '</div>';
            }
        }

        if (!$isCli) {
            echo self::getHtmlFooter();
        }

        exit(1);
    }

    /**
     * Format variable for HTML output.
     *
     * @param mixed $var Variable to format
     * @param int $depth Current recursion depth
     * @param array $seen References to prevent circular references
     * @param string $id Unique ID for this dump
     * @return string HTML formatted output
     */
    protected static function format(mixed $var, int $depth, array &$seen, string $id): string
    {
        $type = gettype($var);

        // Get object hash only for objects
        $hash = is_object($var) ? spl_object_hash($var) : '';

        // Prevent circular references
        if (is_object($var) && isset($seen[$hash])) {
            return sprintf(
                '<span class="dump-type">%s</span> <span class="dump-value">#%d (circular reference)</span>',
                get_class($var),
                $seen[$hash]
            );
        }

        if (is_object($var)) {
            $seen[$hash] = count($seen) + 1;
        }

        return match ($type) {
            'NULL' => '<span class="dump-null">null</span>',
            'boolean' => '<span class="dump-bool">' . ($var ? 'true' : 'false') . '</span>',
            'integer' => '<span class="dump-number">' . $var . '</span>',
            'double' => '<span class="dump-number">' . $var . '</span>',
            'string' => self::formatString($var, $depth),
            'array' => self::formatArray($var, $depth, $seen, $id),
            'object' => self::formatObject($var, $depth, $seen, $id, $hash),
            'resource' => '<span class="dump-resource">resource(' . get_resource_type($var) . ')</span>',
            'resource (closed)' => '<span class="dump-resource">resource(closed)</span>',
            default => '<span class="dump-unknown">' . htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8') . '</span>',
        };
    }

    /**
     * Format string with length and preview.
     */
    protected static function formatString(string $var, int $depth): string
    {
        $length = strlen($var);
        $preview = mb_substr($var, 0, 100, 'UTF-8');
        $escaped = htmlspecialchars($preview, ENT_QUOTES, 'UTF-8');

        if ($length > 100) {
            $escaped .= ' <span class="dump-meta">... (' . ($length - 100) . ' more characters)</span>';
        }

        return sprintf(
            '<span class="dump-string">"%s"</span> <span class="dump-meta">(length: %d)</span>',
            $escaped,
            $length
        );
    }

    /**
     * Format array with collapsible sections.
     */
    protected static function formatArray(array $var, int $depth, array &$seen, string $id): string
    {
        $count = count($var);
        $indent = str_repeat('  ', $depth);
        $isExpanded = $depth < 2 && $count <= 10; // Auto-expand small arrays
        $toggleId = $id . '-array';

        $html = sprintf(
            '<span class="dump-type">array</span> <span class="dump-meta">(%d)</span>',
            $count
        );

        if ($count === 0) {
            return $html . ' <span class="dump-value">[]</span>';
        }

        $html .= ' <button class="dump-toggle" onclick="dumpToggle(\'' . $toggleId . '\')">' .
            ($isExpanded ? '▼' : '▶') . '</button>';

        $html .= '<div class="dump-content' . ($isExpanded ? '' : ' dump-hidden') . '" id="' . $toggleId . '">';
        $html .= '<span class="dump-bracket">[</span>';

        $items = [];
        $index = 0;
        foreach ($var as $key => $value) {
            $keyHtml = is_int($key) ? $key : '<span class="dump-key">"' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '"</span>';
            $valueHtml = self::format($value, $depth + 1, $seen, $id . '-' . $index);
            $items[] = $indent . '  ' . $keyHtml . ' => ' . $valueHtml;
            $index++;
        }

        $html .= "\n" . implode(",\n", $items) . "\n" . $indent;
        $html .= '<span class="dump-bracket">]</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Format object with collapsible sections.
     */
    protected static function formatObject(object $var, int $depth, array &$seen, string $id, string $hash): string
    {
        $class = get_class($var);
        $reflection = new \ReflectionClass($var);
        $properties = $reflection->getProperties();
        $indent = str_repeat('  ', $depth);
        $isExpanded = $depth < 2 && count($properties) <= 10;
        $toggleId = $id . '-object';

        $html = sprintf(
            '<span class="dump-type">%s</span> <span class="dump-meta">#%d</span>',
            $class,
            $seen[$hash]
        );

        if (count($properties) === 0) {
            return $html . ' <span class="dump-value">{}</span>';
        }

        $html .= ' <button class="dump-toggle" onclick="dumpToggle(\'' . $toggleId . '\')">' .
            ($isExpanded ? '▼' : '▶') . '</button>';

        $html .= '<div class="dump-content' . ($isExpanded ? '' : ' dump-hidden') . '" id="' . $toggleId . '">';
        $html .= '<span class="dump-bracket">{</span>';

        $items = [];
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $name = $property->getName();

            // Handle uninitialized properties (PHP 8.1+)
            try {
                $value = $property->getValue($var);
            } catch (\Error $e) {
                // Property is uninitialized
                $value = null;
            }

            $visibility = $property->isPublic() ? '+' : ($property->isProtected() ? '#' : '-');
            $valueHtml = self::format($value, $depth + 1, $seen, $id . '-' . $name);
            $items[] = $indent . '  <span class="dump-visibility">' . $visibility . '</span> ' .
                '<span class="dump-key">$' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span> = ' . $valueHtml;
        }

        $html .= "\n" . implode(",\n", $items) . "\n" . $indent;
        $html .= '<span class="dump-bracket">}</span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get HTML header with styles and scripts.
     */
    protected static function getHtmlHeader(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Variable Dump</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
        }
        body {
            background: #1e1e1e !important;
            color: #d4d4d4 !important;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif !important;
            padding: 20px;
            line-height: 1.6;
        }
        .dump-container {
            background: #252526;
            border: 1px solid #3e3e42;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            max-width: 100%;
            overflow-x: auto;
        }
        .dump-wrapper {
            background: #252526;
            border: 1px solid #3e3e42;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .dump-header {
            color: #4ec9b0;
            font-weight: bold;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #3e3e42;
        }
        .dump-type {
            color: #4ec9b0;
            font-weight: bold;
        }
        .dump-value {
            color: #ce9178;
        }
        .dump-string {
            color: #ce9178;
        }
        .dump-number {
            color: #b5cea8;
        }
        .dump-bool {
            color: #569cd6;
        }
        .dump-null {
            color: #808080;
            font-style: italic;
        }
        .dump-key {
            color: #9cdcfe;
        }
        .dump-meta {
            color: #808080;
            font-size: 0.9em;
        }
        .dump-bracket {
            color: #d4d4d4;
        }
        .dump-visibility {
            color: #808080;
            margin-right: 5px;
        }
        .dump-resource {
            color: #dcdcaa;
        }
        .dump-unknown {
            color: #d4d4d4;
        }
        .dump-toggle {
            background: #3e3e42;
            border: 1px solid #555;
            color: #d4d4d4;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 5px;
            transition: background 0.2s;
        }
        .dump-toggle:hover {
            background: #555;
        }
        .dump-content {
            margin-left: 20px;
            margin-top: 5px;
            white-space: pre;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            color: #d4d4d4;
        }
        .dump-hidden {
            display: none;
        }
        pre, .dump-output {
            background: #252526 !important;
            border: 1px solid #3e3e42 !important;
            border-radius: 6px !important;
            padding: 15px !important;
            margin: 10px 0 !important;
            overflow-x: auto !important;
            white-space: pre !important;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace !important;
            font-size: 13px !important;
            line-height: 1.5 !important;
            color: #d4d4d4 !important;
        }
    </style>
    <script>
        function dumpToggle(id) {
            const element = document.getElementById(id);
            if (!element) return;
            const button = element.previousElementSibling;
            if (element.classList.contains('dump-hidden')) {
                element.classList.remove('dump-hidden');
                if (button) button.textContent = '▼';
            } else {
                element.classList.add('dump-hidden');
                if (button) button.textContent = '▶';
            }
        }
    </script>
</head>
<body>
    <div class="dump-container">
HTML;
    }

    /**
     * Get HTML footer.
     */
    protected static function getHtmlFooter(): string
    {
        return <<<'HTML'
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get inline CSS styles for standalone dump() usage.
     */
    protected static function getInlineStyles(): string
    {
        return <<<'CSS'
<style>
.dump-output {
    background: #252526 !important;
    border: 1px solid #3e3e42 !important;
    border-radius: 6px !important;
    padding: 15px !important;
    margin: 10px 0 !important;
    overflow-x: auto !important;
    white-space: pre !important;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
    color: #d4d4d4 !important;
}
body {
    background: #1e1e1e !important;
    color: #d4d4d4 !important;
}
.dump-type { color: #4ec9b0 !important; font-weight: bold !important; }
.dump-value { color: #ce9178 !important; }
.dump-string { color: #ce9178 !important; }
.dump-number { color: #b5cea8 !important; }
.dump-bool { color: #569cd6 !important; }
.dump-null { color: #808080 !important; font-style: italic !important; }
.dump-key { color: #9cdcfe !important; }
.dump-meta { color: #808080 !important; font-size: 0.9em !important; }
.dump-bracket { color: #d4d4d4 !important; }
.dump-visibility { color: #808080 !important; margin-right: 5px !important; }
.dump-resource { color: #dcdcaa !important; }
.dump-unknown { color: #d4d4d4 !important; }
.dump-toggle {
    background: #3e3e42 !important;
    border: 1px solid #555 !important;
    color: #d4d4d4 !important;
    cursor: pointer !important;
    padding: 2px 6px !important;
    border-radius: 3px !important;
    font-size: 12px !important;
    margin-left: 5px !important;
    transition: background 0.2s !important;
}
.dump-toggle:hover { background: #555 !important; }
.dump-content {
    margin-left: 20px !important;
    margin-top: 5px !important;
    white-space: pre !important;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace !important;
    font-size: 13px !important;
}
.dump-hidden { display: none !important; }
</style>
<script>
function dumpToggle(id) {
    const element = document.getElementById(id);
    if (!element) return;
    const button = element.previousElementSibling;
    if (element.classList.contains('dump-hidden')) {
        element.classList.remove('dump-hidden');
        if (button) button.textContent = '▼';
    } else {
        element.classList.add('dump-hidden');
        if (button) button.textContent = '▶';
    }
}
</script>
CSS;
    }

    /**
     * Reset internal state (useful for testing).
     */
    public static function reset(): void
    {
        self::$seen = [];
        self::$dumpId = 0;
        self::$stylesOutput = false;
    }
}
