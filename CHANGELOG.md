# Changelog

## v1.0.0

Primera versión. Cliente PHP de la API de xmlperu.dev.

- `Cpe` — emitir, consultar, esperar el desenlace, correlativos por serie,
  descargar XML y CDR, enviar y reenviar.
- `Cuenta` — alta y administración de empresas emisoras (token de cuenta).
- **Camino XML** para quien migra de otro proveedor: `procesarXml()`,
  `firmarXml()` y `consultarPorNombre()`, que se usan con `Cpe::desdeLogin()`.
  Sin ellos el login quedaba huerfano: autenticaba a alguien que solo podia
  emitir en JSON, que es justo lo que un migrante no tiene.
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
- **Universal**: PHP 7.2+ y Laravel 5.7 → 13, igual que `esolutions/apiperudev`.
  Verificado ejecutandolo —no solo compilandolo— en 7.2, 7.4, 8.1 y 8.4.
- **El token del login se renueva solo** al toparse con un 401. Caduca en una
  hora, y sin esto un proceso largo se cae en mitad del lote.
