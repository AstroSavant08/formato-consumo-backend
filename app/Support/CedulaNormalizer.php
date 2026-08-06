<?php

namespace App\Support;

class CedulaNormalizer
{
    public static function normalize(?string $cedula): string
    {
        return preg_replace('/\D+/', '', trim((string) $cedula)) ?? '';
    }
}
