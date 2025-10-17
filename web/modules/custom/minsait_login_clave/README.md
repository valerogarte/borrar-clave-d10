# Resumen de Configuración y Certificados

## 1. Montar KIT de Cl@ve

### 2.1. Descarga
- La versión del Kit de PHP siempre puedes descargarla desde [aquí](https://administracionelectronica.gob.es/ctt/verPestanaDescargas.htm?idIniciativa=clave)
- Última versión testeada del Kit: 3.0.1 (Test realizado el 16 Octubre 2025)

### 2.2 Llévalo a ./modules\custom\minsait_login_clave
- 1. Descomprime el zip descargado
- 2. Mueve la carpeta `paquete-integracion-clave` a una carpeta antes de DRUPAL_ROOT y renómbrala a `clave`
- 3. Debe quedar una estructura como:
  - /var/www/html/
    - clave
    - web

## 2. Apache

### Proceso simplificado con DDEV:
Lanza los siguientes comandos:
`cp demo/3.0.1/apache-site.conf /var/www/html/.ddev/apache/apache-site.conf`
`cp demo/3.0.1/config.yaml /var/www/html/.ddev/config.yaml`

### Proceso completo
#### Llevar la configuración al Apache, si usas DDEV:
- Por defecto ddev usa nginx, ahora debes usar apache: `ddev config --webserver-type=apache-fpm`
- LLeva el fichero `demo/apache-site.conf` -> `/.ddev/apache/apache-site.conf` con las modificaciones oportunas.
- Revisa que hayas eliminado el literal `#ddev-generated`, ya que este permite que el archivo no se sobrescriba.

#### Instala sqlite3 en tu servidor
- Añade este hook al final de tu archivo `.ddev/config.yaml`:
  ```yaml
  hooks:
    post-start:
      - exec: |
          apt-get update && apt-get install -y sqlite3
          docker-php-ext-install pdo_sqlite && docker-php-ext-enable pdo_sqlite
  ```
- Revisa que hayas eliminado el literal `#ddev-generated`, ya que este permite que el archivo no se sobrescriba.


## 3. Certificados

### Si estás en fase de desarrollo será suficiente con:
`cp demo/3.0.1/cert /var/www/html/vendor/simplesamlphp/simplesamlphp/cert`

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

### Integración en el kit `clave`
- Copia los archivos `.p12` y `.cer` en la carpeta `./clave/simplesamlphp/cert`.

## 3. Modificación del KIT

### Configuración inicial

Importante, estos comandos son para un arranque inicial, posteriormente se deberá configurar con los datos finales.

#### Configuración
`rsync -av --delete /var/www/html/web/modules/custom/minsait_login_clave/demo/3.0.1/simplesamlphp/metadata/   /var/www/html/vendor/simplesamlphp/simplesamlphp/metadata/`
`rsync -av --delete /var/www/html/web/modules/custom/minsait_login_clave/demo/3.0.1/simplesamlphp/cert/   /var/www/html/vendor/simplesamlphp/simplesamlphp/cert/`
`rsync -av --delete /var/www/html/web/modules/custom/minsait_login_clave/demo/3.0.1/simplesamlphp/config/   /var/www/html/vendor/simplesamlphp/simplesamlphp/config/`

#### Módulos
`rsync -av --delete /var/www/html/web/modules/custom/minsait_login_clave/demo/3.0.1/simplesamlphp/modules/   /var/www/html/vendor/simplesamlphp/simplesamlphp/modules/`

### Test
Revisa que todo funcione correctamente con datos demo.

### Configuración final para producción
- Edita el archivo `./clave/simplesamlphp/config/authsources.php`:
  - Sustituye el certificado antiguo por el nuevo.
  - Añade el valor **CIF_DIR3** al array.
  - Configura:
    - `validate.certificate`
    - `privatekey`
    - `privatekey_password`
  - Sustituye `http://localhost` por `https://tu-dominio.com/`
  - El parámetro logout debe apuntar al frontal de tu proyecto
    'logout.url' => 'https://tu-dominio.com',

### Cambio de variables
- Edita el archivo `./clave/simplesamlphp/config/config.php`
- Revisa y adapta los siguientes valores:
    1. `ASSERTION_URL` -> Url base de la web
    2. `DEFAULT_SPID` -> Identificador del SP configurado para tu organismo

La nueva integración no requiere copiar scripts personalizados ni modificar las clases internas del módulo `saml`. El módulo de Drupal carga el kit directamente desde `../clave` y utiliza el módulo `clave` incluido para gestionar el flujo SAML.
