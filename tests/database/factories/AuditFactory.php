<?php

use Faker\Generator as Faker;
use OwenIt\Auditing\Models\Audit;
use OwenIt\Auditing\Tests\Models\Article;
use OwenIt\Auditing\Tests\Models\User;

/*
|--------------------------------------------------------------------------
| Audit Factories
|--------------------------------------------------------------------------
|
*/

$factory->define(Audit::class, function (Faker $faker) {
    return [
        'USER_ID' => function () {
            return factory(User::class)->create()->AUDIT_TRAILS_ID;
        },
        'USER_MODEL'    => User::class,
        'EVENT'        => 'updated',
        'AUDIT_ID' => function () {
            return factory(Article::class)->create()->AUDIT_TRAILS_ID;
        },
        'AUDIT_MODEL' => Article::class,
        'OLD_VALUES'     => [],
        'NEW_VALUES'     => [],
        'URL'            => $faker->URL,
        'IP_ADDRESS'     => $faker->ipv4,
        'BROWSER'     => $faker->userAgent,
        'TAGS'           => implode(',', $faker->words(4)),
    ];
});
