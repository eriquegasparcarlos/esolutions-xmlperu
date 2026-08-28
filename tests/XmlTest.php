<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Excepciones\ValidacionException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * El camino XML: quien viene de otro proveedor ya tiene el comprobante armado.
 *
 * No va a rehacer su generador para pasarse a un payload JSON, así que si el
 * paquete solo hablara JSON, el público al que apunta la superficie de
 * compatibilidad no podría usarlo.
 */
class XmlTest extends TestCase
{
    /** @var array */
    private $enviadas = array();

    private function cpe(array $respuestas)
    {
        $stack = HandlerStack::create(new MockHandler($respuestas));

        $this->enviadas = array();
        $stack->push(Middleware::history($this->enviadas));

        $cpe = new Cpe('token');
        $cpe->setHttpClient(new GuzzleClient(array('handler' => $stack)));

        return $cpe;
    }

    private function json($codigo, array $cuerpo)
    {
        return new Response($codigo, array('Content-Type' => 'application/json'), json_encode($cuerpo));
    }

    private function cuerpoEnviado($i = 0)
    {
        return json_decode((string) $this->enviadas[$i]['request']->getBody(), true);
    }

    public function test_firmar_devuelve_el_xml_firmado_sin_enviarlo(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success'     => true,
            'message'     => 'XML firmado correctamente',
            'xml'         => base64_encode('<Invoice>firmado</Invoice>'),
            'hash'        => 'h-1',
            'external_id' => 'abc-123',
            'estado'      => 200,
        ))));

        $c = $cpe->firmarXml('20000000001-01-F001-1', '<Invoice/>');

        $this->assertSame('<Invoice>firmado</Invoice>', $c->xmlFirmado());
        $this->assertSame('abc-123', $c->externalId());
        $this->assertSame('20000000001-01-F001-1', $c->nombreArchivo());
        // La API devuelve estado=200, que es un código HTTP repetido y no un
        // estado del comprobante. Se traduce al vocabulario de /v1.
        $this->assertSame('firmado', $c->estado());
    }

    public function test_el_xml_viaja_en_base64_con_los_nombres_de_campo_de_compat(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success' => true, 'xml' => base64_encode('<x/>'), 'external_id' => 'a', 'estado' => 200,
        ))));

        $cpe->firmarXml('20000000001-01-F001-1', '<Invoice/>');

        $cuerpo = $this->cuerpoEnviado();

        $this->assertSame('20000000001-01-F001-1', $cuerpo['nombre_archivo']);
        $this->assertSame('<Invoice/>', base64_decode($cuerpo['contenido_archivo']));
    }

    public function test_procesar_firma_y_encola(): void
    {
        $cpe = $this->cpe(array($this->json(202, array(
            'success'     => true,
            'estado'      => 'en_cola',
            'message'     => 'Comprobante encolado para envío a SUNAT.',
            'external_id' => 'abc-123',
        ))));

        $c = $cpe->procesarXml('20000000001-01-F001-2', '<Invoice/>');

        $this->assertSame('en_cola', $c->estado());
        $this->assertSame('abc-123', $c->externalId());
        $this->assertFalse($c->resuelto());
    }

    public function test_un_xml_invalido_no_se_firma(): void
    {
        // Lo mismo que en el camino JSON: si no pasa la validación, no se emitió
        // y el correlativo no se consumió.
        $cpe = $this->cpe(array($this->json(422, array(
            'success' => false,
            'message' => 'El comprobante no pasó la validación.',
            'errors'  => array('La serie B001 no corresponde a una factura.'),
        ))));

        $this->expectException(ValidacionException::class);
        $cpe->firmarXml('20000000001-01-B001-1', '<Invoice/>');
    }

    public function test_consultar_por_nombre_traduce_la_respuesta_de_compat(): void
    {
        // La consulta de compatibilidad usa otros nombres de campo y manda
        // `estado => 200` cuando está resuelto: un código HTTP disfrazado de
        // estado. Sin traducir, `estado()` devolvería 200 y `aceptado()` nada.
        $cpe = $this->cpe(array($this->json(200, array(
            'success'       => true,
            'resuelto'      => true,
            'estado'        => 200,
            'state_type_id' => '05',
            'message'       => 'Aceptado por SUNAT.',
            'code'          => '0',
            'external_id'   => 'abc-123',
            'cdr'           => base64_encode('PK-cdr'),
        ))));

        $c = $cpe->consultarPorNombre('20000000001-01-F001-1');

        $this->assertTrue($c->resuelto());
        $this->assertTrue($c->aceptado());
        $this->assertTrue($c->valido());
        $this->assertSame('Aceptado por SUNAT.', $c->estado());
        $this->assertSame('0', $c->resultado()['code']);
    }

    public function test_el_cdr_que_viene_en_la_consulta_no_se_vuelve_a_descargar(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success' => true, 'resuelto' => true, 'state_type_id' => '05',
            'external_id' => 'abc-123', 'cdr' => base64_encode('PK-cdr'),
        ))));

        $c = $cpe->consultarPorNombre('20000000001-01-F001-1');

        $this->assertSame('PK-cdr', $c->cdr());
        $this->assertCount(1, $this->enviadas, 'El CDR ya venía: no hay que ir a buscarlo.');
    }

    public function test_un_rechazo_de_sunat_llega_con_su_motivo(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success'       => true,
            'resuelto'      => true,
            'state_type_id' => '09',
            'message'       => 'El comprobante fue rechazado.',
            'code'          => '2335',
            'errors'        => array('El dato ingresado en el precio unitario no cumple.'),
            'external_id'   => 'abc-123',
        ))));

        $c = $cpe->consultarPorNombre('20000000001-01-F001-3');

        $this->assertTrue($c->rechazado());
        $this->assertFalse($c->valido());
        $this->assertSame('2335', $c->resultado()['code']);
    }

    public function test_lo_que_sigue_pendiente_no_se_da_por_resuelto(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success' => true, 'resuelto' => false, 'estado' => '03',
            'state_type_id' => '03', 'message' => 'Enviado a SUNAT, esperando respuesta.',
            'external_id' => 'abc-123',
        ))));

        $c = $cpe->consultarPorNombre('20000000001-01-F001-4');

        $this->assertFalse($c->resuelto());
        $this->assertFalse($c->aceptado());
        $this->assertFalse($c->rechazado());
    }
}
