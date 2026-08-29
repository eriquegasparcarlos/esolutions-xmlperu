<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Comprobante;
use Esolutions\XmlPeru\Cpe;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Los accesores del comprobante.
 *
 * Existen para que leer el resultado no obligue a conocer los nombres internos
 * de la respuesta: mientras `dato('has_cdr')` era la única vía, cualquier cambio
 * de esa clave rompía integraciones en silencio, sin ser un cambio declarado.
 */
class ComprobanteTest extends TestCase
{
    private function consulta(array $documento)
    {
        $mock = new MockHandler(array(
            new Response(200, array('Content-Type' => 'application/json'), json_encode(array(
                'success' => true,
                'data'    => array('document' => $documento),
            ))),
        ));

        $cpe = new Cpe('token');
        $cpe->setHttpClient(new GuzzleClient(array('handler' => HandlerStack::create($mock))));

        return $cpe->consultar('abc-123');
    }

    public function test_los_campos_de_la_consulta_tienen_accesor(): void
    {
        $c = $this->consulta(array(
            'external_id'      => 'abc-123',
            'filename'         => '20000000001-01-F001-42',
            'document_type_id' => '01',
            'series'           => 'F001',
            'number'           => '42',
            'hash'             => 'h-1',
            'has_signed'       => true,
            'has_cdr'          => true,
            'ticket'           => null,
            'date_of_issue'    => '2026-08-28',
            'status_code'    => '05',
            'resuelto'         => true,
        ));

        $this->assertSame('abc-123', $c->externalId());
        $this->assertSame('20000000001-01-F001-42', $c->nombreArchivo());
        $this->assertSame('01', $c->tipoDoc());
        $this->assertSame('F001', $c->serie());
        $this->assertSame('42', $c->numero());
        $this->assertSame('h-1', $c->hash());
        $this->assertSame('2026-08-28', $c->fechaEmision());
        $this->assertTrue($c->tieneFirma());
        $this->assertTrue($c->tieneCdr());
    }

    public function test_el_ticket_solo_existe_en_guias_y_resumenes(): void
    {
        $factura = $this->consulta(array('status_code' => '05', 'ticket' => null));
        $this->assertNull($factura->ticket());

        $guia = $this->consulta(array('status_code' => '05', 'ticket' => 'test-db8a7cdb'));
        $this->assertSame('test-db8a7cdb', $guia->ticket());
    }

    public function test_pendiente_distingue_lo_que_sigue_en_curso_de_un_fallo(): void
    {
        // La trampa: `valido()` es false en los dos casos, y confundirlos lleva a
        // re-emitir un comprobante que estaba en camino.
        $enProceso = $this->consulta(array('status_code' => '03', 'resolved' => false));

        $this->assertTrue($enProceso->pendiente());
        $this->assertFalse($enProceso->valido());
        $this->assertFalse($enProceso->rechazado(), 'En proceso NO es un rechazo.');

        $rechazado = $this->consulta(array('status_code' => '09', 'resolved' => true));

        $this->assertFalse($rechazado->pendiente());
        $this->assertFalse($rechazado->valido());
        $this->assertTrue($rechazado->rechazado());
    }

    public function test_una_aceptacion_trae_codigo_cero(): void
    {
        $c = $this->consulta(array(
            'status_code' => '05',
            'resolved'      => true,
            'result'        => array('code' => '0', 'message' => 'La Factura numero F001-42, ha sido aceptada'),
        ));

        $this->assertSame('0', $c->codigo());
        $this->assertStringContainsString('aceptada', $c->mensaje());
        $this->assertSame(array(), $c->errores());
    }

    public function test_un_rechazo_trae_el_codigo_de_sunat_y_los_errores_aparte(): void
    {
        $c = $this->consulta(array(
            'status_code' => '09',
            'resolved'      => true,
            'result'        => array(
                'code'    => '2335',
                'message' => 'El comprobante fue rechazado.',
                'errors'  => array('El dato ingresado en el precio unitario no cumple.'),
            ),
        ));

        $this->assertSame('2335', $c->codigo());
        $this->assertCount(1, $c->errores());
    }

    public function test_las_observaciones_de_sunat_van_aparte_de_nuestras_advertencias(): void
    {
        // `observaciones()` son de SUNAT sobre un comprobante que SÍ aceptó;
        // `advertencias()` son las que detectamos nosotros antes de enviarlo.
        $c = $this->consulta(array(
            'status_code' => '07',
            'resolved'      => true,
            'result'        => array('code' => '4000', 'notes' => array('El campo domicilio fiscal está vacío.')),
        ));

        $this->assertTrue($c->observado());
        $this->assertTrue($c->valido(), 'Observado está aceptado.');
        $this->assertCount(1, $c->observaciones());
        $this->assertSame(array(), $c->advertencias());
    }

    public function test_llego_a_sunat_distingue_el_no_de_el_no_se_sabe(): void
    {
        // Es lo que decide si reintentar es seguro. Un null tratado como false
        // haría reenviar un comprobante que quizá ya está declarado.
        $sinIntento = $this->consulta(array('status_code' => '01'));
        $this->assertNull($sinIntento->llegoASunat());

        $noLlego = $this->consulta(array(
            'status_code' => '01',
            'result'        => array('reached_sunat' => false),
        ));
        $this->assertFalse($noLlego->llegoASunat());

        $llego = $this->consulta(array(
            'status_code' => '09',
            'resolved'      => true,
            'result'        => array('reached_sunat' => true),
        ));
        $this->assertTrue($llego->llegoASunat());
    }

    public function test_el_timeout_trae_el_null_explicito_y_la_accion(): void
    {
        // La forma real del caso timeout en /v1: llego_a_sunat viaja en null
        // -«no se sabe»- y accion dice que toca consultar, no reenviar.
        $c = $this->consulta(array(
            'status_code' => '03',
            'resolved'      => false,
            'result'        => array(
                'message'       => 'Tiempo de espera agotado esperando la respuesta de SUNAT.',
                'origin'        => 'timeout',
                'action'        => 'review',
                'reached_sunat' => null,
            ),
        ));

        $this->assertNull($c->llegoASunat(), 'null explicito = no se sabe, nunca false.');
        $this->assertSame('timeout', $c->origen());
        $this->assertSame('review', $c->accion());
    }

    public function test_sin_fallo_no_hay_origen_ni_accion(): void
    {
        $c = $this->consulta(array(
            'status_code' => '05',
            'resolved'      => true,
            'result'        => array('code' => '0', 'message' => 'aceptada', 'reached_sunat' => true),
        ));

        $this->assertNull($c->origen());
        $this->assertNull($c->accion());
    }

    public function test_el_numero_es_un_entero_como_lo_manda_la_api(): void
    {
        $c = $this->consulta(array('status_code' => '01', 'number' => 42));

        $this->assertSame(42, $c->numero(), 'La API manda un entero; el paquete no lo disfraza.');
    }

    public function test_el_catalogo_de_estados_esta_en_constantes(): void
    {
        $this->assertSame('03', Comprobante::ESTADO_RECIBIDO);
        $this->assertSame(
            array(Comprobante::ESTADO_ACEPTADO, Comprobante::ESTADO_OBSERVADO, Comprobante::ESTADO_RECHAZADO),
            Comprobante::RESUELTOS
        );
    }
}
