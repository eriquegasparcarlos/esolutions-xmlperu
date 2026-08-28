# Changelog

## v1.3.0

### Añadido

- **`cdrXml()`**, el XML del CDR sin envoltorio. `cdr()` devuelve el ZIP que
  entrega SUNAT —`dummy/` + `R-{nombre}.xml`, que es lo que archiva un sistema
  contable— y este da el contenido para leerlo, sin abrir el ZIP.

### Corregido

- **`Comprobante::cdr()` podía devolver dos cosas distintas.** La consulta del
  camino XML trae el CDR incrustado, y ese incrustado es el CONTENIDO, no el
  envoltorio: según de dónde viniera el comprobante, el mismo método devolvía un
  ZIP o un XML. Ahora `cdr()` es siempre el ZIP y lo incrustado alimenta a
  `cdrXml()`.

  Lo introduje yo en la v1.1.0 al aprovechar el CDR incrustado para ahorrar una
  llamada. La optimización estaba bien; mezclar los dos formatos bajo un mismo
  nombre, no.

## v1.2.0

Todo esto sale de la revisión de un integrador que usó el paquete de verdad.

### Añadido

- **Accesores para lo que solo salía por `dato()`**: `ticket()`, `tieneCdr()`,
  `tieneFirma()`, `llegoASunat()`, `codigo()`, `mensaje()`, `errores()`,
  `observaciones()`, `tipoDoc()`, `serie()`, `numero()`, `fechaEmision()`.

  `dato('has_cdr')` obligaba a conocer los nombres internos de la respuesta: si
  cambiaba una clave, la integración se rompía en silencio y sin que fuera un
  cambio declarado. Ahora esos nombres son detalle interno y los accesores son el
  contrato.

- **`pendiente()`**, para el estado que faltaba. `valido() === false` NO significa
  que algo haya fallado: mientras SUNAT no conteste devuelve false igual que un
  rechazo. Confundirlos lleva a re-emitir un comprobante que estaba en camino — le
  costó una guía a quien revisó el paquete.

- **Constantes del catálogo de estados** (`ESTADO_RECIBIDO`, `ESTADO_ACEPTADO`…),
  que antes solo se deducían leyendo `const RESUELTOS`.

### Documentado

- La tabla de estados ahora incluye `pendiente()` y `resuelto()`, con el aviso
  explícito sobre `valido()` y los tres códigos intermedios (01, 02, 03).
- Qué trae la consulta: una fila por campo, con su accesor.
- Qué trae `resultado()`: `"0"` en aceptación —cadena, no entero— y el código de
  SUNAT en rechazo, con `errors[]` aparte. Y que `llegoASunat()` puede ser `null`,
  que significa «no se sabe» y no «no llegó»: es lo que decide si reintentar es
  seguro.
- **Guías de remisión y resúmenes**: que el ticket es de SUNAT, que solo existe
  para ellos, que de consultarlo nos encargamos nosotros, y que tardan más.
- **Cuándo hace falta `enviar()`** frente a `emitir()`: normalmente nunca. Estaba
  en la tabla de métodos sin una línea que lo explicara.

## v1.1.0

### Añadido

- **Camino XML**, para quien migra de otro proveedor: `procesarXml()`,
  `firmarXml()` y `consultarPorNombre()`, que se usan junto a
  `Cpe::desdeLogin()`.

  Sin ellos el login quedaba huérfano: autenticaba igual que en el proveedor
  anterior… y después lo único que se podía hacer era emitir en JSON, que es
  justo lo que un migrante no tiene — su generador de XML ya existe y no lo va a
  rehacer. Se cubría la mitad del camino de migración, y no la que faltaba.

  Se consulta por `RUC-TIPO-SERIE-CORRELATIVO`, porque un sistema que viene de
  otro proveedor no conoce nuestro `external_id`.

- El CDR que llega dentro de la consulta del camino XML lo devuelve `cdr()` sin
  volver a la red.

### Corregido

- **El paquete exigía PHP `^8.0` sin necesitarlo.** El código no usa nada
  posterior a 7.2, pero la restricción dejaba fuera a quien más lo necesita: los
  ERP peruanos en Laravel viejo, que son exactamente los que hoy emiten con otro
  proveedor. Queda en `^7.2 || ^8.0` con Guzzle `^6 || ^7 || ^8`, la misma matriz
  que `esolutions/apiperudev`.

  Verificado ejecutándolo, no solo pasándole el linter: instalación limpia
  `--no-dev` y una emisión + espera + rechazo 422 corriendo en **7.2, 7.4, 8.1 y
  8.4**.

- El ejemplo del README usaba argumentos con nombre (`timeout: 30`), que son de
  PHP 8 — justo el ejemplo que un integrador en 7.4 iba a copiar.

### Sobre la v1.0.0

`v1.0.0` quedó publicada declarando `php ^8.0` y sin el camino XML. Las versiones
estables de Packagist son inmutables —una vez publicadas, su contenido no puede
cambiar—, así que se deja donde está y la corrección sale aquí. Quien pida
`^1.0` recibe esta.

## v1.0.0

Primera versión. Cliente PHP de la API de xmlperu.dev.

- `Cpe` — emitir, consultar, esperar el desenlace, correlativos por serie,
  descargar XML y CDR, enviar y reenviar.
- `Cuenta` — alta y administración de empresas emisoras (token de cuenta).
- `Comprobante` — el objeto que devuelve emitir, con el bucle de espera dentro.
- `Webhook` — verificación HMAC-SHA256 de las entregas de `cpe.resuelto`.
- Excepciones por situación: `ValidacionException` (422, no se emitió),
  `YaAceptadoException` (409, ya existe), `ConexionException` (no se sabe),
  `TiempoAgotadoException`, `NoAutorizadoException`, `NoEncontradoException`.

Tres decisiones que conviene conocer:

- **Los fallos lanzan excepción**, al revés que `esolutions/apiperudev`. En una
  consulta, ignorar el error deja una pantalla vacía; en una emisión deja al
  cliente creyendo que facturó.
- **La clave de idempotencia se deriva del comprobante**, no del azar: es lo que
  hace que reintentar un envío perdido en la red no duplique la emisión.
- **El token del login se renueva solo** al toparse con un 401. Caduca en una
  hora, y sin esto un proceso largo se cae en mitad del lote.
