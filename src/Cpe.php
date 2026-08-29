<?php

namespace Esolutions\XmlPeru;

use Esolutions\XmlPeru\Excepciones\NoAutorizadoException;
use Esolutions\XmlPeru\Excepciones\TiempoAgotadoException;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Cliente de firma: emite, consulta y descarga comprobantes de UNA empresa.
 *
 * Se autentica con el token de empresa (ability `cpe:sign`), que es el que
 * devuelve «crear empresa» o `POST /v1/empresas/{ruc}/token`. Si prefieres
 * usuario y contraseña al estilo QPSE, usa `Cpe::desdeLogin()`.
 *
 * El emisor viaja DENTRO del payload (`company`): la API no lo inyecta. El
 * token dice quién firma, el payload dice quién emite, y tienen que coincidir.
 */
class Cpe
{
    /** @var Http */
    private $http;

    /**
     * Credenciales para renovar el token cuando caduca. Solo se guardan si el
     * cliente se creó con `desdeLogin()`.
     *
     * @var array|null
     */
    private $credenciales = null;

    /** @var callable Espera entre consultas; aislada para poder simularla. */
    private $dormir;

    /** @var callable Reloj; aislado por lo mismo. */
    private $reloj;

    /**
     * @param string|null $token Token de empresa. Si es null, se lee de
     *                           config('esolutions.xmlperu.token').
     */
    public function __construct($token = null)
    {
        $this->http = new Http($token !== null ? $token : Http::cfg('esolutions.xmlperu.token', ''));

        $this->dormir = function ($segundos) {
            sleep($segundos);
        };
        $this->reloj = function () {
            return time();
        };
    }

    /** @return self */
    public static function make($token = null)
    {
        return new self($token);
    }

    /**
     * Inicia sesión con usuario y contraseña (el estilo de QPSE y otros
     * proveedores) y devuelve un cliente listo para emitir.
     *
     * El token que entrega el login **caduca en una hora**. El cliente lo
     * renueva solo al toparse con un 401, así que un proceso largo no se cae a
     * los sesenta minutos.
     *
     * Aviso que importa si vas a escalar: cada login reemplaza la sesión
     * anterior de esa empresa. Dos procesos que hagan login se van echando el
     * uno al otro. Para eso está el token permanente, que no caduca y admite
     * tantos procesos como quieras.
     *
     * @return self
     */
    public static function desdeLogin($usuario, $password, ?GuzzleClient $http = null)
    {
        $cliente = new self('');

        if ($http !== null) {
            $cliente->setHttpClient($http);
        }

        $cliente->credenciales = array('usuario' => $usuario, 'password' => $password);
        $cliente->autenticar();

        return $cliente;
    }

    /** @return $this */
    public function setToken($token)
    {
        $this->http->setToken($token);
        return $this;
    }

    /** @return string */
    public function token()
    {
        return $this->http->token();
    }

    /** Guzzle propio (tests, proxy, reintentos). @return $this */
    public function setHttpClient(GuzzleClient $cliente)
    {
        $this->http->setHttpClient($cliente);
        return $this;
    }

    /** @return $this */
    public function setTimeout($segundos)
    {
        $this->http->setTimeout($segundos);
        return $this;
    }

    /**
     * Sustituye la espera y el reloj de `esperar()`. Para los tests: sin esto,
     * probar el bucle de espera costaría minutos reales.
     *
     * @return $this
     */
    public function setEsperaFn(callable $dormir, ?callable $reloj = null)
    {
        $this->dormir = $dormir;

        if ($reloj !== null) {
            $this->reloj = $reloj;
        }

        return $this;
    }

    // ── emisión ──────────────────────────────────────────────────────────────

    /**
     * Emite un comprobante desde el payload en español.
     *
     * Responde en cuanto está firmado: el envío a SUNAT queda encolado. El
     * comprobante ya es válido —lo que lo hace válido es la firma— así que se
     * puede imprimir y entregar sin esperar al CDR.
     *
     * La `Idempotency-Key` se genera sola si no la pasas, derivada del propio
     * comprobante. Es lo que hace que un reintento tras un corte de red no
     * emita el comprobante dos veces: sin ella, el segundo intento choca con el
     * correlativo del primero.
     *
     * Pasa `''` para no mandar clave, y `'la-tuya'` para poner la tuya.
     *
     * @param  array       $payload
     * @param  string|null $idempotencyKey
     * @return Comprobante
     *
     * @throws \Esolutions\XmlPeru\Excepciones\ValidacionException  422: no se emitió, y por qué.
     * @throws \Esolutions\XmlPeru\Excepciones\YaAceptadoException  409: SUNAT ya lo aceptó antes.
     */
    public function emitir(array $payload, $idempotencyKey = null)
    {
        $clave = $idempotencyKey !== null ? $idempotencyKey : $this->claveIdempotencia($payload);

        $headers = $clave === '' ? array() : array('Idempotency-Key' => $clave);

        $r = $this->peticion('POST', '/v1/cpe', $payload, $headers);

        return Comprobante::deEmision($this, $r);
    }

