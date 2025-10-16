# Resumen de Configuración y Certificados

## 1. Preparar SimpleSAMLphp

### 1.1. Obtener los recursos del Kit Cl@ve (opcional)
- Descarga la última versión del Kit de PHP desde [aquí](https://administracionelectronica.gob.es/ctt/verPestanaDescargas.htm?idIniciativa=clave)
- Última versión testeada del Kit: 2.8.0 (Test realizado el 17 Marzo 2025)

### 1.2 Copia la configuración dentro de `vendor/simplesamlphp/simplesamlphp`
- 1. Descomprime el zip descargado
- 2. Copia el contenido de la carpeta `paquete-integracion-clave/simplesamlphp` dentro de `vendor/simplesamlphp/simplesamlphp`
- 3. Debe quedar una estructura como:
  - /var/www/html/
    - vendor/simplesamlphp/simplesamlphp
    - web

## 2. Apache
### Llevar la configuración al Apache, si usas DDEV:
- Por defecto ddev usa nginx, usa apache: `ddev config --webserver-type=apache-fpm`
- LLeva el fichero `demo/apache-site.conf` -> `/.ddev/apache/apache-site.conf` con las modificaciones oportunas.
- Revisa que hayas eliminado el litearl `#ddev-generated` es lo que permite no sobrescribir el archivo.

## 3. Certificados

### Solicitud acceso a Cl@ve
- El cliente debe pedir el certificado desde:  
  `https://sede.agenciatributaria.gob.es/Sede/clave.html`
- Necesitarán:
  - **CIF**
  - **DIR3**

### Certificados
- Como respuesta a la petición anterior, recibirán un archivo comprimido que contiene un archivo `.pfx`.

### Procesado del Certificado
- Descarga y utiliza **[KeyStore Explorer](https://keystore-explorer.org/downloads.html)**:
- Abre el `.pfx` y realiza lo siguiente:
  - **Exportar Certificate Chain:**
    - Botón derecho > Export > Certificate Chain > Export  
    - Esto genera el archivo `.cer`
  - **Exportar Private Key:**
    - Botón derecho > Export > Private Key > Export > PKCS #8  
    - En Encryption Password, usa la pwd `changeit` (rellena ambos campos)  
    - Esto genera el archivo `.p8.pem`

### Creación del archivo .p12
- Concatena el contenido del archivo `.cer` dentro del `.pem` y guarda el resultado en un nuevo fichero con extensión `.p12`.

### Integración en SimpleSAMLphp
- Copia los archivos `.p12` y `.cer` en la carpeta `vendor/simplesamlphp/simplesamlphp/cert`.

## 3. Modificación del KIT
### Configuración inicial

Importante, estos comandos son para un arranque inicial, posteriormente se deberá configurar con los datos finales.

#### Configuración
`rsync -av --delete /home/valerogarte/proyectos/kitclave/kit/simplesamlphp/metadata/   /home/valerogarte/proyectos/drupal10clave/vendor/simplesamlphp/simplesamlphp/metadata/`
`rsync -av --delete /home/valerogarte/proyectos/kitclave/kit/simplesamlphp/cert/   /home/valerogarte/proyectos/drupal10clave/vendor/simplesamlphp/simplesamlphp/cert/`
`rsync -av --delete /home/valerogarte/proyectos/kitclave/kit/simplesamlphp/config/   /home/valerogarte/proyectos/drupal10clave/vendor/simplesamlphp/simplesamlphp/config/`
#### Módulos
`rsync -av --delete /home/valerogarte/proyectos/kitclave/kit/simplesamlphp/modules/   /home/valerogarte/proyectos/drupal10clave/vendor/simplesamlphp/simplesamlphp/modules/`

### Test
Revisa que todo funcione correctamente con datos demo.

### Configuración final
- Edita el archivo `vendor/simplesamlphp/simplesamlphp/config/authsources.php`:
  - Sustituye el certificado antiguo por el nuevo.
  - Añade el valor **CIF_DIR3** al array.
  - Configura:
    - `validate.certificate`
    - `privatekey`
    - `privatekey_password`
  - Sustituye `http://localhost` por `https://sepa.ddev.site/`
  - El parámetro logout debe apuntar al frontal de tu proyecto
    'logout.url' => 'https://sepa.ddev.site',

### Cambio de variables
- Edita el archivo `vendor/simplesamlphp/simplesamlphp/config/config.php`
- Revisa y adapta los siguientes valores:
    1. `ASSERTION_URL` -> Url base de la web
    2. `DEFAULT_SPID` -> Identificador del SP configurado para tu organismo

La integración no requiere copiar scripts personalizados ni modificar las clases internas del módulo `saml`. El módulo de Drupal carga SimpleSAMLphp directamente desde `../vendor/simplesamlphp/simplesamlphp` y utiliza el módulo `clave` incluido para gestionar el flujo SAML.
