<?php

use App\Providers\AppServiceProvider;
use Composer\InstalledVersions;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'phpbm',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    | The release PHAR gets it from the tag (app:build --build-version);
    | otherwise Composer's installed version of phpbm, or "dev".
    |
    */

    'version' => (static function (): string {
        $root = InstalledVersions::getRootPackage();
        $version = $root['name'] === 'php-baremetal/phpbm'
            ? $root['pretty_version']
            : (InstalledVersions::isInstalled('php-baremetal/phpbm') ? InstalledVersions::getPrettyVersion('php-baremetal/phpbm') : null);
        return $version === null || str_contains($version, 'no-version-set') ? 'dev' : ltrim($version, 'v');
    })(),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    */

    'env' => 'development',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        AppServiceProvider::class,
    ],

];
