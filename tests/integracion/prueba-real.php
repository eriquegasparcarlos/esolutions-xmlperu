<?php
/**
 * El paquete contra la API de verdad, con una empresa en DEMO.
 *
 * Se corre a mano, no en la suite: necesita un token real y crea una empresa.
 *
 *   XMLPERU_TOKEN_CUENTA=... php tests/integracion/prueba-real.php
 *
 * Los tests unitarios prueban la lógica del cliente con respuestas simuladas.
 * Esto prueba lo otro: que las respuestas simuladas se parezcan a las de verdad.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Esolutions\XmlPeru\Cpe;
use Esolutions\XmlPeru\Cuenta;
use Esolutions\XmlPeru\Excepciones\ValidacionException;
use Esolutions\XmlPeru\Excepciones\YaAceptadoException;

$tokenCta = getenv('XMLPERU_TOKEN_CUENTA');

if (! $tokenCta) {
    fwrite(STDERR, "Falta XMLPERU_TOKEN_CUENTA (token de cuenta, ability empresas:manage).
");
    exit(2);
}

$RUC      = getenv('XMLPERU_RUC_PRUEBA') ?: '20000000001';

$ok = 0; $ko = 0;
function paso($nombre, $condicion, $detalle = '') {
    global $ok, $ko;
    if ($condicion) { printf("  OK    %-44s %s\n", $nombre, $detalle); $ok++; }
    else            { printf("  FALLA %-44s %s\n", $nombre, $detalle); $ko++; }
}

$cuenta = new Cuenta($tokenCta);

echo "── Alta de empresa ────────────────────────────────────────────────────\n";

try { $cuenta->eliminarEmpresa($RUC); } catch (Throwable $e) { /* no existía */ }

$empresa = $cuenta->crearEmpresa($RUC, 'EMPRESA PAQUETE SAC', '01', '01');

paso('crearEmpresa devuelve el token de firma', (bool) $empresa->token());
paso('y las credenciales de login', isset($empresa->credenciales()['username']));

$cpe = $empresa->cpe();
paso('empresa->cpe() da un cliente listo', $cpe instanceof Cpe);

echo "\n── Emitir ─────────────────────────────────────────────────────────────\n";

$correlativo = (string) $cpe->siguienteCorrelativo('01', 'F001');
paso('siguienteCorrelativo en serie nueva', $correlativo === '1', "= $correlativo");

$payload = [
    'tipoDoc' => '01', 'serie' => 'F001', 'correlativo' => $correlativo,
    'fechaEmision' => date('Y-m-d'), 'horaEmision' => '10:00:00',
    'tipoMoneda' => 'PEN', 'formaPago' => 'Contado', 'tipoOperacion' => '0101',
    'emisor' => [
        'ruc' => $RUC, 'razonSocial' => 'EMPRESA PAQUETE SAC',
        'establecimiento' => [
            'ubigeo' => '150101', 'codLocal' => '0000', 'departamento' => 'LIMA',
            'provincia' => 'LIMA', 'distrito' => 'LIMA',
            'direccion' => 'AV. PRUEBA 123', 'codigoPais' => 'PE',
        ],
    ],
    'cliente' => ['tipoDoc' => '6', 'numDoc' => '20601234567', 'nombre' => 'CLIENTE SAC'],
    'items' => [[
        'descripcion' => 'Producto', 'cantidad' => 2, 'unidad' => 'NIU',
        'valorUnitario' => 100, 'afectacionIgv' => '10', 'porcentajeIgv' => 18,
    ]],
];

$comprobante = $cpe->emitir($payload);

paso('emitir devuelve external_id', (bool) $comprobante->externalId());
paso('trae el XML firmado ya decodificado', str_contains((string) $comprobante->xmlFirmado(), 'Invoice'));
paso('estado queued', $comprobante->estado() === 'queued', (string) $comprobante->estado());
paso('no está resuelto todavía', ! $comprobante->resuelto());

echo "\n── Consultar y esperar ────────────────────────────────────────────────\n";

$consultado = $cpe->consultar($comprobante->externalId());
paso('consultar encuentra el comprobante', $consultado->externalId() === $comprobante->externalId());
paso('el nombre de archivo cuadra', (bool) $consultado->nombreArchivo(), (string) $consultado->nombreArchivo());

try {
    $resuelto = $comprobante->esperar(45, 3);
    paso('esperar() alcanza el desenlace', $resuelto->resuelto(), $resuelto->estado());
    paso('y lo clasifica', $resuelto->valido() || $resuelto->rechazado(),
        'valido=' . var_export($resuelto->valido(), true));
} catch (Throwable $e) {
    paso('esperar() alcanza el desenlace', false, get_class($e) . ': ' . $e->getMessage());
}

echo "\n── Descargas ──────────────────────────────────────────────────────────\n";

$xml = $cpe->xml($comprobante->externalId());
paso('descargar el XML firmado', str_contains($xml, 'Invoice'), strlen($xml) . ' bytes');

echo "\n── Series ─────────────────────────────────────────────────────────────\n";

$series = $cpe->series('01');
paso('series() lista la serie usada', count($series) > 0, json_encode($series[0] ?? []));
paso('siguienteCorrelativo avanzó', $cpe->siguienteCorrelativo('01', 'F001') === (int) $correlativo + 1);

echo "\n── Errores ────────────────────────────────────────────────────────────\n";

// (a) Reintento idéntico: la clave derivada del payload lo convierte en una
//     repetición inofensiva. No debe emitir un segundo comprobante.
$repetido = $cpe->emitir($payload);
$emitidos = $cpe->series('01')[0]['emitidos'] ?? 0;

paso('reintento idéntico no duplica', (int) $emitidos === 1, "emitidos=$emitidos");
paso('y devuelve el mismo comprobante', $repetido->externalId() === $comprobante->externalId());

// (b) El mismo documento con clave nueva sí llega a la API, y ahí sale el 409:
//     SUNAT ya lo aceptó y no se re-emite.
try {
    $cpe->emitir($payload, 'clave-nueva-' . uniqid());
    paso('duplicado real lanza YaAceptadoException', false, 'no lanzó nada');
} catch (YaAceptadoException $e) {
    paso('duplicado real lanza YaAceptadoException', true, 'external_id=' . $e->externalId());
}

$malo = $payload;
$malo['serie'] = 'B001';           // boleta en un comprobante tipo factura
$malo['correlativo'] = '900';

try {
    $cpe->emitir($malo);
    paso('serie inválida lanza ValidacionException', false, 'no lanzó nada');
} catch (ValidacionException $e) {
    paso('serie inválida lanza ValidacionException', true, ($e->errores()[0] ?? ''));
}

echo "\n── Login estilo QPSE ──────────────────────────────────────────────────\n";

$cred = $cuenta->credenciales($RUC);
$porLogin = Cpe::desdeLogin($cred['username'], $cred['password']);
paso('desdeLogin autentica', (bool) $porLogin->token());
paso('y puede consultar series', is_array($porLogin->series()));
paso('el token permanente sigue vivo tras el login', is_array($cpe->series()));

echo "\n───────────────────────────────────────────────────────────────────────\n";
echo "  $ok correctos · $ko fallos\n";
exit($ko === 0 ? 0 : 1);
