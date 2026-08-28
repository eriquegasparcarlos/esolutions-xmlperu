<?php

namespace Esolutions\XmlPeru\Excepciones;

/**
 * 409 — SUNAT ya aceptó ese comprobante; no se vuelve a emitir.
 *
 * No es un fallo del que haya que recuperarse reintentando: el documento
 * existe. `externalId()` da con qué consultarlo y descargar su XML y su CDR,
 * que casi siempre es lo que el integrador quería.
 */
class YaAceptadoException extends XmlPeruException
{
    /** @return string|null */
    public function externalId()
    {
        return isset($this->respuesta['external_id']) ? $this->respuesta['external_id'] : null;
    }
}
