<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded Institutions
    |--------------------------------------------------------------------------
    |
    | The institutions created by the database seeder, each with one finance
    | staff user. A secret set in the environment is used as given (so it can
    | match a secret delivered separately); an empty one makes the seeder
    | generate a random secret. After seeding, secrets live in the database.
    |
    */

    'seed' => [
        [
            'code' => 'UNIV-A',
            'name' => 'University A',
            'secret' => env('INSTITUTION_SECRET_UNIV_A'),
        ],
        [
            'code' => 'UNIV-B',
            'name' => 'University B',
            'secret' => env('INSTITUTION_SECRET_UNIV_B'),
        ],
        [
            'code' => 'UNIV-C',
            'name' => 'University C',
            'secret' => env('INSTITUTION_SECRET_UNIV_C'),
        ],
    ],

];
