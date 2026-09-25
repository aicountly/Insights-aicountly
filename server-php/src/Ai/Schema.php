<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

/**
 * A small JSON Schema checker for the Copilot's answers.
 *
 * Covers exactly what the Insights schemas use: object (properties, required,
 * additionalProperties false), array (items, maxItems), string (enum,
 * maxLength), number, integer, boolean, and nullable types written as
 * ["string", "null"]. Anything the model adds or omits against that is an
 * error, and an answer with errors is never shown.
 */
final class Schema
{
    /**
     * @param array<string, mixed> $schema
     * @return list<string> problems, empty when valid
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && !self::matchesType($value, $types)) {
            return [$path . ' should be ' . implode(' or ', $types)];
        }
        if ($value === null) {
            return [];
        }

        $errors = [];
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = $path . ' is not one of the allowed values';
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            $errors[] = $path . ' is too long';
        }
        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = $path . ' is below the minimum';
        }
        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = $path . ' is above the maximum';
        }

        if (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                $errors[] = $path . ' has too many items';
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    $errors = array_merge($errors, self::validate($item, $schema['items'], $path . '[' . $i . ']'));
                }
            }
        } elseif (is_array($value) && in_array('object', $types, true)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (!array_key_exists((string) $name, $value)) {
                    $errors[] = $path . '.' . $name . ' is missing';
                }
            }
            foreach ($value as $name => $item) {
                if (!isset($properties[$name])) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        $errors[] = $path . '.' . $name . ' is not expected';
                    }
                    continue;
                }
                $errors = array_merge($errors, self::validate($item, $properties[$name], $path . '.' . $name));
            }
        }

        return array_slice($errors, 0, 20);
    }

    /** @param list<string> $types */
    private static function matchesType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null'    => $value === null,
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array'   => is_array($value) && array_is_list($value),
                'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
                default   => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Builders, so every feature's schema is strict in the same way.
    // ---------------------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties, ?array $required = null): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?? array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function string(int $maxLength = 600, ?array $enum = null): array
    {
        return array_filter(['type' => 'string', 'maxLength' => $maxLength, 'enum' => $enum], static fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public static function nullableString(int $maxLength = 600): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => $maxLength];
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public static function list(array $items, int $maxItems = 8): array
    {
        return ['type' => 'array', 'items' => $items, 'maxItems' => $maxItems];
    }

    /** @return array<string, mixed> */
    public static function strings(int $maxItems = 6, int $maxLength = 300): array
    {
        return self::list(self::string($maxLength), $maxItems);
    }
}
