<?php

namespace Esolutions\XmlPeru;

use Illuminate\Support\ServiceProvider;

/**
 * ServiceProvider OPCIONAL para Laravel (auto-descubierto vía composer
 * extra.laravel.providers).
 *
 * - Registra los defaults de config('esolutions.xmlperu.*') sin pisar lo que ya haya.
 * - Bindea Cpe y Cuenta como singletons.
 * - Publica el config: php artisan vendor:publish --tag=esolutions-xmlperu-config
 *
 * Fuera de Laravel este provider no se carga y los clientes funcionan igual.
 */
class XmlPeruServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Se mezcla bajo la misma clave «esolutions» que el resto de paquetes:
        // cada uno ocupa su propio subarray (xmlperu, apiperudev, ws) y así
        // conviven en un solo config/esolutions.php.
        $this->mergeConfigFrom(__DIR__ . '/../config/esolutions.php', 'esolutions');

        $this->app->singleton(Cpe::class, function () {
            return new Cpe();
        });

        $this->app->singleton(Cuenta::class, function () {
            return new Cuenta();
        });
    }

    public function boot()
    {
        if (method_exists($this->app, 'runningInConsole') && $this->app->runningInConsole()) {
            $destino = function_exists('config_path')
                ? config_path('esolutions.php')
                : $this->app->basePath('config/esolutions.php');

            $this->publishes(array(
                __DIR__ . '/../config/esolutions.php' => $destino,
            ), 'esolutions-xmlperu-config');
        }
    }
}
