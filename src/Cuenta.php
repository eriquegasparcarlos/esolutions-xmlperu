<?php

namespace Esolutions\XmlPeru;

use GuzzleHttp\Client as GuzzleClient;

/**
 * Cliente de cuenta: da de alta y administra las empresas emisoras.
 *
 * Se autentica con el token de CUENTA (ability `empresas:manage`), que es otro
 * distinto del de firma. Son dos ámbitos a propósito: el token que llevas a un
 * punto de venta puede emitir, pero no dar de alta empresas ni leer sus
 * credenciales.
 *
 * Quien integra para un solo emisor no necesita esta clase; le basta con `Cpe`.
 */
class Cuenta
{
    /** @var Http */
    private $http;

    /**
     * @param string|null $token Si es null, se lee de
     *                           config('esolutions.xmlperu.token_cuenta').
     */
    public function __construct($token = null)
    {
        $this->http = new Http($token !== null ? $token : Http::cfg('esolutions.xmlperu.token_cuenta', ''));
    }

    /** @return self */
    public static function make($token = null)
    {
        return new self($token);
    }

    /** Guzzle propio (tests, proxy). @return $this */
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

    // ── empresas ─────────────────────────────────────────────────────────────

    /** Empresas de la cuenta. @return array */
    public function empresas()
    {
        $r = $this->http->json('GET', '/v1/empresas');

        return isset($r['data']['empresas']) ? $r['data']['empresas'] : array();
    }

    /** Una empresa por RUC. @return array */
    public function empresa($ruc)
    {
        $r = $this->http->json('GET', '/v1/empresas/' . rawurlencode($ruc));

        return isset($r['data']['empresa']) ? $r['data']['empresa'] : array();
    }

    /**
     * Da de alta una empresa emisora.
     *
     * Devuelve un `Empresa` que ya trae dentro el token de firma y, sobre todo,
     * `cpe()`: un cliente listo para emitir. Sin eso, el alta obliga a copiar a
     * mano un `plain_text_token` de un JSON al siguiente cliente, que es donde
     * se pierde —el token en claro **solo se muestra aquí, una vez**.
     *
     * @param  string $ruc
     * @param  string $razonSocial
     * @param  string $tipoPlan     '01' comprobante (consume firmas) · '02' certificado propio
     * @param  string $tipoEntorno  '01' demo · '02' producción
     * @param  bool   $confirmar    El RUC ya existe en otra cuenta y aun así quieres seguir.
     * @return Empresa
     */
    public function crearEmpresa($ruc, $razonSocial, $tipoPlan = '01', $tipoEntorno = '01', $confirmar = false)
    {
        $r = $this->http->json('POST', '/v1/empresas', array(
            'ruc'          => $ruc,
            'razon_social' => $razonSocial,
            'tipo_plan'    => $tipoPlan,
            'tipo_entorno' => $tipoEntorno,
            'confirmar'    => (bool) $confirmar,
        ));

        return new Empresa(isset($r['data']) ? $r['data'] : array());
    }

    /**
     * Emite un token de firma nuevo para la empresa.
     *
     * @return string Token en claro. No se vuelve a mostrar.
     */
    public function nuevoToken($ruc)
    {
        $r = $this->http->json('POST', '/v1/empresas/' . rawurlencode($ruc) . '/token');

        return isset($r['data']['token']['plain_text_token']) ? $r['data']['token']['plain_text_token'] : '';
    }

    /**
     * Cliente de firma de esa empresa, con un token recién emitido.
     *
     * @return Cpe
     */
    public function cpe($ruc)
    {
        return new Cpe($this->nuevoToken($ruc));
    }

    /**
     * Usuario y contraseña de la empresa, para quien prefiere el login estilo
     * QPSE en vez del token directo.
     *
     * @return array
     */
    public function credenciales($ruc)
    {
        $r = $this->http->json('GET', '/v1/empresas/' . rawurlencode($ruc) . '/credenciales');

        return isset($r['data']['credenciales']) ? $r['data']['credenciales'] : array();
    }

