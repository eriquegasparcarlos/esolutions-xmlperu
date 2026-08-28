<?php

namespace Esolutions\XmlPeru;

use Esolutions\XmlPeru\Excepciones\TiempoAgotadoException;

/**
 * Un comprobante emitido, y lo que se puede hacer con él.
 *
 * Existe por una diferencia de fondo con la API de consultas: emitir NO
 * devuelve el desenlace. Responde 202 con el XML ya firmado y el envío a SUNAT
 * corre por su cuenta. Sin esta clase, cada integrador escribe su propio bucle
 * de espera —y ese bucle es donde se equivocan todos: sin plazo, sin distinguir
 * «observado» de «rechazado», o esperando en el hilo que atiende al cliente.
 */
class Comprobante
{
    /** Estados en los que SUNAT ya se pronunció. Los demás siguen en curso. */
    const RESUELTOS = array('05', '07', '09');

    /** @var Cpe */
    private $cpe;

    /** @var array */
    private $datos;

    /** @var string|null XML firmado, solo disponible justo tras emitir. */
    private $xmlFirmado = null;

    private function __construct(Cpe $cpe, array $datos)
    {
        $this->cpe   = $cpe;
        $this->datos = $datos;
    }

    /** Construido desde la respuesta 202 de POST /v1/cpe. */
    public static function deEmision(Cpe $cpe, array $respuesta)
    {
        $c = new self($cpe, $respuesta);

        if (isset($respuesta['xml'])) {
            $decodificado = base64_decode($respuesta['xml'], true);
            $c->xmlFirmado = $decodificado === false ? null : $decodificado;
        }

        return $c;
    }

    /** Construido desde GET /v1/cpe/{external_id}. */
    public static function deConsulta(Cpe $cpe, array $documento)
    {
        return new self($cpe, $documento);
    }

    // ── identidad ────────────────────────────────────────────────────────────

    /** @return string|null */
    public function externalId()
    {
        return $this->dato('external_id');
    }

    /** @return string|null Nombre SUNAT: RUC-TIPO-SERIE-NUMERO */
    public function nombreArchivo()
    {
        return $this->dato('filename');
    }

    /** @return string|null */
    public function hash()
    {
        return $this->dato('hash');
    }

    // ── estado ───────────────────────────────────────────────────────────────

    /**
     * `en_cola` o `por_enviar` recién emitido; después el nombre del estado
     * («Aceptado», «Observado», «Rechazado»…).
     *
     * @return string|null
     */
    public function estado()
    {
        $estado = $this->dato('estado');

        return $estado !== null ? $estado : $this->dato('state');
    }

    /** Código de estado del catálogo interno: 01, 02, 03, 05, 07, 09. */
    public function codigoEstado()
    {
        return $this->dato('state_type_id');
    }

    /** ¿SUNAT ya se pronunció? Mientras sea false, el desenlace no se conoce. */
    public function resuelto()
    {
        if (array_key_exists('resuelto', $this->datos)) {
            return (bool) $this->datos['resuelto'];
        }

        return in_array((string) $this->codigoEstado(), self::RESUELTOS, true);
    }

    /** Aceptado limpio (05). */
    public function aceptado()
    {
        return (string) $this->codigoEstado() === '05';
    }

    /**
     * Aceptado CON observaciones (07).
     *
     * Ojo con esto: el comprobante es válido y está declarado. Tratarlo como un
     * fallo lleva a re-emitir algo que SUNAT ya aceptó, y el segundo intento
     * choca con un 409.
     */
    public function observado()
    {
        return (string) $this->codigoEstado() === '07';
    }

    /** Rechazado (09): el comprobante NO existe para SUNAT y hay que corregirlo. */
    public function rechazado()
    {
        return (string) $this->codigoEstado() === '09';
    }

    /** Aceptado, con o sin observaciones: la pregunta que casi siempre se quiere hacer. */
    public function valido()
    {
        return $this->aceptado() || $this->observado();
    }

    /**
     * Qué dijo SUNAT en el último intento: código, mensaje, notas.
     * `null` mientras no haya habido intento.
     *
     * @return array|null
     */
    public function resultado()
    {
        $r = $this->dato('resultado');

        return is_array($r) ? $r : null;
    }

    /**
     * Observaciones no bloqueantes detectadas al emitir: el comprobante salió,
     * pero hay algo que conviene corregir para los siguientes.
     *
     * @return array
     */
    public function advertencias()
    {
        $w = $this->dato('warnings');

        return is_array($w) ? $w : array();
    }

    // ── archivos ─────────────────────────────────────────────────────────────

    /**
     * XML firmado, disponible sin ir a la red justo después de emitir.
     *
     * Es lo que hace VÁLIDO al comprobante: se puede imprimir y entregar sin
     * esperar al CDR.
     *
     * @return string|null
     */
    public function xmlFirmado()
    {
        return $this->xmlFirmado;
    }

    /** Descarga el XML firmado. @return string */
    public function xml()
    {
        return $this->cpe->xml($this->externalId());
    }

    /**
     * CDR de SUNAT (ZIP).
     *
     * La consulta del camino XML ya lo trae en la misma respuesta; en ese caso
     * se devuelve sin volver a la red.
     *
     * @return string
     */
    public function cdr()
    {
        $incluido = $this->dato('cdr');

        if (is_string($incluido) && $incluido !== '') {
            return $incluido;
        }

        return $this->cpe->cdr($this->externalId());
    }

    // ── seguimiento ──────────────────────────────────────────────────────────

    /** Vuelve a consultar y devuelve un comprobante con el estado de ahora. */
    public function refrescar()
    {
        return $this->cpe->consultar($this->externalId());
    }

    /**
     * Espera a que SUNAT se pronuncie, consultando cada `$intervalo` segundos.
     *
     * Úsalo cuando de verdad necesites el desenlace antes de seguir (una
     * conciliación, un proceso por lotes). NO lo pongas en el camino de un
     * punto de venta: el comprobante ya es válido al firmarse, y bloquear la
     * caja hasta que SUNAT conteste convierte una lentitud de ellos en una cola
     * de clientes tuya. Para eso está el webhook `cpe.resuelto`.
     *
     * @param  int  $timeout    Segundos máximos de espera.
     * @param  int  $intervalo  Segundos entre consultas.
     * @return self             Ya resuelto (aceptado, observado o rechazado).
     *
     * @throws TiempoAgotadoException Si vence el plazo. El comprobante sigue
     *                                firmado y encolado: no es un rechazo.
     */
    public function esperar($timeout = 60, $intervalo = 2)
    {
        if ($this->resuelto()) {
            return $this;
        }

        return $this->cpe->esperar($this->externalId(), $timeout, $intervalo);
    }

    // ── datos crudos ─────────────────────────────────────────────────────────

    /** Respuesta tal cual la devolvió la API. @return array */
    public function datos()
    {
        return $this->datos;
    }

    /** @return mixed */
    public function dato($clave, $porDefecto = null)
    {
        return array_key_exists($clave, $this->datos) ? $this->datos[$clave] : $porDefecto;
    }
}
