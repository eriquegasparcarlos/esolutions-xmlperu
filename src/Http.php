<?php

namespace Esolutions\XmlPeru;

use Esolutions\XmlPeru\Excepciones\ConexionException;
use Esolutions\XmlPeru\Excepciones\NoAutorizadoException;
use Esolutions\XmlPeru\Excepciones\NoEncontradoException;
use Esolutions\XmlPeru\Excepciones\ValidacionException;
use Esolutions\XmlPeru\Excepciones\XmlPeruException;
use Esolutions\XmlPeru\Excepciones\YaAceptadoException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Transporte compartido por los dos clientes (firma y cuenta).
 *
 * La URL base está FIJA (const BASE_URL), igual que en esolutions/apiperudev: el
 * paquete solo habla con la infraestructura de xmlperu. Lo único configurable es
 * el token.
 *
 * Traduce cada código HTTP a la excepción que corresponde, para que el que
 * integra distinga las tres situaciones que exigen reacciones opuestas: el
 * comprobante no se emitió (422), ya existía (409), o no se sabe (red).
 */
class Http
{
    /** URL base FIJA de la API (no configurable a propósito). */
    const BASE_URL = 'https://api.xmlperu.dev';

    /** @var string */
    private $token;

    /** @var GuzzleClient|null */
    private $http = null;

    /** @var float Segundos antes de dar por perdida una petición. */
    private $timeout = 30.0;

    public function __construct($token = '')
    {
        $this->token = (string) $token;
    }

    /** @return $this */
    public function setToken($token)
    {
        $this->token = (string) $token;
        return $this;
    }

    /** @return string */
    public function token()
    {
        return $this->token;
    }

    /** @return $this */
    public function setTimeout($segundos)
    {
        $this->timeout = (float) $segundos;
        return $this;
    }

    /**
     * Permite inyectar un Guzzle propio (handler simulado en los tests, proxy
     * corporativo, reintentos a medida).
     *
     * @return $this
     */
    public function setHttpClient(GuzzleClient $cliente)
    {
        $this->http = $cliente;
        return $this;
    }

    /**
     * Petición que espera JSON de vuelta.
     *
     * @param  string      $metodo  GET, POST, PATCH, DELETE
     * @param  string      $ruta    Empieza con «/»
     * @param  array|null  $cuerpo  Se manda como JSON
     * @param  array       $headers Cabeceras extra (Idempotency-Key)
     * @return array
     */
    public function json($metodo, $ruta, ?array $cuerpo = null, array $headers = array())
    {
        $respuesta = $this->enviar($metodo, $ruta, $cuerpo, $headers, 'application/json');

        $datos = json_decode($respuesta['cuerpo'], true);

        if (! is_array($datos)) {
            throw new XmlPeruException(
                'La API respondió algo que no es JSON (HTTP ' . $respuesta['codigo'] . ').',
                $respuesta['codigo']
            );
        }

        $this->reventarSiFalla($respuesta['codigo'], $datos);

        return $datos;
    }

    /**
     * Subida de un archivo (el .pfx del certificado propio).
     *
     * @param  array $campos ['nombre' => valor] que acompañan al archivo
     * @return array
     */
    public function subir($ruta, $nombreCampo, $rutaArchivo, array $campos = array())
    {
        if (! is_readable($rutaArchivo)) {
            throw new XmlPeruException('No se puede leer el archivo: ' . $rutaArchivo);
        }

        $multipart = array(array(
            'name'     => $nombreCampo,
            'contents' => fopen($rutaArchivo, 'r'),
            'filename' => basename($rutaArchivo),
        ));

        foreach ($campos as $nombre => $valor) {
            if ($valor === null) {
                continue;
            }
            $multipart[] = array('name' => $nombre, 'contents' => (string) $valor);
        }

        $respuesta = $this->enviar('POST', $ruta, null, array(), 'application/json', $multipart);

        $datos = json_decode($respuesta['cuerpo'], true);
        $datos = is_array($datos) ? $datos : array();

        $this->reventarSiFalla($respuesta['codigo'], $datos);

        return $datos;
    }

