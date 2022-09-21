<?php

defined('DS') or exit('No direct script access.');

return [
    /*
    |--------------------------------------------------------------------------
    | Case Insensitive Search
    |--------------------------------------------------------------------------
    |
    | Abaikan case-sensitivity pada query pencarian?
    |
    */

    'case_insensitive' => true,

    /*
    |--------------------------------------------------------------------------
    | Wildcard Search
    |--------------------------------------------------------------------------
    |
    | Izinkan pencarian wildcard?
    |
    */

    'use_wildcards' => false,

    /*
    |--------------------------------------------------------------------------
    | Datatables Colums Data Support
    |--------------------------------------------------------------------------
    |
    | Izinkan set data source ke kolom dari object data baris masing - masing
    | Referensi: https://datatables.net/reference/option/columns.data
    |
    */

    'use_column_data' => false,
];
