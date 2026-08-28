<?php

namespace Esolutions\XmlPeru\Tests;

use Esolutions\XmlPeru\Webhook;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private $secreto = 'secreto-de-prueba';

    private function firmar($cuerpo)
    {
        return hash_hmac('sha256', $cuerpo, $this->secreto);
    }

    public function test_una_entrega_legitima_se_acepta(): void
    {
        $cuerpo = '{"evento":"cpe.resuelto","external_id":"abc-123"}';

        $this->assertTrue(Webhook::verificar($cuerpo, $this->firmar($cuerpo), $this->secreto));
    }

    public function test_un_cuerpo_alterado_se_rechaza(): void
    {
        // El caso que importa: la URL del webhook es pública. Sin verificar,
        // cualquiera que la descubra puede declarar aceptado un comprobante.
        $firma = $this->firmar('{"evento":"cpe.resuelto","external_id":"abc-123"}');

        $this->assertFalse(
            Webhook::verificar('{"evento":"cpe.resuelto","external_id":"OTRO"}', $firma, $this->secreto)
        );
    }

    public function test_sin_firma_no_pasa(): void
    {
        $this->assertFalse(Webhook::verificar('{}', '', $this->secreto));
        $this->assertFalse(Webhook::verificar('{}', null, $this->secreto));
    }

    public function test_leer_devuelve_el_contenido_solo_si_la_firma_cuadra(): void
    {
        $cuerpo = '{"evento":"cpe.resuelto","external_id":"abc-123"}';

        $datos = Webhook::leer($cuerpo, $this->firmar($cuerpo), $this->secreto);

        $this->assertSame('abc-123', $datos['external_id']);
        $this->assertNull(Webhook::leer($cuerpo, 'firma-inventada', $this->secreto));
    }
}
