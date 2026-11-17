<?php

namespace App\Support;

final class JsonData
{
    public static function equals(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }

        if (count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! self::equals($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