    /**
     * Petición que espera un archivo (XML firmado, ZIP del CDR).
     *
     * @return string Contenido binario
     */
    public function descargar($ruta)
    {
        $respuesta = $this->enviar('GET', $ruta, null, array(), '*/*');

        if ($respuesta['codigo'] >= 400) {
            // Un error sí llega en JSON aunque la ruta sirva archivos.
            $datos = json_decode($respuesta['cuerpo'], true);
            $this->reventarSiFalla($respuesta['codigo'], is_array($datos) ? $datos : array());

            throw new XmlPeruException('No se pudo descargar el archivo.', $respuesta['codigo']);
        }

        return $respuesta['cuerpo'];
    }

    /**
     * @return array{codigo:int,cuerpo:string}
     */
    private function enviar($metodo, $ruta, ?array $cuerpo = null, array $headers = array(), $accept = 'application/json', ?array $multipart = null)
    {
        $opciones = array(
            'headers' => array_merge(array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => $accept,
                'User-Agent'    => 'esolutions-xmlperu/' . Version::NUMERO . ' (php ' . PHP_VERSION . ')',
            ), $headers),
            'timeout'         => $this->timeout,
            // Los códigos de error se interpretan aquí abajo, no los lanza Guzzle:
            // así un 422 llega con su cuerpo (los motivos) y no como una excepción
            // de Guzzle con el detalle truncado.
            'http_errors'     => false,
        );

        if ($multipart !== null) {
            $opciones['multipart'] = $multipart;
        } elseif ($cuerpo !== null) {
            $opciones['json'] = $cuerpo;
        }

        try {
            $r = $this->cliente()->request($metodo, self::BASE_URL . $ruta, $opciones);

            return array('codigo' => (int) $r->getStatusCode(), 'cuerpo' => (string) $r->getBody());
        } catch (ConnectException $e) {
            throw new ConexionException('No se pudo conectar con xmlperu: ' . $e->getMessage());
        } catch (RequestException $e) {
            throw new ConexionException('Falló la petición a xmlperu: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new ConexionException('Falló la petición a xmlperu: ' . $e->getMessage());
        }
    }

    /**
     * Cada código HTTP tiene una reacción distinta y por eso tiene su excepción.
     */
    private function reventarSiFalla($codigo, array $datos)
    {
        if ($codigo < 400) {
            return;
        }

        $mensaje = isset($datos['message']) ? (string) $datos['message'] : 'La API devolvió HTTP ' . $codigo . '.';

        if ($codigo === 422) {
            throw new ValidacionException($mensaje, $codigo, $datos);
        }
        if ($codigo === 409) {
            throw new YaAceptadoException($mensaje, $codigo, $datos);
        }
        if ($codigo === 401 || $codigo === 403) {
            throw new NoAutorizadoException($mensaje, $codigo, $datos);
        }
        if ($codigo === 404) {
            throw new NoEncontradoException($mensaje, $codigo, $datos);
        }

        throw new XmlPeruException($mensaje, $codigo, $datos);
    }

    /** @return GuzzleClient */
    private function cliente()
    {
        if ($this->http === null) {
            $this->http = new GuzzleClient();
        }

        return $this->http;
    }

    /**
     * Lee config() de Laravel si existe; si no, devuelve el valor por defecto.
     * Permite que el paquete funcione igual sin framework.
     */
    public static function cfg($clave, $porDefecto = '')
    {
        if (! function_exists('config')) {
            return $porDefecto;
        }

        try {
            $valor = config($clave, $porDefecto);
        } catch (Throwable $e) {
            return $porDefecto;
        }

        return $valor === null ? $porDefecto : $valor;
    }
}
