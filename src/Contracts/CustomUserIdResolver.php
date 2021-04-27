<?php

namespace OwenIt\Auditing\Contracts;

interface CustomUserIdResolver
{
    /**
     * Resolve the custom user Id
     *
     * @return string
     */
    public static function resolve(): string;
}
