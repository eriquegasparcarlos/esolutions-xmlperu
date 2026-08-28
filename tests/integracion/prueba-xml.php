<?php
/**
 * El camino XML del paquete, contra la API real.
 *
 * Se corre a mano:  XMLPERU_TOKEN_CUENTA=... php tests/integracion/prueba-xml.php
 *
 * Recorre lo que hace de verdad quien migra de otro proveedor: inicia sesión con
 * usuario y contraseña, manda el XML que ya tenía armado, y consulta por nombre
 * de archivo — nunca ve nuestro external_id.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Cuenta;
use Esolutions\XmlPeru\Excepciones\ValidacionException;

$dir      = __DIR__ . '/fixtures';
$tokenCta = getenv('XMLPERU_TOKEN_CUENTA');

if (! $tokenCta) {
    fwrite(STDERR, "Falta XMLPERU_TOKEN_CUENTA (token de cuenta, ability empresas:manage).
");
    exit(2);
}
$RUC      = '20000000001';

$ok = 0; $ko = 0;
function paso($nombre, $cond, $detalle = '') {
    global $ok, $ko;
    if ($cond) { printf("  OK    %-44s %s\n", $nombre, $detalle); $ok++; }
    else       { printf("  FALLA %-44s %s\n", $nombre, $detalle); $ko++; }
}

$cuenta = new Cuenta($tokenCta);
try { $cuenta->eliminarEmpresa($RUC); } catch (Throwable $e) {}
$empresa = $cuenta->crearEmpresa($RUC, 'EMPRESA XML SAC', '01', '01');

echo "── Migración: login usuario/clave ─────────────────────────────────────\n";

$cred = $empresa->credenciales();
$cpe  = Cpe::desdeLogin($cred['usuario'], $cred['password']);
paso('desdeLogin (estilo QPSE)', (bool) $cpe->token());

echo "\n── Firmar sin enviar ──────────────────────────────────────────────────\n";

$xml = file_get_contents($dir . '/xml-ok.xml');
paso('el XML de prueba se lee', strpos($xml, '<Invoice') !== false, strlen($xml) . ' bytes');

$c = $cpe->firmarXml("$RUC-01-F001-20", $xml);

paso('firmarXml devuelve el firmado', strpos((string) $c->xmlFirmado(), 'Signature') !== false,
    strlen((string) $c->xmlFirmado()) . ' bytes');
paso('estado traducido a «firmado»', $c->estado() === 'firmado', (string) $c->estado());
paso('trae external_id', (bool) $c->externalId());
paso('conserva el nombre de archivo', $c->nombreArchivo() === "$RUC-01-F001-20");

echo "\n── Firmar y enviar (el reemplazo directo) ─────────────────────────────\n";

$p = $cpe->procesarXml("$RUC-01-F001-22", file_get_contents($dir . "/xml-proc.xml"));
paso('procesarXml encola', $p->estado() === 'en_cola', (string) $p->estado());
paso('trae external_id', (bool) $p->externalId());

echo "\n── Consultar por nombre de archivo ────────────────────────────────────\n";

sleep(6);
$e = $cpe->consultarPorNombre("$RUC-01-F001-22");

paso('consulta por filename', $e->externalId() === $p->externalId());
paso('el estado no es un codigo HTTP', $e->estado() !== 200 && $e->estado() !== '200', (string) $e->estado());
paso('clasifica el desenlace',
    $e->resuelto() ? ($e->valido() || $e->rechazado()) : true,
    'resuelto=' . var_export($e->resuelto(), true) . ' valido=' . var_export($e->valido(), true));

if ($e->resuelto() && $e->valido()) {
    paso('el CDR viene en la misma consulta', strlen((string) $e->cdr()) > 0, strlen((string) $e->cdr()) . ' bytes');
}

echo "\n── Un XML que no debe firmarse ────────────────────────────────────────\n";

$malo = file_get_contents($dir . '/xml-mala.xml');
try {
    $cpe->firmarXml("$RUC-01-B001-21", $malo);
    paso('serie invalida se rechaza', false, 'no lanzo nada');
} catch (ValidacionException $ex) {
    paso('serie invalida se rechaza', true, substr($ex->getMessage(), 0, 70));
} catch (Throwable $ex) {
    paso('serie invalida se rechaza', true, get_class($ex) . ': ' . substr($ex->getMessage(), 0, 60));
}

echo "\n── Limpieza ───────────────────────────────────────────────────────────\n";
try { $cuenta->eliminarEmpresa($RUC); paso('empresa de prueba eliminada', true); }
catch (Throwable $ex) { paso('empresa de prueba eliminada', false, $ex->getMessage()); }

echo "\n───────────────────────────────────────────────────────────────────────\n";
echo "  $ok correctos · $ko fallos\n";
exit($ko === 0 ? 0 : 1);
