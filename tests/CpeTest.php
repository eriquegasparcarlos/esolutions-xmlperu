<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Comprobante;
use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Excepciones\ConexionException;
use Esolutions\XmlPeru\Excepciones\TiempoAgotadoException;
use Esolutions\XmlPeru\Excepciones\ValidacionException;
use Esolutions\XmlPeru\Excepciones\YaAceptadoException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CpeTest extends TestCase
{
    /** @var array Peticiones que salieron, para poder mirarlas. */
    private $enviadas = array();

    /**
     * Cliente con las respuestas ya preparadas y sin esperas reales: probar el
     * bucle de espera con `sleep` de verdad costaría minutos por test.
     */
    private function cpe(array $respuestas)
    {
        $mock  = new MockHandler($respuestas);
        $stack = HandlerStack::create($mock);

        $this->enviadas = array();
        $stack->push(Middleware::history($this->enviadas));

        $cpe = new Cpe('token-de-prueba');
        $cpe->setHttpClient(new GuzzleClient(array('handler' => $stack)));

        // Reloj simulado: cada consulta del reloj avanza 1 s, así el timeout se
        // agota en unos pocos ciclos y el test termina al instante.
        $t = 0;
        $cpe->setEsperaFn(
            function () {},
            function () use (&$t) {
                return $t++;
            }
        );

        return $cpe;
    }

    private function json($codigo, array $cuerpo)
    {
        return new Response($codigo, array('Content-Type' => 'application/json'), json_encode($cuerpo));
    }

    private function emision(array $extra = array())
    {
        return $this->json(202, array_merge(array(
            'success'     => true,
            'estado'      => 'en_cola',
            'external_id' => 'abc-123',
            'filename'    => '20000000001-01-F001-1',
            'hash'        => 'hash-x',
            'xml'         => base64_encode('<Invoice/>'),
        ), $extra));
    }

    private function documento(array $extra = array())
    {
        return $this->json(200, array('success' => true, 'data' => array('document' => array_merge(array(
            'external_id'   => 'abc-123',
            'state_type_id' => '03',
            'state'         => 'Pendiente',
            'resuelto'      => false,
        ), $extra))));
    }

    // ── emisión ──────────────────────────────────────────────────────────────

    public function test_emitir_devuelve_el_comprobante_firmado(): void
    {
        $c = $this->cpe(array($this->emision()))->emitir($this->payload());

        $this->assertSame('abc-123', $c->externalId());
        $this->assertSame('en_cola', $c->estado());
        // El XML firmado llega en la propia respuesta: es lo que hace válido al
        // comprobante y permite imprimir sin esperar al CDR.
        $this->assertSame('<Invoice/>', $c->xmlFirmado());
        $this->assertFalse($c->resuelto());
    }

    public function test_la_clave_de_idempotencia_sale_del_comprobante_no_del_azar(): void
    {
        // Es lo que hace que reintentar un envío perdido en la red no duplique
        // la emisión. Una clave aleatoria por intento no serviría de nada.
        $cpe = $this->cpe(array($this->emision(), $this->emision()));

        $cpe->emitir($this->payload());
        $primera = $this->enviadas[0]['request']->getHeaderLine('Idempotency-Key');

        $cpe->emitir($this->payload());
        $segunda = $this->enviadas[1]['request']->getHeaderLine('Idempotency-Key');

        $this->assertNotSame('', $primera);
        $this->assertSame($primera, $segunda, 'El mismo comprobante tiene que dar la misma clave.');
    }

    public function test_dos_comprobantes_distintos_no_comparten_clave(): void
    {
        $cpe = $this->cpe(array($this->emision(), $this->emision()));

        $cpe->emitir($this->payload());
        $cpe->emitir($this->payload(array('correlativo' => '2')));

        $this->assertNotSame(
            $this->enviadas[0]['request']->getHeaderLine('Idempotency-Key'),
            $this->enviadas[1]['request']->getHeaderLine('Idempotency-Key')
        );
    }

    public function test_un_payload_corregido_estrena_clave(): void
    {
        // Sin esto, la API replicaba durante 24 h la primera respuesta de ese
        // serie-correlativo: volver a firmar un comprobante que SUNAT aún no
        // había aceptado —permitido, y a veces necesario— no llegaba a ocurrir,
        // y el cliente recibía la respuesta vieja creyendo que sí.
        $cpe = $this->cpe(array($this->emision(), $this->emision()));

        $cpe->emitir($this->payload());
        $cpe->emitir($this->payload(array('tipoMoneda' => 'USD')));

        $this->assertNotSame(
            $this->enviadas[0]['request']->getHeaderLine('Idempotency-Key'),
            $this->enviadas[1]['request']->getHeaderLine('Idempotency-Key')
        );
    }

    public function test_se_puede_emitir_sin_clave(): void
    {
        $cpe = $this->cpe(array($this->emision()));

        $cpe->emitir($this->payload(), '');

        $this->assertFalse($this->enviadas[0]['request']->hasHeader('Idempotency-Key'));
    }

    // ── errores ──────────────────────────────────────────────────────────────

    public function test_un_rechazo_de_validacion_interrumpe_y_dice_por_que(): void
    {
        $cpe = $this->cpe(array($this->json(422, array(
            'success' => false,
            'message' => 'El comprobante no pasó la validación.',
            'errors'  => array('La serie B001 no corresponde a una factura.'),
            'details' => array(array('regla' => 'serie')),
        ))));

        try {
            $cpe->emitir($this->payload());
            $this->fail('Una emisión rechazada no puede pasar en silencio.');
        } catch (ValidacionException $e) {
            $this->assertStringContainsString('serie B001', $e->errores()[0]);
            $this->assertSame(array(array('regla' => 'serie')), $e->detalles());
        }
    }

    public function test_un_duplicado_trae_el_external_id_del_original(): void
    {
        // Es lo que casi siempre se quiere: el comprobante ya existe, hay que
        // descargarlo, no volver a emitirlo.
        $cpe = $this->cpe(array($this->json(409, array(
            'success'     => false,
            'message'     => 'Ya fue aceptado por SUNAT.',
            'external_id' => 'original-999',
        ))));

        try {
            $cpe->emitir($this->payload());
            $this->fail('Debió lanzar YaAceptadoException.');
        } catch (YaAceptadoException $e) {
            $this->assertSame('original-999', $e->externalId());
        }
    }

    public function test_una_red_caida_no_se_confunde_con_un_rechazo(): void
    {
        // La distinción importa: un 422 significa que no se emitió; esto
        // significa que no se sabe, y la reacción correcta es reintentar con la
        // misma clave de idempotencia.
        $cpe = $this->cpe(array(
            new \GuzzleHttp\Exception\ConnectException('sin red', new Request('POST', '/v1/cpe')),
        ));

        $this->expectException(ConexionException::class);
        $cpe->emitir($this->payload());
    }

    // ── espera ───────────────────────────────────────────────────────────────

    public function test_esperar_devuelve_el_desenlace_cuando_sunat_contesta(): void
    {
        $cpe = $this->cpe(array(
            $this->emision(),
            $this->documento(),                                                    // sigue pendiente
            $this->documento(array('state_type_id' => '05', 'state' => 'Aceptado', 'resuelto' => true)),
        ));

        $resuelto = $cpe->emitir($this->payload())->esperar(30, 1);

        $this->assertTrue($resuelto->resuelto());
        $this->assertTrue($resuelto->aceptado());
        $this->assertTrue($resuelto->valido());
    }

    public function test_observado_es_valido_no_es_un_fallo(): void
    {
        // Un comprobante observado está aceptado y declarado. Tratarlo como
        // fallo lleva a re-emitir algo que SUNAT ya aceptó, y el segundo intento
        // se lleva un 409.
        $cpe = $this->cpe(array(
            $this->documento(array('state_type_id' => '07', 'state' => 'Observado', 'resuelto' => true)),
        ));

        $c = $cpe->consultar('abc-123');

        $this->assertTrue($c->observado());
        $this->assertTrue($c->valido());
        $this->assertFalse($c->rechazado());
        $this->assertFalse($c->aceptado(), 'Aceptado limpio y observado no son lo mismo.');
    }

    public function test_un_rechazo_de_sunat_se_distingue_del_resto(): void
    {
        $cpe = $this->cpe(array(
            $this->documento(array('state_type_id' => '09', 'state' => 'Rechazado', 'resuelto' => true)),
        ));

        $c = $cpe->consultar('abc-123');

        $this->assertTrue($c->rechazado());
        $this->assertFalse($c->valido());
    }

    public function test_agotar_el_plazo_no_es_un_rechazo(): void
    {
        // El comprobante sigue firmado y encolado. La excepción lo lleva dentro
        // justamente para que quien la capture pueda imprimirlo igual.
        $cpe = $this->cpe(array_fill(0, 10, $this->documento()));

        try {
            $cpe->esperar('abc-123', 3, 1);
            $this->fail('Debió agotar el plazo.');
        } catch (TiempoAgotadoException $e) {
            $this->assertInstanceOf(Comprobante::class, $e->comprobante);
            $this->assertFalse($e->comprobante->resuelto());
            $this->assertStringContainsString('firmado y encolado', $e->getMessage());
        }
    }

    public function test_esperar_no_consulta_si_ya_esta_resuelto(): void
    {
        $cpe = $this->cpe(array(
            $this->documento(array('state_type_id' => '05', 'resuelto' => true)),
        ));

        $c = $cpe->consultar('abc-123')->esperar(30, 1);

        $this->assertTrue($c->aceptado());
        $this->assertCount(1, $this->enviadas, 'Ya estaba resuelto: no hay nada que esperar.');
    }

    // ── series ───────────────────────────────────────────────────────────────

    public function test_el_siguiente_correlativo_evita_el_choque_de_numeracion(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success' => true,
            'data'    => array('series' => array(
                array('tipo_doc' => '01', 'serie' => 'F001', 'ultimo' => 42, 'siguiente' => 43),
            )),
        ))));

        $this->assertSame(43, $cpe->siguienteCorrelativo('01', 'F001'));
    }

    public function test_una_serie_sin_emisiones_arranca_en_uno(): void
    {
        $cpe = $this->cpe(array($this->json(200, array(
            'success' => true,
            'data'    => array('series' => array()),
        ))));

        $this->assertSame(1, $cpe->siguienteCorrelativo('01', 'F900'));
    }

    // ── descargas ────────────────────────────────────────────────────────────

    public function test_descargar_devuelve_el_archivo_tal_cual(): void
    {
        $cpe = $this->cpe(array(new Response(200, array('Content-Type' => 'application/zip'), 'PK-zip-binario')));

        $this->assertSame('PK-zip-binario', $cpe->cdr('abc-123'));
    }

    private function payload(array $extra = array())
    {
        return array_merge(array(
            'tipoDoc'     => '01',
            'serie'       => 'F001',
            'correlativo' => '1',
            'emisor'      => array('ruc' => '20000000001'),
        ), $extra);
    }
}
