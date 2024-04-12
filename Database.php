<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        // This pattern matches placeholders with optional type specifiers
        $pattern = '/\?(d|f|a|#)?/';
        $index = 0;

        $callback = function($matches) use ($args, &$index) {
            $type = $matches[1] ?? '';
            $value = $args[$index++] ?? null;

            // Handle conditional block special values
            if ($value === $this->skip()) {
                return $this->skip(); // Will be processed in post-processing
            }

            return $this->formatValue($value, '?' . $type);
        };

        // Replace placeholders with actual values
        $result = preg_replace_callback($pattern, $callback, $query);

        // Handle conditional blocks
        $result = preg_replace_callback('/\{[^{}]*\}/', function($matches) {
            // Check for the skip marker
            if (strpos($matches[0], $this->skip()) !== false) {
                return '';
            }
            // Remove the curly braces, return the content
            return substr($matches[0], 1, -1);
        }, $result);

        return $result;
    }

    private function formatValue($value, $type)
    {
        switch ($type) {
            case '?d':
                return is_null($value) ? 'NULL' : intval($value);
            case '?f':
                return is_null($value) ? 'NULL' : floatval($value);
            case '?a':
                return $this->formatArray($value);
            case '?#':
                return $this->formatIdentifier($value);
            case '?':
                return $this->formatGeneric($value);
            default:
                throw new Exception("Unknown specifier: $type");
        }
    }

    private function formatArray($values)
    {
        if (!is_array($values)) {
            throw new Exception('Expected an array for ?a specifier.');
        }

        if ($this->isAssoc($values)) {
            // Handle associative arrays for setting key-value pairs
            $result = [];
            foreach ($values as $key => $value) {
                $escapedKey = $this->formatIdentifier($key);
                $escapedValue = $this->formatGeneric($value);
                $result[] = "$escapedKey = $escapedValue";
            }
            return implode(', ', $result);
        } else {
            // Handle indexed arrays for value lists
            return implode(', ', array_map([$this, 'formatGeneric'], $values));
        }
    }

    private function isAssoc(array $arr)
    {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function formatIdentifier($identifier)
    {
        if (is_array($identifier)) {
            return implode(', ', array_map(function($id) { return "`$id`"; }, $identifier));
        }
        return "`$identifier`";
    }

    private function formatGeneric($value)
    {
        if (is_null($value)) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_string($value)) {
            return "'" . $this->mysqli->real_escape_string($value) . "'";
        } elseif (is_numeric($value)) {
            return $value;
        }
        throw new Exception("Invalid type for placeholder");
    }

    public function skip()
    {
        // Use a unique marker that would not appear in a normal query
        return '/*skip*/';
    }
}
