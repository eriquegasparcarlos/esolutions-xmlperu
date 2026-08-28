<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Excepciones\NoAutorizadoException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * El login estilo QPSE y la renovación del token.
 *
 * El token del login caduca en una hora. Sin renovación automática, un proceso
 * largo se cae con un 401 en mitad del lote, sesenta minutos después de
 * empezar y sin relación aparente con la causa.
 */
class LoginTest extends TestCase
{
    /** @var array */
    private $enviadas = array();

    private function guzzle(array $respuestas)
    {
        $stack = HandlerStack::create(new MockHandler($respuestas));

        $this->enviadas = array();
        $stack->push(Middleware::history($this->enviadas));

        return new GuzzleClient(array('handler' => $stack));
    }

    private function json($codigo, array $cuerpo)
    {
        return new Response($codigo, array('Content-Type' => 'application/json'), json_encode($cuerpo));
    }

    public function test_desde_login_deja_el_cliente_listo_para_emitir(): void
    {
        $guzzle = $this->guzzle(array(
            $this->json(200, array('access_token' => 'tok-sesion', 'expires_in' => 3600)),
            $this->json(200, array('success' => true, 'data' => array('series' => array()))),
        ));

        $cpe = Cpe::desdeLogin('usuario', 'clave', $guzzle);

        $cpe->series();

        $this->assertSame('Bearer tok-sesion', $this->enviadas[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_un_token_caducado_se_renueva_y_la_peticion_se_reintenta(): void
    {
        $guzzle = $this->guzzle(array(
            $this->json(200, array('access_token' => 'tok-1', 'expires_in' => 3600)),
            $this->json(401, array('success' => false, 'message' => 'No autorizado')),
            $this->json(200, array('access_token' => 'tok-2', 'expires_in' => 3600)),
            $this->json(200, array('success' => true, 'data' => array('series' => array()))),
        ));

        $cpe = Cpe::desdeLogin('usuario', 'clave', $guzzle);

        $cpe->series();

        $this->assertCount(4, $this->enviadas);
        $this->assertSame('Bearer tok-2', $this->enviadas[3]['request']->getHeaderLine('Authorization'));
    }

    public function test_sin_login_un_401_no_se_reintenta_a_ciegas(): void
    {
        // Quien usa el token permanente no tiene credenciales que reintentar:
        // insistir solo escondería el problema real (token mal copiado, revocado).
        $cpe = new Cpe('token-permanente');
        $cpe->setHttpClient($this->guzzle(array(
            $this->json(401, array('success' => false, 'message' => 'No autorizado')),
        )));

        $this->expectException(NoAutorizadoException::class);
        $cpe->series();
    }
}
