<?php

namespace OwenIt\Auditing\Resolvers;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class CustomUserIdResolver implements \OwenIt\Auditing\Contracts\CustomUserIdResolver
{
    /**
     * {@inheritdoc}
     */
    public static function resolve(): string
    {
        return Session::get('user_id');
    }
}