    /**
     * Emite y espera el desenlace en una sola llamada.
     *
     * Atajo para procesos por lotes y conciliaciones. En un punto de venta usa
     * `emitir()` a secas: ver la advertencia de `Comprobante::esperar()`.
     *
     * @return Comprobante
     */
    public function emitirYEsperar(array $payload, $timeout = 60, $intervalo = 2, $idempotencyKey = null)
    {
        return $this->emitir($payload, $idempotencyKey)->esperar($timeout, $intervalo);
    }

    // ── consulta ─────────────────────────────────────────────────────────────

    /** @return Comprobante */
    public function consultar($externalId)
    {
        $r = $this->peticion('GET', '/v1/cpe/' . rawurlencode($externalId));

        $documento = isset($r['data']['document']) ? $r['data']['document'] : array();

        return Comprobante::deConsulta($this, $documento);
    }

    /**
     * Consulta hasta que SUNAT se pronuncie.
     *
     * @return Comprobante
     *
     * @throws TiempoAgotadoException
     */
    public function esperar($externalId, $timeout = 60, $intervalo = 2)
    {
        $intervalo = max(1, (int) $intervalo);
        $reloj     = $this->reloj;
        $dormir    = $this->dormir;

        $comprobante = $this->consultar($externalId);

        if ($comprobante->resuelto()) {
            return $comprobante;
        }

        $limite = call_user_func($reloj) + $timeout;

        while (call_user_func($reloj) < $limite) {
            call_user_func($dormir, $intervalo);

            $comprobante = $this->consultar($externalId);

            if ($comprobante->resuelto()) {
                return $comprobante;
            }
        }

        throw new TiempoAgotadoException(
            'SUNAT no se pronunció en ' . $timeout . ' s. El comprobante está firmado y encolado: '
            . 'consúltalo más tarde o espera el webhook cpe.resuelto.',
            $comprobante
        );
    }

    /**
     * Último correlativo usado por cada serie.
     *
     * Es lo que evita el choque de numeración cuando un punto de venta se
     * reinstala o se abre una segunda caja: sin esto arrancan en 1 y cada
     * intento se lleva un 409.
     *
     * @param  string|null $tipoDoc  01, 03, 07, 08…
     * @return array
     */
    public function series($tipoDoc = null)
    {
        $ruta = '/v1/cpe/series' . ($tipoDoc !== null ? '?tipo_doc=' . rawurlencode($tipoDoc) : '');

        $r = $this->peticion('GET', $ruta);

        return isset($r['data']['series']) ? $r['data']['series'] : array();
    }

    /**
     * Siguiente correlativo de una serie, ya listo para usar.
     *
     * @return int
     */
    public function siguienteCorrelativo($tipoDoc, $serie)
    {
        foreach ($this->series($tipoDoc) as $fila) {
            if (isset($fila['serie']) && $fila['serie'] === $serie) {
                return (int) $fila['siguiente'];
            }
        }

        // Serie sin emisiones todavía.
        return 1;
    }

    // ── camino XML (migración desde otro proveedor) ──────────────────────────
    //
    // Para quien YA TIENE el XML construido y no va a rehacer su generador para
    // pasarse a un payload JSON. Van contra la superficie de compatibilidad
    // (`/api/cpe/*`), que existe justamente para que cambiar de proveedor sea
    // cambiar la URL base y nada más.
    //
    // El nombre de archivo es el de SUNAT: RUC-TIPO-SERIE-CORRELATIVO
    // (por ejemplo `20000000001-01-F001-123`), sin extensión.

    /**
     * Firma el XML y lo deja ahí: NO lo envía a SUNAT.
     *
     * Para quien quiere el XML firmado en la mano y se encarga del envío por su
     * cuenta. Ojo: consume firma igual, porque lo que se cobra es firmar.
     *
     * @param  string $nombreArchivo  RUC-TIPO-SERIE-CORRELATIVO
     * @param  string $xml            XML sin firmar (texto, no base64)
     * @return Comprobante            Con `xmlFirmado()` ya disponible
     *
     * @throws \Esolutions\XmlPeru\Excepciones\ValidacionException
     */
    public function firmarXml($nombreArchivo, $xml)
    {
        $r = $this->peticion('POST', '/api/cpe/generar', array(
            'nombre_archivo'    => $nombreArchivo,
            'contenido_archivo' => base64_encode($xml),
        ));

        // La respuesta trae `estado => 200`, que es un código HTTP repetido y no
        // un estado del comprobante. Se sustituye por el vocabulario de /v1 para
        // que `estado()` signifique lo mismo en los dos caminos.
        $r['estado']   = 'firmado';
        $r['filename'] = $nombreArchivo;

        return Comprobante::deEmision($this, $r);
    }

