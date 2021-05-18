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
        // if ($request->headers->has('user_id')) {
            return request()->header('UID') ?? 0;
        //   }else{

        //   }

        // return Session::get('USER_ID');
    }
}
