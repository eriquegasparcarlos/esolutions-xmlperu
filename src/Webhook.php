<?php

namespace Esolutions\XmlPeru;

/**
 * Verificación de las entregas del webhook `cpe.resuelto`.
 *
 * Cada entrega llega firmada con HMAC-SHA256 en la cabecera `X-Firma`. Verificar
 * esa firma no es opcional: la URL del webhook es pública, y sin verificar,
 * cualquiera que la descubra puede decirle a tu sistema que un comprobante fue
 * aceptado.
 */
class Webhook
{
    /** Cabecera donde viaja la firma. */
    const CABECERA = 'X-Firma';

    /**
     * ¿La entrega viene de verdad de xmlperu?
     *
     * @param  string $cuerpo  Cuerpo CRUDO de la petición, sin decodificar ni
     *                         re-serializar: cualquier reformateo cambia el HMAC.
     * @param  string $firma   Valor de la cabecera X-Firma.
     * @param  string $secreto El que devolvió configurar el webhook.
     * @return bool
     */
    public static function verificar($cuerpo, $firma, $secreto)
    {
        if (! is_string($firma) || $firma === '' || ! is_string($secreto) || $secreto === '') {
            return false;
        }

        $esperada = hash_hmac('sha256', (string) $cuerpo, $secreto);

        // hash_equals y no ===: comparar en tiempo constante evita que el
        // atacante deduzca la firma byte a byte midiendo lo que tardamos.
        return hash_equals($esperada, $firma);
    }

    /**
     * Verifica y devuelve el contenido ya decodificado.
     *
     * @return array|null `null` si la firma no cuadra.
     */
    public static function leer($cuerpo, $firma, $secreto)
    {
        if (! self::verificar($cuerpo, $firma, $secreto)) {
            return null;
        }

        $datos = json_decode((string) $cuerpo, true);

        return is_array($datos) ? $datos : null;
    }
}
