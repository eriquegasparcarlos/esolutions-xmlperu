<?php

namespace Esolutions\XmlPeru\Excepciones;

/**
 * 422 — el comprobante no pasó la validación y NO se envió a SUNAT.
 *
 * Es la excepción que hay que capturar en un punto de venta: el correlativo no
 * se consumió y el documento no existe. Lo que hay que corregir está en
 * `errores()`.
 */
class ValidacionException extends XmlPeruException
{
    /** @return array Motivos, en el orden en que los detectó la validación. */
    public function errores()
    {
        return isset($this->respuesta['errors']) ? (array) $this->respuesta['errors'] : array();
    }

    /** @return array Detalle por regla (código, línea, valor encontrado). */
    public function detalles()
    {
        return isset($this->respuesta['details']) ? (array) $this->respuesta['details'] : array();
    }
}
