<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Excepciones\XmlPeruException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Dar de baja un comprobante.
 *
 * El cliente manda el motivo; el resto —qué documento pide SUNAT para cada caso,
 * su correlativo, la firma, el ticket— lo absorbe la API.
 *
 * Lo que devuelve es **la baja**, no el comprobante original: un documento
 * aparte, con su propio identificador y su propio desenlace.
 */
class AnularTest extends TestCase
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

    public function test_devuelve_la_baja_no_el_comprobante_original(): void
    {
        $cpe = $this->cpe(array($this->json(202, array(
            'success'     => true,
            'estado'      => 'en_cola',
            'external_id' => 'baja-999',
            'filename'    => '20000000001-RA-20260829-1',
            'hash'        => 'h',
            'xml'         => base64_encode('<VoidedDocuments/>'),
            'anula'       => 'original-111',
        ))));

        $baja = $cpe->anular('original-111', 'Error en el monto');

        $this->assertSame('baja-999', $baja->externalId(), 'El external_id es el de la baja.');
        $this->assertSame('original-111', $baja->dato('anula'));
        $this->assertSame('20000000001-RA-20260829-1', $baja->nombreArchivo());
        $this->assertSame('<VoidedDocuments/>', $baja->xmlFirmado());
    }

    public function test_el_motivo_viaja_en_el_cuerpo(): void
    {
        $cpe = $this->cpe(array($this->json(202, array(
            'success' => true, 'estado' => 'en_cola', 'external_id' => 'b', 'xml' => base64_encode('<x/>'),
        ))));

        $cpe->anular('abc-123', 'Error en el monto');

        $cuerpo = json_decode((string) $this->enviadas[0]['request']->getBody(), true);

        $this->assertSame('Error en el monto', $cuerpo['motivo']);
        $this->assertStringContainsString('/anular', (string) $this->enviadas[0]['request']->getUri());
    }

    public function test_pedir_la_misma_baja_dos_veces_lleva_la_misma_clave(): void
    {
        // Es lo que evita que un reintento tras un corte de red genere dos
        // documentos de baja — y cada baja es una firma que se cobra.
        $cpe = $this->cpe(array(
            $this->json(202, array('success' => true, 'external_id' => 'b', 'xml' => base64_encode('<x/>'))),
            $this->json(202, array('success' => true, 'external_id' => 'b', 'xml' => base64_encode('<x/>'))),
        ));

        $cpe->anular('abc-123', 'Error');
        $cpe->anular('abc-123', 'Error');

        $this->assertSame(
            $this->enviadas[0]['request']->getHeaderLine('Idempotency-Key'),
            $this->enviadas[1]['request']->getHeaderLine('Idempotency-Key')
        );
    }

    public function test_una_baja_que_no_procede_interrumpe(): void
    {
        // 409: todavía sin aceptar, ya anulado, o baja en curso. Ignorarlo en
        // silencio dejaría creer que el comprobante quedó dado de baja.
        $cpe = $this->cpe(array($this->json(409, array(
            'success' => false,
            'message' => 'SUNAT todavía no aceptó el comprobante: no hay nada que dar de baja.',
        ))));

        try {
            $cpe->anular('abc-123', 'Error');
            $this->fail('Una baja que no procede no puede pasar en silencio.');
        } catch (XmlPeruException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('todavía no aceptó', $e->getMessage());
        }
    }

    public function test_el_plazo_vencido_dice_que_toca_nota_de_credito(): void
    {
        $cpe = $this->cpe(array($this->json(422, array(
            'success' => false,
            'message' => 'Venció el plazo para dar de baja este comprobante (7 días desde la emisión). Emite una nota de crédito.',
        ))));

        try {
            $cpe->anular('abc-123', 'Error');
            $this->fail('Debió interrumpir.');
        } catch (XmlPeruException $e) {
            $this->assertStringContainsString('nota de crédito', $e->getMessage());
        }
    }
}
