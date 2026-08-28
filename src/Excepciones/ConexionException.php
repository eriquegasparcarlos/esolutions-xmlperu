<?php

namespace Esolutions\XmlPeru\Excepciones;

/**
 * No hubo respuesta: red caída, DNS, timeout, TLS.
 *
 * Se separa de los errores con código HTTP a propósito. Un 422 significa que la
 * emisión no ocurrió; esto significa que NO SE SABE si ocurrió — y la reacción
 * correcta es reintentar con la misma `Idempotency-Key`, no emitir de nuevo.
 */
class ConexionException extends XmlPeruException
{
}
