# esolutions/xmlperu

Cliente PHP de la API de [xmlperu.dev](https://xmlperu.dev): firma y emisión de
comprobantes electrónicos (CPE) a SUNAT/OSE, y administración de empresas
emisoras.

- PHP **7.2+**, Laravel **5.7 → 13**, o **standalone** (sin framework).
- Usa **Guzzle** directamente (`^6 || ^7 || ^8`), no el HTTP client de Illuminate,
  que exige Laravel 7+.
- **URL fija** dentro del paquete (`https://api.xmlperu.dev`): no es configurable
  ni inyectable. Lo único configurable es el token.

> No confundir con **`esolutions/xml`**, que es el motor de firma que corre en el
> servidor. Este paquete solo **consume** la API; no firma nada por su cuenta ni
> necesita el certificado.

## Instalación

```bash
composer require esolutions/xmlperu
```

En Laravel el ServiceProvider se autodescubre:

```dotenv
XMLPERU_TOKEN=token_de_la_empresa
```

## Los cinco minutos que importan

```php
use Esolutions\XmlPeru\Cpe;

$cpe = new Cpe('token_de_la_empresa');

$comprobante = $cpe->emitir([
    'tipoDoc'      => '01',
    'serie'        => 'F001',
    'correlativo'  => '1',
    'fechaEmision' => '2026-08-28',
    'tipoMoneda'   => 'PEN',
    'emisor'       => ['ruc' => '20000000001', 'razonSocial' => 'MI EMPRESA SAC', /* … */],
    'cliente'      => ['tipoDoc' => '6', 'numDoc' => '20601234567', 'nombre' => 'CLIENTE SAC'],
    'items'        => [/* … */],
]);

$comprobante->externalId();    // con qué consultarlo después
$comprobante->xmlFirmado();    // XML firmado, ya en la mano
```

**Emitir no espera al CDR.** La respuesta llega en cuanto el comprobante está
firmado y el envío a SUNAT queda encolado. Eso es deliberado: lo que hace válido
al comprobante es la firma, así que ya se puede imprimir y entregar. Una SUNAT
lenta no tiene por qué convertirse en una cola de clientes tuya.

El desenlace llega de dos maneras:

```php
// 1) El webhook cpe.resuelto (lo normal en un punto de venta)
// 2) Consultando
$estado = $cpe->consultar($comprobante->externalId());

if ($estado->valido()) { /* aceptado, con o sin observaciones */ }
```

Y si de verdad necesitas el desenlace antes de seguir —una conciliación, un
proceso por lotes— hay una espera con plazo:

```php
$resuelto = $cpe->emitirYEsperar($payload, 30);   // 30 s de plazo
```

## Aceptado, observado, rechazado

Los tres estados no significan lo mismo y confundirlos sale caro:

| Método | Qué pasó | Qué hacer |
|---|---|---|
| `aceptado()` | Aceptado limpio | Nada |
| `observado()` | **Aceptado** con observaciones | Nada urgente: el comprobante es válido y está declarado |
| `rechazado()` | Rechazado: no existe para SUNAT | Corregir y emitir de nuevo |
| `valido()` | Aceptado u observado | La pregunta que casi siempre quieres hacer |

Tratar un observado como fallo lleva a re-emitir algo que SUNAT ya aceptó, y el
segundo intento se lleva un `409`.

## Errores: cada uno pide una reacción distinta

A diferencia de `esolutions/apiperudev` —que devuelve arrays y nunca lanza—, aquí
un fallo **interrumpe**. En una consulta de RUC, ignorar el error deja una
pantalla vacía; en una emisión deja al cliente creyendo que facturó.

```php
use Esolutions\XmlPeru\Excepciones\{ValidacionException, YaAceptadoException, ConexionException};

try {
    $cpe->emitir($payload);
} catch (ValidacionException $e) {
    // 422 · NO se emitió y el correlativo no se consumió
    $e->errores();          // qué corregir
} catch (YaAceptadoException $e) {
    // 409 · SUNAT ya lo aceptó antes. Casi siempre lo que quieres es:
    $cpe->consultar($e->externalId());
} catch (ConexionException $e) {
    // Red caída: NO SE SABE si se emitió. Reintenta con la misma clave
    // de idempotencia — nunca emitas de nuevo a ciegas.
}
```

## Idempotencia

`emitir()` manda una `Idempotency-Key` derivada del propio comprobante (emisor,
tipo, serie, número), no del azar. Es lo que hace que reintentar un envío que se
perdió en la red no duplique la emisión: una clave aleatoria por intento no
serviría de nada.

El **contenido** entra en la clave, no solo el serie-correlativo. Así reintentar
el mismo payload devuelve el comprobante original sin emitir otro, pero un
payload corregido sí pasa — volver a firmar un comprobante que SUNAT aún no
aceptó es algo permitido y a veces necesario. Puedes pasar tu propia clave como
segundo argumento, o `''` para no mandar ninguna.

## Numeración

```php
$cpe->siguienteCorrelativo('01', 'F001');   // 43
```

Es lo que evita el choque de numeración cuando un punto de venta se reinstala o
se abre una segunda caja: sin esto arrancan en 1 y cada intento se lleva un 409.

## Los dos estilos de autenticación

**Token de empresa** (recomendado): no caduca, admite tantos procesos como
quieras. Lo devuelve dar de alta la empresa o `POST /v1/empresas/{ruc}/token`.

```php
$cpe = new Cpe('token_permanente');
```

**Login estilo QPSE**: para quien viene de otro proveedor y ya tiene ese flujo
montado.

```php
$cpe = Cpe::desdeLogin('usuario', 'clave');
```

El token del login **caduca en una hora**; el cliente lo renueva solo al toparse
con un 401, así que un proceso largo no se cae a los sesenta minutos. Un aviso
si vas a escalar: **cada login reemplaza la sesión anterior** de esa empresa, así
que dos procesos que hagan login se van echando el uno al otro. Para eso está el
token permanente.

## Administrar empresas

Solo si das de alta emisores desde tu sistema. Usa el token de **cuenta**
(`empresas:manage`), que es otro distinto — el token que llevas a un punto de
venta debe poder emitir, pero no crear empresas ni leer sus credenciales.

```php
use Esolutions\XmlPeru\Cuenta;

$cuenta  = new Cuenta('token_de_cuenta');
$empresa = $cuenta->crearEmpresa('20000000001', 'MI EMPRESA SAC', '01', '02');

$empresa->token();   // guárdalo: solo se muestra aquí, una vez
$cpe = $empresa->cpe();   // cliente ya autenticado, sin copiar nada a mano
```

Otras operaciones: `empresas()`, `empresa($ruc)`, `nuevoToken($ruc)`,
`credenciales($ruc)`, `plan()`, `entorno()`, `envio()`, `webhook()`,
`certificado()`, `subirCertificado()`, `quitarCertificado()`,
`credencialesGre()`, `eliminarEmpresa()`.

## Webhook

Verificar la firma **no es opcional**: la URL del webhook es pública, y sin
verificarla cualquiera que la descubra puede decirle a tu sistema que un
comprobante fue aceptado.

```php
use Esolutions\XmlPeru\Webhook;

$datos = Webhook::leer($request->getContent(), $request->header('X-Firma'), $secreto);

if ($datos === null) {
    abort(401);   // no viene de xmlperu
}
```

El cuerpo tiene que ser el **crudo**, sin decodificar ni re-serializar:
cualquier reformateo cambia el HMAC.

## Envío manual

Si la empresa lleva el envío por su cuenta (`envio($ruc, 'manual')`), firmar deja
el comprobante en «Por enviar» y se manda cuando tú digas:

```php
$cpe->enviar($comprobante->externalId());
```

## Métodos del cliente de firma

| Método | HTTP |
|---|---|
| `emitir($payload, $idempotencyKey = null)` | `POST /v1/cpe` |
| `emitirYEsperar($payload, $timeout, $intervalo)` | ↑ + consultas |
| `consultar($externalId)` | `GET /v1/cpe/{id}` |
| `esperar($externalId, $timeout, $intervalo)` | consultas hasta el desenlace |
| `series($tipoDoc = null)` · `siguienteCorrelativo($tipoDoc, $serie)` | `GET /v1/cpe/series` |
| `xml($externalId)` · `cdr($externalId)` | `GET /v1/cpe/{id}/xml` · `/cdr` |
| `enviar($externalId)` · `reenviar($externalId)` | `POST /v1/cpe/{id}/enviar` |

## Tests

```bash
composer install && vendor/bin/phpunit
```

Las respuestas de la API se simulan con el `MockHandler` de Guzzle, y el reloj de
la espera se inyecta: la suite corre en milisegundos y no toca la red.