    /**
     * Elimina la empresa. Solo se permite si NO tiene movimiento en producción.
     *
     * @return array
     */
    public function eliminarEmpresa($ruc)
    {
        return $this->http->json('DELETE', '/v1/empresas/' . rawurlencode($ruc));
    }

    // ── ajustes ──────────────────────────────────────────────────────────────

    /**
     * @param string $tipoPlan '01' comprobante · '02' certificado propio
     * @return array
     */
    public function plan($ruc, $tipoPlan)
    {
        return $this->http->json('PATCH', '/v1/empresas/' . rawurlencode($ruc) . '/plan', array(
            'tipo_plan' => $tipoPlan,
        ));
    }

    /**
     * @param string $tipoEntorno '01' demo · '02' producción
     * @return array
     */
    public function entorno($ruc, $tipoEntorno)
    {
        return $this->http->json('PATCH', '/v1/empresas/' . rawurlencode($ruc) . '/entorno', array(
            'tipo_entorno' => $tipoEntorno,
        ));
    }

    /**
     * Quién manda los comprobantes a SUNAT.
     *
     * `automatico` (por defecto): firmar encola el envío. `manual`: el
     * comprobante se queda en «Por enviar» hasta que llames a `Cpe::enviar()`.
     *
     * @param  string $modo 'automatico' | 'manual'
     * @return array
     */
    public function envio($ruc, $modo)
    {
        return $this->http->json('PATCH', '/v1/empresas/' . rawurlencode($ruc) . '/envio', array(
            'modo' => $modo,
        ));
    }

    /**
     * Registra la URL donde avisamos cuando un comprobante queda resuelto.
     *
     * Devuelve el secreto de firma **una sola vez**: guárdalo, es con lo que se
     * verifica cada entrega (`Webhook::verificar`). Pasa `null` para desactivar.
     *
     * @return array
     */
    public function webhook($ruc, $url)
    {
        return $this->http->json('PATCH', '/v1/empresas/' . rawurlencode($ruc) . '/webhook', array(
            'url' => $url,
        ));
    }

    // ── certificado propio ───────────────────────────────────────────────────

    /**
     * Datos del certificado de la empresa: titular, vigencia, huella. Nunca el
     * archivo.
     *
     * @return array
     */
    public function certificado($ruc)
    {
        $r = $this->http->json('GET', '/v1/empresas/' . rawurlencode($ruc) . '/certificado');

        return isset($r['data']) ? $r['data'] : array();
    }

    /**
     * Registra el certificado propio de la empresa (.pfx).
     *
     * A partir de ahí firma con SU certificado y no con el nuestro, lo que
     * consume menos cupo por emisión. La contraseña se usa para convertirlo a
     * PEM y **se descarta**: no queda guardada en ninguna parte.
     *
     * @param  string      $rutaArchivo Ruta local del .pfx
     * @param  string|null $password
     * @return array
     */
    public function subirCertificado($ruc, $rutaArchivo, $password = null)
    {
        return $this->http->subir(
            '/v1/empresas/' . rawurlencode($ruc) . '/certificado',
            'certificado',
            $rutaArchivo,
            array('password' => $password)
        );
    }

    /**
     * Retira el certificado propio: la empresa vuelve a firmar con el del PSE.
     *
     * @return array
     */
    public function quitarCertificado($ruc)
    {
        return $this->http->json('DELETE', '/v1/empresas/' . rawurlencode($ruc) . '/certificado');
    }

    /**
     * Credenciales GRE propias de la empresa (guías de remisión).
     *
     * @return array
     */
    public function credencialesGre($ruc, $clientId, $clientSecret)
    {
        return $this->http->json('PATCH', '/v1/empresas/' . rawurlencode($ruc) . '/credenciales-gre', array(
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ));
    }

    /** Respuesta cruda de la API, por si necesitas algo que no expone el cliente. */
    public function http()
    {
        return $this->http;
    }
}
