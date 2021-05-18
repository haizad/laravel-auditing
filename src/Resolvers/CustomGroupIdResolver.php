<?php

namespace OwenIt\Auditing\Resolvers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class CustomGroupIdResolver implements \OwenIt\Auditing\Contracts\CustomGroupIdResolver
{
    /**
     * {@inheritdoc}
     */
    public static function resolve(): string
    {
        return request()->header('GID') ?? 0;
    }
}