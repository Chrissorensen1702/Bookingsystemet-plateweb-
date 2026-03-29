<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Company Projects Root
    |--------------------------------------------------------------------------
    |
    | Path to the folder containing your separate company website projects.
    | Public booking branding can be loaded from:
    |   {company-folder}/bookingsystem-branding
    |   {company-folder}/branding/bookingsystem
    |   {company-folder}/branding
    |   {company-folder}
    |
    */
    'company_projects_root' => env('COMPANY_PROJECTS_ROOT', dirname(base_path())),
];

