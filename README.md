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

## Estados: lo primero es saber si SUNAT ya contestó

| Método | Qué pasó | Qué hacer |
|---|---|---|
| `pendiente()` | **SUNAT aún no se ha pronunciado** | Esperar. No es un fallo |
| `aceptado()` | Aceptado limpio | Nada |
| `observado()` | **Aceptado** con observaciones | Nada urgente: es válido y está declarado |
| `rechazado()` | Rechazado: no existe para SUNAT | Corregir y emitir de nuevo |
| `valido()` | Aceptado u observado | La pregunta que casi siempre quieres hacer |
| `resuelto()` | Ya hay desenlace, sea el que sea | Decidir |

### ⚠️ `valido() === false` no significa que algo haya fallado

Mientras SUNAT no conteste, `valido()` devuelve `false` **igual que en un
rechazo**. Confundir las dos cosas lleva a re-emitir un comprobante que estaba en
camino, y el segundo intento se lleva un `409`.

```php
if (! $c->valido())   { reemitir(); }   // MAL — también entra lo que sigue en curso
if ($c->rechazado())  { corregir(); }   // BIEN
if ($c->pendiente())  { esperar(); }    // BIEN
```

Lo mismo con `observado()`: está **aceptado**. Tratarlo como fallo re-emite algo
que SUNAT ya declaró.

### Catálogo de estados

`codigoEstado()` devuelve el código; hay constantes para no escribirlos a mano
(`Comprobante::ESTADO_RECIBIDO`).

| Código | Estado | Qué significa |
|---|---|---|
| `01` | Registrado | Firmado. Aún no salió — o se quedó a medias |
| `02` | Por enviar | Firmado, esperando a que **tú** lo mandes (envío manual) |
| `03` | Recibido | Enviado; SUNAT no contesta todavía, o lo está procesando |
| `04` | Por resumir | Boleta firmada, esperando el resumen del día |
| `05` | Aceptado | Declarado, sin observaciones |
| `07` | Observado | **Aceptado** con observaciones. Es válido |
| `09` | Rechazado | No existe para SUNAT |

Los tres primeros son etapas del camino; los tres últimos, desenlaces. Solo esos
tres hacen `resuelto()` verdadero.

## Qué trae la consulta

`consultar()` devuelve un `Comprobante` con un accesor por campo. La respuesta
cruda está en `datos()`, pero los accesores son el contrato: si mañana cambia un
nombre de clave, ellos siguen valiendo.

| Accesor | Clave | Qué es |
|---|---|---|
| `externalId()` | `external_id` | Nuestro identificador |
| `nombreArchivo()` | `filename` | `RUC-TIPO-SERIE-CORRELATIVO` |
| `tipoDoc()` | `document_type_id` | `01` factura, `03` boleta, `07` NC, `08` ND, `09` guía… |
| `serie()` · `numero()` | `series` · `number` | |
| `fechaEmision()` | `date_of_issue` | `Y-m-d` |
| `hash()` | `hash` | Resumen de la firma |
| `codigoEstado()` | `state_type_id` | Ver el catálogo de arriba |
| `estado()` | `state` | El estado en palabras |
| `resuelto()` · `pendiente()` | `resuelto` | Si SUNAT ya contestó |
| `tieneFirma()` | `has_signed` | Si el XML firmado se puede descargar |
| `tieneCdr()` | `has_cdr` | Si el CDR ya está |
| `ticket()` | `ticket` | Solo en guías y resúmenes |
| `resultado()` | `resultado` | Lo que dijo SUNAT — abajo |

## Qué dijo SUNAT: `resultado()`

`null` mientras no haya habido intento de envío. Cuando lo hay:

| Accesor | Aceptado | Rechazado |
|---|---|---|
| `codigo()` | `"0"` | El código de SUNAT: `2335`, `3277`… |
| `mensaje()` | «La Factura numero F001-42, ha sido aceptada» | El motivo |
| `errores()` | vacío | Los motivos, uno por línea |
| `observaciones()` | las del estado «Observado» | vacío |
| `llegoASunat()` | `true` | `true`/`false`/`null` |

**`codigo()` devuelve una cadena, no un entero:** el `"0"` de aceptación se
compara como cadena.

**`llegoASunat()` puede ser `null`, y ese `null` importa.** Significa «no se
sabe», no «no llegó»: es lo que decide si reintentar es seguro. Si llegó, hay que
consultar antes de reenviar —el correlativo puede estar consumido—; tratarlo como
`false` haría reenviar algo que quizá ya está declarado.

**`observaciones()` no es `advertencias()`.** Las primeras son de SUNAT, sobre un
comprobante que **sí** aceptó. Las segundas las detectamos nosotros al emitir,
antes de que SUNAT lo viera.

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
`boletas()`, `certificado()`, `subirCertificado()`, `quitarCertificado()`,
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

## Guías de remisión y resúmenes

Se emiten igual que cualquier otro comprobante —`emitir()` o `procesarXml()`—
pero SUNAT los procesa **por ticket**: el envío devuelve un identificador y la
respuesta se recoge después.

De preguntarle a SUNAT por ese ticket **nos encargamos nosotros**. Tú consultas
el comprobante como siempre.

