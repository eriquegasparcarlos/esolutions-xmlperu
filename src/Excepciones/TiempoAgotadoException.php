<?php

namespace Esolutions\XmlPeru\Excepciones;

use Esolutions\XmlPeru\Comprobante;

/**
 * `esperar()` agotó su plazo sin que SUNAT se pronunciara.
 *
 * El comprobante está firmado y encolado: esto NO es un rechazo ni una razón
 * para volver a emitir. Lo normal es imprimir con lo que ya se tiene —el XML
 * firmado es lo que hace válido al comprobante— y dejar que el desenlace llegue
 * por el webhook o por una consulta posterior.
 */
class TiempoAgotadoException extends XmlPeruException
{
    /** @var Comprobante */
    public $comprobante;

    public function __construct($mensaje, Comprobante $comprobante)
    {
        parent::__construct($mensaje, 0, $comprobante->datos());
        $this->comprobante = $comprobante;
    }
}
