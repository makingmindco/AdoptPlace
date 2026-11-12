<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Traversable;

class BsonArray implements CastsAttributes
{
    /**
     * Converte o valor armazenado no MongoDB (array BSON nativo ou string JSON legada) para array PHP.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int|string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return self::normalize($value);
    }

    /**
     * Prepara o valor para ser persistido como array/subdocumento BSON nativo no MongoDB.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array<int|string, mixed>|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => self::normalize($value)];
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
                ? self::normalizeArray($decoded)
                : [];
        }

        if ($value instanceof Arrayable) {
            return self::normalizeArray($value->toArray());
        }

        if ($value instanceof Traversable) {
            return self::normalizeArray(iterator_to_array($value));
        }

        if (is_object($value)) {
            return self::normalizeArray((array) $value);
        }

        if (is_array($value)) {
            return self::normalizeArray($value);
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return array<int|string, mixed>
     */
    private static function normalizeArray(array $items): array
    {
        foreach ($items as $index => $item) {
            if ($item instanceof Arrayable) {
                $items[$index] = self::normalizeArray($item->toArray());
            } elseif ($item instanceof Traversable) {
                $items[$index] = self::normalizeArray(iterator_to_array($item));
            } elseif (is_object($item)) {
                $items[$index] = self::normalizeArray((array) $item);
            } elseif (is_array($item)) {
                $items[$index] = self::normalizeArray($item);
            }
        }

        return $items;
    }
}

