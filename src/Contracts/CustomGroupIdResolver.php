<?php

namespace OwenIt\Auditing\Contracts;

interface CustomGroupIdResolver
{
    /**
     * Resolve the custom group Id
     *
     * @return string
     */
    public static function resolve(): string;
}