    /**
     * Firma el XML y encola el envío a SUNAT. Es el reemplazo directo de lo que
     * hacen SmartPSE, ValidaPSE y QPSE.
     *
     * Única diferencia con ellos: la respuesta no trae el CDR, porque el envío
     * no ocurre dentro de la petición. El desenlace se consulta después con
     * `consultarPorNombre()`, o llega por el webhook.
     *
     * @return Comprobante
     */
    public function procesarXml($nombreArchivo, $xml)
    {
        $r = $this->peticion('POST', '/api/cpe/procesar', array(
            'nombre_archivo'    => $nombreArchivo,
            'contenido_archivo' => base64_encode($xml),
        ));

        $r['filename'] = $nombreArchivo;

        return Comprobante::deEmision($this, $r);
    }

    /**
     * Estado de un comprobante por su nombre de archivo, que es como lo tienen
     * identificado los sistemas que vienen de otro proveedor —ellos no conocen
     * nuestro external_id.
     *
     * @return Comprobante
     */
    public function consultarPorNombre($nombreArchivo)
    {
        $r = $this->peticion('GET', '/api/cpe/consultar/' . rawurlencode($nombreArchivo));

        return Comprobante::deConsulta($this, $this->normalizarConsultaXml($r, $nombreArchivo));
    }

    /**
     * La consulta de compatibilidad responde con otros nombres de campo —y con
     * `estado => 200` cuando está resuelto, que es un código HTTP disfrazado de
     * estado—. Se traduce a la forma de /v1 para que un `Comprobante` signifique
     * lo mismo venga de donde venga.
     */
    private function normalizarConsultaXml(array $r, $nombreArchivo)
    {
        $resultado = array_filter(array(
            'code'    => isset($r['code']) ? $r['code'] : null,
            'message' => isset($r['message']) ? $r['message'] : null,
            'errors'  => isset($r['errors']) ? $r['errors'] : null,
            'notes'   => isset($r['observaciones']) ? $r['observaciones'] : null,
        ), function ($v) {
            return $v !== null;
        });

        return array(
            'external_id'   => isset($r['external_id']) ? $r['external_id'] : null,
            'filename'      => $nombreArchivo,
            'state_type_id' => isset($r['state_type_id']) ? $r['state_type_id'] : null,
            'resuelto'      => ! empty($r['resuelto']),
            'state'         => isset($r['message']) ? $r['message'] : null,
            'ticket'        => isset($r['ticket']) ? $r['ticket'] : null,
            'resultado'     => $resultado ? $resultado : null,
            // El CDR viaja en la misma respuesta cuando ya existe: se decodifica
            // aquí para no obligar a una descarga aparte.
            'cdr'           => isset($r['cdr']) ? base64_decode($r['cdr'], true) : null,
        );
    }

    // ── archivos ─────────────────────────────────────────────────────────────

    /** XML firmado. @return string */
    public function xml($externalId)
    {
        return $this->descarga('/v1/cpe/' . rawurlencode($externalId) . '/xml');
    }

    /**
     * CDR tal como lo entrega SUNAT: un ZIP con `dummy/` y `R-{nombre}.xml`.
     *
     * Es el formato que esperan los sistemas contables, así que este es el que
     * hay que archivar. Si solo quieres leer el contenido, `cdrXml()`.
     *
     * @return string Binario del ZIP
     */
    public function cdr($externalId)
    {
        return $this->descarga('/v1/cpe/' . rawurlencode($externalId) . '/cdr');
    }

    /**
     * El XML del CDR ya extraído, sin envoltorio.
     *
     * Para leerlo —el código de respuesta, las observaciones— sin abrir el ZIP.
     * Es el mismo contenido byte a byte que va dentro.
     *
     * Para archivar, guarda el ZIP: es lo que pide un sistema contable.
     *
     * @return string
     */
    public function cdrXml($externalId)
    {
        return $this->descarga('/v1/cpe/' . rawurlencode($externalId) . '/cdr?formato=xml');
    }

    // ── envío ────────────────────────────────────────────────────────────────

    /**
     * Envía a SUNAT un comprobante ya firmado.
     *
     * Dos usos: la empresa configurada en envío manual (el comprobante espera
     * en «Por enviar»), y el envío que agotó sus reintentos. Es idempotente.
     *
     * @return array
     */
    public function enviar($externalId)
    {
        return $this->peticion('POST', '/v1/cpe/' . rawurlencode($externalId) . '/enviar');
    }

    /** Alias de `enviar()`. @return array */
    public function reenviar($externalId)
    {
        return $this->peticion('POST', '/v1/cpe/' . rawurlencode($externalId) . '/reenviar');
    }

