# Change Log
Cambios sobre kit de integración de CLAVE2 en tecnología PHP

## [3.0.1]
- En la pantalla de inicio se han hecho los siguientes cambios:
   - Se elimina el checkbox 'PIN 24H' para deshabilitar el IdP 'PIN 24H', ya que este IdP se da de baja, el cual es reemplazado por el nuevo IdP 'Cl@ve Móvil'. NOTA: Con este cambio, ya no habrá posibilidad de enviar el atributo 'http://es.minhafp.clave/AEATIdP' a través de la petición SAML para desactivar dicho IdP.
   - Se cambia el nombre del checkbox 'IDP Móvil', que pasa a llamarse 'Cl@ve Móvil'. Recordemos que el IdP Móvil es una especie de IdP de nivel superior que puede ser configurado con varios IdPs finales, a los cuales se les da una prioridad de conexión. Actualmente parece que se pretende que el IdP Móvil solo tenga configurado el nuevo IdP 'Cl@ve Móvil'.

## [3.0.0]
Versión inicial del kit de CLAVE2
- Se ha actualizado SimpleSAML a la versión 2.3.6.
- Se ha reescrito el kit para el funcionamiento con SimpleSAML 2.3.6.
- Se ha creado un módulo llamado 'clave' para adaptar SimpleSAML a las necesidades de Clave a la hora de hacer logout.
- Se ha eliminado el módulo de SimpleSAML 'statistics', ya que hacía uso de jQuery 1.12.4 y presentaba identificada como CVE-2015-9251. El módulo no estaba habilitado y no se hacía uso de él.

