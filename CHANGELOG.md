# Changelog

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