    // ── baja ─────────────────────────────────────────────────────────────────

    /**
     * Da de baja un comprobante ya aceptado por SUNAT.
     *
     * Mandas el motivo y nada más. De elegir el documento que SUNAT pide para
     * cada caso —comunicación de baja para facturas, resumen para boletas,
     * reversión para retenciones y percepciones—, numerarlo, firmarlo y
     * perseguir su respuesta nos encargamos nosotros.
     *
     * La baja es un **documento aparte**: el `Comprobante` que devuelve tiene su
     * propio `external_id`, su XML y su CDR, y se consulta como cualquier otro.
     * El comprobante original no cambia — conserva su estado y su CDR, porque
     * SUNAT lo aceptó y esa aceptación siguió siendo cierta.
     *
     * Queda marcado como anulado **cuando SUNAT acepta la baja**, no al pedirla:
     * una baja fuera de plazo se rechaza y el comprobante sigue vivo.
     *
     * @param  string $externalId  El del comprobante que se anula
     * @param  string $motivo      Obligatorio: SUNAT lo exige
     * @return Comprobante         La baja, no el comprobante original
     *
     * @throws \Esolutions\XmlPeru\Excepciones\XmlPeruException
     *         409 si no procede —todavía sin aceptar, ya anulado, baja en curso—;
     *         422 si no se puede: venció el plazo, falta el motivo, o es una guía.
     */
    public function anular($externalId, $motivo)
    {
        $r = $this->peticion(
            'POST',
            '/v1/cpe/' . rawurlencode($externalId) . '/anular',
            array('motivo' => $motivo),
            array('Idempotency-Key' => 'xmlperu-baja-' . hash('sha256', $externalId . '|' . $motivo))
        );

        return Comprobante::deEmision($this, $r);
    }

    // ── interno ──────────────────────────────────────────────────────────────

    /**
     * Petición con renovación de sesión: si el token vino de un login y ha
     * caducado, se renueva y se reintenta UNA vez.
     *
     * Sin esto, un proceso largo autenticado por login se cae con un 401 a los
     * sesenta minutos, en mitad del lote y sin relación aparente con la causa.
     */
    private function peticion($metodo, $ruta, ?array $cuerpo = null, array $headers = array())
    {
        try {
            return $this->http->json($metodo, $ruta, $cuerpo, $headers);
        } catch (NoAutorizadoException $e) {
            if ($this->credenciales === null || $e->getCode() !== 401) {
                throw $e;
            }

            $this->autenticar();

            return $this->http->json($metodo, $ruta, $cuerpo, $headers);
        }
    }

    private function descarga($ruta)
    {
        try {
            return $this->http->descargar($ruta);
        } catch (NoAutorizadoException $e) {
            if ($this->credenciales === null || $e->getCode() !== 401) {
                throw $e;
            }

            $this->autenticar();

            return $this->http->descargar($ruta);
        }
    }

    /** Cambia usuario y contraseña por un token de sesión. */
    private function autenticar()
    {
        $r = $this->http->json('POST', '/api/auth/cpe/token', array(
            'usuario'  => $this->credenciales['usuario'],
            'password' => $this->credenciales['password'],
        ));

        if (empty($r['access_token'])) {
            throw new NoAutorizadoException('El login no devolvió un token.', 401, $r);
        }

        $this->http->setToken($r['access_token']);
    }

    /**
     * Clave de idempotencia derivada del propio comprobante.
     *
     * Se deriva de la identidad del documento (emisor, tipo, serie, número) y
     * no al azar: así el reintento de un envío que se perdió en la red lleva la
     * MISMA clave, que es justo lo que hace que no se duplique. Una clave
     * aleatoria por intento no serviría de nada.
     */
    private function claveIdempotencia(array $payload)
    {
        $identidad = implode('-', array(
            isset($payload['emisor']['ruc']) ? $payload['emisor']['ruc'] : '',
            isset($payload['tipoDoc']) ? $payload['tipoDoc'] : '',
            isset($payload['serie']) ? $payload['serie'] : '',
            isset($payload['correlativo']) ? $payload['correlativo'] : '',
        ));

        // El contenido entra en la clave, y no solo la identidad del documento.
        //
        // Con la identidad sola, la API replicaba durante 24 h la primera
        // respuesta de ese serie-correlativo: volver a firmar un comprobante que
        // SUNAT aún no había aceptado —cosa permitida y a veces necesaria— no
        // llegaba a ocurrir, y el cliente recibía la respuesta vieja creyendo
        // que sí. Se vio probando el paquete contra la API de verdad.
        //
        // Con el contenido dentro: el reintento de un envío perdido en la red
        // manda el mismo payload, da la misma clave y no duplica; un payload
        // corregido da otra clave y pasa.
        return 'xmlperu-' . hash('sha256', $identidad . '|' . json_encode($payload));
    }
}
