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
    /**
     * Catálogo de estados (`codigoEstado()`).
     *
     * Los tres primeros son etapas del camino; los tres últimos, desenlaces.
     *
     * | Código | Estado      | Qué significa                                        |
     * |--------|-------------|------------------------------------------------------|
     * | `01`   | Registrado  | Firmado. Aún no salió — o se quedó a medias           |
     * | `02`   | Por enviar  | Firmado y esperando a que TÚ lo mandes (envío manual) |
     * | `03`   | Recibido    | Enviado; SUNAT aún no contesta, o lo está procesando  |
     * | `05`   | Aceptado    | Declarado, sin observaciones                          |
     * | `07`   | Observado   | **Aceptado** con observaciones. Es válido             |
     * | `09`   | Rechazado   | No existe para SUNAT. Corregir y volver a emitir      |
     */
    const ESTADO_REGISTRADO = '01';
    const ESTADO_POR_ENVIAR = '02';
    const ESTADO_RECIBIDO   = '03';
    const ESTADO_ACEPTADO   = '05';
    const ESTADO_OBSERVADO  = '07';
    const ESTADO_RECHAZADO  = '09';

    /** Estados en los que SUNAT ya se pronunció. Los demás siguen en curso. */
    const RESUELTOS = array(self::ESTADO_ACEPTADO, self::ESTADO_OBSERVADO, self::ESTADO_RECHAZADO);

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

    /** Tipo de comprobante: 01 factura, 03 boleta, 07 NC, 08 ND, 09 guía… @return string|null */
    public function tipoDoc()
    {
        return $this->dato('document_type_id');
    }

    /** @return string|null */
    public function serie()
    {
        return $this->dato('series');
    }

    /** @return string|null */
    public function numero()
    {
        return $this->dato('number');
    }

    /** @return string|null Fecha de emisión, `Y-m-d`. */
    public function fechaEmision()
    {
        return $this->dato('date_of_issue');
    }

    /**
     * Ticket de SUNAT.
     *
     * Solo existe para **guías y resúmenes**, que SUNAT procesa de forma
     * asíncrona: el envío devuelve un ticket y la respuesta se recoge después.
     * En una factura o una boleta siempre es `null`.
     *
     * Es informativo: preguntarle a SUNAT por el ticket lo hacemos nosotros.
     * Sirve para cotejar ante una incidencia.
     *
     * @return string|null
     */
    public function ticket()
    {
        return $this->dato('ticket');
    }

    /** ¿El XML firmado está disponible para descargar? @return bool */
    public function tieneFirma()
    {
        return (bool) $this->dato('has_signed');
    }

    /** ¿El CDR está disponible? @return bool */
    public function tieneCdr()
    {
        $incluido = $this->dato('cdr');

        return (bool) $this->dato('has_cdr') || (is_string($incluido) && $incluido !== '');
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
        return (string) $this->codigoEstado() === self::ESTADO_ACEPTADO;
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
        return (string) $this->codigoEstado() === self::ESTADO_OBSERVADO;
    }

    /** Rechazado (09): el comprobante NO existe para SUNAT y hay que corregirlo. */
    public function rechazado()
    {
        return (string) $this->codigoEstado() === self::ESTADO_RECHAZADO;
    }

    /**
     * Aceptado, con o sin observaciones: la pregunta que casi siempre se quiere
     * hacer, PERO solo tiene sentido cuando `resuelto()` es true.
     *
     * ⚠️ `valido() === false` NO significa que algo haya fallado. Mientras SUNAT
     * no se pronuncie devuelve false igual que un rechazo, y confundir las dos
     * cosas lleva a re-emitir un comprobante que estaba en camino:
     *
     *     if (! $c->valido()) { reemitir(); }          // MAL
     *     if ($c->rechazado()) { corregir(); }         // BIEN
     *     if ($c->pendiente()) { esperar(); }          // BIEN
     */
    public function valido()
    {
        return $this->aceptado() || $this->observado();
    }

    /**
     * SUNAT todavía no se ha pronunciado: firmado, encolado, enviado o en
     * proceso. No es un fallo — es el estado normal de los primeros segundos, y
     * de los primeros minutos en guías y resúmenes.
     *
     * @return bool
     */
    public function pendiente()
    {
        return ! $this->resuelto();
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
     * Código de respuesta de SUNAT.
     *
     * `"0"` cuando acepta. En un rechazo, el código del catálogo de SUNAT
     * (`2335`, `3277`…), que es lo que hay que buscar para saber qué corregir.
     * `null` mientras no haya habido intento de envío.
     *
     * Ojo: `"0"` es una cadena, no un entero. Compáralo como cadena.
     *
     * @return string|null
     */
    public function codigo()
    {
        $r = $this->resultado();

        return isset($r['code']) ? (string) $r['code'] : null;
    }

    /** Lo que dijo SUNAT en palabras. @return string|null */
    public function mensaje()
    {
        $r = $this->resultado();

        return isset($r['message']) ? $r['message'] : null;
    }

    /**
     * Motivos del rechazo, uno por línea. Vacío si no hubo rechazo.
     *
     * Van aparte del `codigo()`: el código dice cuál fue el error principal y
     * esto detalla todos.
     *
     * @return array
     */
    public function errores()
    {
        $r = $this->resultado();

        return isset($r['errors']) && is_array($r['errors']) ? $r['errors'] : array();
    }

    /**
     * Observaciones de SUNAT sobre un comprobante que SÍ aceptó (estado
     * «Observado»). No hay que re-emitir nada; conviene corregirlas para los
     * siguientes.
     *
     * Distinto de `advertencias()`, que son las que detectamos NOSOTROS al
     * emitir, antes de que SUNAT viera el comprobante.
     *
     * @return array
     */
    public function observaciones()
    {
        $r = $this->resultado();

        return isset($r['notes']) && is_array($r['notes']) ? $r['notes'] : array();
    }

    /**
     * ¿El comprobante llegó a SUNAT?
     *
     * Es la pregunta que decide qué hacer ante un fallo de envío: si llegó, hay
     * que consultar antes de reintentar —el correlativo puede estar consumido—;
     * si no llegó, reintentar es seguro.
     *
     * `null` cuando no se sabe: no ha habido intento todavía, o el fallo ocurrió
     * en un punto en que no puede afirmarse. Trata el `null` como «no se sabe»,
     * nunca como «no llegó».
     *
     * @return bool|null
     */
    public function llegoASunat()
    {
        $r = $this->resultado();

        return isset($r['llego_a_sunat']) ? (bool) $r['llego_a_sunat'] : null;
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
     * CDR tal como lo entrega SUNAT: el ZIP con `dummy/` y `R-{nombre}.xml`.
     *
     * Es lo que hay que archivar. Siempre va a la red: lo que trae incrustado
     * la consulta del camino XML es el contenido, no el envoltorio.
     *
     * @return string Binario del ZIP
     */
    public function cdr()
    {
        return $this->cpe->cdr($this->externalId());
    }

    /**
     * El XML del CDR, sin envoltorio, para leerlo.
     *
     * La consulta del camino XML ya lo trae en la misma respuesta; en ese caso
     * se devuelve sin volver a la red.
     *
     * @return string
     */
    public function cdrXml()
    {
        $incluido = $this->dato('cdr');

        if (is_string($incluido) && $incluido !== '') {
            return $incluido;
        }

        return $this->cpe->cdrXml($this->externalId());
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