```php
$guia = $cpe->emitir($payload);          // tipoDoc 09 (remitente) o 31 (transportista)

$estado = $cpe->consultar($guia->externalId());
$estado->ticket();       // el de SUNAT — informativo, para cotejar ante una incidencia
```

`ticket()` es `null` en facturas y boletas: solo existe para guías (`09`, `31`) y
resúmenes (`RC`, `RA`, `RR`).

Cuenta con que tarden más. Una factura suele resolverse en segundos; una guía o
un resumen pueden estar en `pendiente()` varios minutos, y eso es normal.

Las guías necesitan además un acceso OAuth2 propio de SUNAT, distinto del resto.
En pruebas no hay que configurar nada. Ver [la guía de guías de
remisión](https://docs.xmlperu.dev/guias/guias-de-remision/).

## Descargar el CDR

```php
$zip = $cpe->cdr($externalId);        // como lo entrega SUNAT — esto es lo que se archiva
$xml = $cpe->cdrXml($externalId);     // solo el contenido, para leerlo
```

El ZIP trae `dummy/` y `R-{nombre}.xml`. Ese es el formato que esperan los
sistemas contables, así que **archiva el ZIP**; usa el XML cuando solo quieras
leer el código de respuesta o las observaciones. El contenido es el mismo byte a
byte.

Un matiz si vas a comparar checksums: el ZIP se **rearma** en la descarga, no es
el archivo original de SUNAT. Es estructuralmente idéntico, pero las marcas de
tiempo del contenedor no coinciden. El XML de dentro sí es el original.

Los dos sirven igual para facturas, boletas, notas, **resúmenes y guías**.

## `emitir()` y `enviar()`: cuándo hace falta cada uno

**Normalmente `enviar()` no se usa.** `emitir()` firma y encola el envío él solo.

Hace falta en dos casos:

1. **La empresa lleva el envío por su cuenta** (`Cuenta::envio($ruc, 'manual')`).
   Entonces `emitir()` firma y para: el comprobante queda en «Por enviar»
   (`02`) hasta que tú lo mandes.
2. **Un envío que se quedó sin salir** — agotó sus reintentos, o nunca llegó a
   encolarse. Se reconoce por seguir en `01` o `02` mucho después de emitido.

```php
$cpe->enviar($comprobante->externalId());
```

Es idempotente: llamarlo repetido no envía el comprobante dos veces. Y sobre uno
que SUNAT ya aceptó responde `409` en vez de fingir que lo encoló.

## Si vienes de otro proveedor: manda tu XML

Si ya tienes el comprobante armado, no hace falta que rehagas tu generador para
pasarte al payload JSON. El camino XML acepta lo que ya produces.

```php
use Esolutions\XmlPeru\Cpe;

// Login con usuario y clave, como en tu proveedor anterior
$cpe = Cpe::desdeLogin('usuario', 'clave');

// Firma y encola el envio: el reemplazo directo
$c = $cpe->procesarXml('20000000001-01-F001-123', $miXml);

// Consultas por nombre de archivo — no necesitas conocer nuestro external_id
$estado = $cpe->consultarPorNombre('20000000001-01-F001-123');

if ($estado->valido()) {
    $cdr = $estado->cdr();   // ya viene en la consulta, no se vuelve a descargar
}
```

| Metodo | Que hace |
|---|---|
| `procesarXml($nombreArchivo, $xml)` | Firma y encola el envio |
| `firmarXml($nombreArchivo, $xml)` | Solo firma; el envio corre por tu cuenta |
| `consultarPorNombre($nombreArchivo)` | Estado por `RUC-TIPO-SERIE-CORRELATIVO` |

El `$xml` va en texto plano: el paquete lo codifica en base64 por ti.

**La unica diferencia con tu proveedor anterior** es que la respuesta no trae el
CDR, porque el envio no ocurre dentro de la peticion. Lo que hace valido al
comprobante es la firma, y esa la tienes al instante; el desenlace llega por el
webhook o consultando.

## Métodos del cliente de firma

| Método | HTTP |
|---|---|
| `emitir($payload, $idempotencyKey = null)` | `POST /v1/cpe` |
| `emitirYEsperar($payload, $timeout, $intervalo)` | ↑ + consultas |
| `consultar($externalId)` | `GET /v1/cpe/{id}` |
| `esperar($externalId, $timeout, $intervalo)` | consultas hasta el desenlace |
| `series($tipoDoc = null)` · `siguienteCorrelativo($tipoDoc, $serie)` | `GET /v1/cpe/series` |
| `xml($externalId)` | `GET /v1/cpe/{id}/xml` |
| `cdr($externalId)` · `cdrXml($externalId)` | `GET /v1/cpe/{id}/cdr` — ZIP de SUNAT · XML extraído |
| `enviar($externalId)` · `reenviar($externalId)` | `POST /v1/cpe/{id}/enviar` — solo en envío manual o si se quedó sin salir |
| `firmarXml($nombre, $xml)` | `POST /api/cpe/generar` |
| `procesarXml($nombre, $xml)` | `POST /api/cpe/procesar` |
| `consultarPorNombre($nombre)` | `GET /api/cpe/consultar/{filename}` |

## Tests

```bash
composer install && vendor/bin/phpunit
```

Las respuestas de la API se simulan con el `MockHandler` de Guzzle, y el reloj de
la espera se inyecta: la suite corre en milisegundos y no toca la red.
