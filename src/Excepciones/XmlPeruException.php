<?php

namespace Esolutions\XmlPeru\Excepciones;

use RuntimeException;

/**
 * Base de todos los fallos del cliente.
 *
 * A diferencia de esolutions/apiperudev —que devuelve arrays y nunca lanza—,
 * aquí un fallo SÍ interrumpe. La razón es el dominio: en una consulta de RUC
 * ignorar el error deja una pantalla vacía; en una emisión deja al cliente
 * creyendo que facturó. Un `if (! $r['success'])` olvidado no puede costar un
 * comprobante que nunca existió.
 */
class XmlPeruException extends RuntimeException
{
    /** @var array Cuerpo completo de la respuesta, tal cual lo devolvió la API. */
    public $respuesta = array();

    public function __construct($mensaje, $codigo = 0, array $respuesta = array())
    {
        parent::__construct($mensaje, $codigo);
        $this->respuesta = $respuesta;
    }
}
