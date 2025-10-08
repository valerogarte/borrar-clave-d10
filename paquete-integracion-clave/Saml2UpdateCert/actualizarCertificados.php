<?php
// -----------------------------------------------------------------------------
// Estas dos constantes hacen referencia a la ruta y al archivo desde el que se
// va a leer el fichero de configuraci�n que estblecer� el resto de los datos.
// -----------------------------------------------------------------------------
//const PROPERTIES_PATH = "/var/www/html/SP/Saml2UpdateCert/Properties";
const PROPERTIES_PATH = "C:\\xampp\\htdocs\\paquete-integracion-clave\\Saml2UpdateCert\\Properties";
const PROPERTIES_FILE = "certproxy.properties";
// -----------------------------------------------------------------------------


const LFWEB = "<br />";
const LFFILE = "\n";
const SEP =
"--------------------------------------------------------------------------------";

function logger($fichero, $mensaje = null, $error = false)
{
    $fecha   = date('Y/m/d H:i:s');
    $tipo    = (false === $error) ? "-[INFO] : " : "--- [ERROR] *** ---> ";
    //$file = "C:\\logs\\Apache\\log_apps_" . $fecha . ".log";
    $texto   = $fecha . $tipo . $mensaje . LFFILE;

    $fd = fopen($fichero, 'a');
    if (!$fd) {
        throw new Exception("Error en la apertura del fichero de logs");
    }
    fwrite($fd, $texto);
    fclose($fd);
}

function crearDOM_desdeXML2($rutaCompleta)
{
    $resultado = array();

    function dom2array($node) {
        $res = array();
        //print $node->nodeType . LFWEB;

        if ($node->nodeType == XML_TEXT_NODE) {
            $res = $node->nodeValue;
        } else {
            if ($node->hasAttributes()) {
                $attributes = $node->attributes;
                if (!is_null($attributes)) {
                    $res['@attributes'] = array();
                    foreach ($attributes as $index => $attr) {
                        $res['@attributes'][$attr->name] = $attr->value;
                    }
                }
            }

            if ($node->hasChildNodes()) {
                $children = $node->childNodes;
                $end      = $children->length;
                for ($i = 0; $i < $end; $i++) {
                    $child = $children->item($i);
                    $res[$child->nodeName] = dom2array($child);
                }
            }
        }

        return $res;
    }

    $xmlDoc = new DOMDocument();
    $xmlDoc->load($rutaCompleta);
    $nodos = array();
    $tagSearch  = "KeyDescriptor";

    $listaNodos = $xmlDoc->getElementsByTagName($tagSearch);
    $conteo     = $listaNodos->length;
    $i = 0;
    foreach($listaNodos as $nodo) {
        $nodos[$i] = dom2array($nodo);
        $i++;
    }

    if ($conteo == $i) {
        $resultado["isOK"]  = true;
        $resultado["mensaje"] =
                "Procesados correctamente " . $conteo . " nodos." . LFWEB;
        $resultado["nodos"]   = $nodos;
    } else {
        $resultado["isOK"]  = false;
        $resultado["mensaje"] =
                "Error en el procesado del fichero XML " . LFWEB
                . $rutaCompleta . LFWEB;
    }

    return $resultado;
}

function procesarDOM(&$arrayResultado)
{
    $arrayRespuesta = array();

    try {
        foreach ($arrayResultado as $contenido) {
            $nombre = $contenido["ds:KeyInfo"]["ds:KeyName"]["#text"];

            $arrayRespuesta[$nombre]["use"] =
                    $contenido["@attributes"]["use"];

            $arrayRespuesta[$nombre]["state"] =
                    $contenido["ds:KeyInfo"]["ds:MgmtData"]["#text"];

            $arrayRespuesta[$nombre]["decodedKey"] =
                    base64_decode($contenido["ds:KeyInfo"]["ds:X509Data"]["ds:X509Certificate"]["#text"], true);

            // Indicara si hay que crear el fichero ".cer" correspondiente.
            $arrayRespuesta[$nombre]["createFile"] = true;
        }
    } catch (Exception $ex) {
        $mensaje = $e->getMessage();
        throw $e;
    }

    return $arrayRespuesta;
}

function imprimirArray($varArray, $cadena = null)
{
    if (!is_null($varArray)) {
        foreach ($varArray as $clave => $contenido) {
            $strTemp = '[' . $clave . ']';

            if (!is_null($cadena)) { $strRecursivo = $cadena . $strTemp; }
            else                   { $strRecursivo = $strTemp; }

            if (is_array($contenido)) {
                imprimirArray($contenido, $strRecursivo);
            } else {
                if ("decodedKey" == $clave) {
                    $strRecursivo .= " = Aqui esta almacenada la clave decodificada en base64.";
                } else if ("createFile" == $clave) {
                    $strRecursivo .= ($contenido)
                            ? " = Crear fichero .cer"
                            : " = No hay que crear fichero .cer";
                } else {
                    $strRecursivo .= " = " . $contenido;
                }
                echo $strRecursivo . LFWEB;
            }
        }
    }
    echo LFWEB;
}

function procesarFicheroProperties($ruta, $fichero, $delimitador = '_')
{
    $separador = '/';
    $dirActual = getcwd();
    chdir($ruta);

    $rutaCompleta = $ruta . $separador . $fichero;
    $propertiesArray = array();
    try {
        // Si el fichero no existe, ya sea el de configuracion o el de
        // historico, salimos.
        if (!is_file($rutaCompleta)) {
            throw new Exception();

        }

        $datosTempProperties = file($rutaCompleta
                , FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($datosTempProperties as $value) {
            // Analizamos el primer caracter de la cadena y si es '#' es un
            // comentario por lo que lo ignoramos.
            if ('#' == substr($value, 0, 1)) { continue; }

            $bloque1 = explode($delimitador, $value);
            $bloque2 = explode('=', $bloque1[1]);

            $propertiesArray[$bloque1[0]][$bloque2[0]] = $bloque2[1];
        }

    } catch (Exception $ex) {
        $propertiesArray = null;
    }

    chdir($dirActual);

    return $propertiesArray;
}

function comprobarDirectorio($rutaDestino, $permisos = 0777)
{
    $arrayResultado = array();

    $sep = '\\';        // Separador de directorios.
    $mensaje = "Comprobando ruta: " . $rutaDestino;

    // Obtenemos la ruta de trabajo.
    $rutaActual = getcwd() . $sep;
    //echo "Ruta actual: " . $rutaActual . LFWEB;

    // Obtenemos el arbol de carpetas a revisar o crear.
    $listaCarpetas = explode('/', $rutaDestino);
    $numCarpetas   = count($listaCarpetas);
    //echo "Hay que revisar: " . $numCarpetas . " carpetas." . LFWEB;

    // En principio, la ruta de trabajo es la actual.
    $rutaNueva = $rutaActual;

    // Recorremos el array de carpetas para revisarlas.
    $unidades = array ("C:", "D:", "E:", "F:", "G:", "H:", "X:", "Y:", "Z:");
    try {
        for ($i = 0; $i < $numCarpetas; $i++) {
            // Comprobamos si la primera ocurrencia corresponde a una unidad de disco.
            if (0 == $i) {
                if (in_array($listaCarpetas[0], $unidades)) {
                    // Si es asi cambiamos el directorio a esa unidad.
                    $rutaNueva = $listaCarpetas[0] . $sep;
                    chdir($rutaNueva);
                }
                continue;   // saltamos al siguiente ciclo del bucle
            }

            // Revisamos las subcarpetas.
            if (in_array($listaCarpetas[$i], $unidades)) {
                $mensaje .= " ... La ruta: " . $rutaDestino . ", es erronea";
                    throw new Exception($mensaje);
            }
            $rutaNueva .= $listaCarpetas[$i] . $sep;

            // Si no existe el directorio ...
            if (!is_dir($rutaNueva) && strlen($rutaNueva) > 0) {
                // Intentamos crearlo
                $isOK = mkdir($rutaNueva, $permisos);
                $mensaje .= " ---> Ruta: "
                        . $rutaNueva . " creada correctamente." . LFWEB;
                if (!$isOK) {
                    $mensaje = " ... No se ha podido crear la carpeta " . $rutaNueva;
                    throw new Exception($mensaje);
                }
            }
        }

    } catch (Exception $e) {
        $mensaje = "ERROR! ---> ". $e->getMessage();
        throw new Exception($mensaje);
    }

    // Volvemos a la carpeta de trabajo.
    chdir($rutaActual);
    //echo $mensaje . LFWEB;

    return $rutaNueva;
}

function CreateHistoryFromXML(&$xmlArray)
{
    // Esta funcion crea el array de historico, la primera vez que se ejecuta el
    // proceso y luego, se enviara al fichero certificates.properties.
    $arrayResultado = array();
    $fecha = date(DATE_ATOM);

    foreach ($xmlArray as $clave => $contenido) {
        $arrayResultado[$clave]["state"] = $contenido["state"];
        $arrayResultado[$clave]["updated"] = $fecha;
    }

    return $arrayResultado;
}

function compararArrays_XML_Hist(&$xmlArray, &$certificatesArray)
{
    // Para cada clave del array XML ...
    foreach($xmlArray as $clave => $contenido) {
        // comprobamos si esta existe en el array del Historico de certificados.
        $existeClave = array_key_exists($clave, $certificatesArray);

        // Si la clave existe en el historico, el archivo .cer ya se tiene que
        // haber procesado por lo que no es necesario volverlo a crear.
        if ($existeClave) {
            $xmlArray[$clave]["createFile"] = false;

            // Comparamos el estado de ambos registros y, si son distintos, se
            // actualiza el Historico con el nuevo estado y la fecha de ahora.
            if ($certificatesArray[$clave]["state"] !=
                    $xmlArray[$clave]["state"]) {

                // Actualizamos los valores correspondientes.
                $certificatesArray[$clave]["state"] = $xmlArray[$clave]["state"];
                $certificatesArray[$clave]["updated"] = date(DATE_ATOM);
            }
        }
        // Si no existe, simplemente la insertamos.
        else {
            $certificatesArray[$clave]["state"] = $xmlArray[$clave]["state"];
            $certificatesArray[$clave]["updated"] = date(DATE_ATOM);
        }
    }
}

function actualizarFicheroHistorico($ruta, $certificatesFile
        , &$certificatesArray)
{
    $rutaActual = getcwd();

    chdir($ruta);
    try {
        $fd = fopen($certificatesFile, 'w');
        if (!$fd) {
            $mensaje = "Error de creacion del fichero. " .$certificatesFile;
            throw new Exception($mensaje);
        }

        foreach ($certificatesArray as $clave => $contenido) {
            $mensaje = $clave . ".state=" . $contenido["state"] . LFFILE;
            fwrite($fd, $mensaje);
            $mensaje = $clave . ".updated=" . $contenido["updated"] . LFFILE;
            fwrite($fd, $mensaje);
        }
        fclose($fd);

    } catch (Exception $ex) {
        echo $mensaje . LFWEB;
        throw $ex;
    }

    chdir($rutaActual);

    return;
}

function crearFicherosCER($certificatesPath, &$xmlArray)
{
    $rutaActual = getcwd();
    chdir($certificatesPath);
    $contador = 0;
    try {
        // Para cada registro en el xmlArray, comprobamos si hay que generar el
        // fichero .cer y, si es asi, se crea.
        foreach ($xmlArray as $clave => $contenido) {
            if ($contenido["createFile"]) {
                $contador++;
                $tempFile = $clave . ".cer";
                $fd = fopen($tempFile, 'w');
                if (!$fd) {
                    $mensaje = "Error de creacion del fichero. " . $tempFile;
                    throw new Exception($mensaje);
                }
                $mensaje = $contenido["decodedKey"];
                fwrite($fd, $mensaje);
                fclose($fd);
            }
        }

    } catch (Exception $ex) {
        $mensaje = "ERROR! ---> ". $ex->getMessage();
        throw $e;
    }

    chdir($rutaActual);

    return $contador;
}
/* -------------------------------------------------------------------------- */


// *********************** BLOQUE PRINCIPAL ************************************
try {
    /* *************************************************************************
     * 1.   LECTURA DEL ARCHIVO DE CONFIGURACION
     ************************************************************************ */
    // Procesamos el fichero para obtener las referencias de estado, directorios
    // y ficheros necesarias
    $propertiesArray =
            procesarFicheroProperties(PROPERTIES_PATH, PROPERTIES_FILE);

    // -------------------------------------------------------------------------
    $mensaje = "Leyendo fichero de configuracion --> '"
            . PROPERTIES_FILE . "' ...";

    // Si no se ha encontrado el fichero terminamos.
    if (is_null($propertiesArray)) {
        $mensaje .= "No existe el fichero de configuracion " . PROPERTIES_FILE;
        throw new Exception($mensaje);
    }
    $mensaje .= "fichero de configuracion leido correctamente." . LFFILE;
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 1.0  Comprobamos el directorio de almacenamiento del LOG y establecemos
    //      el nombre del archivo de log.
    // -------------------------------------------------------------------------
    $mensaje .= "Comprobando directorio de logs...";
    $logsPath = $propertiesArray["certproxy2"]["logsPath"];

    // Si no existe el directorio de logs, tratamos de crearlo.
    if (!is_dir($logsPath)) {
        comprobarDirectorio($logsPath);
        $mensaje .= "Carpeta " . $logsPath . " creada correctamente.";
    } else {
        $mensaje .= "La ruta a la carpeta de logs: " . $logsPath . ", es correcta.";
    }
    $logsFile = $logsPath . '/' . $propertiesArray["certproxy2"]["logsFile"]
            . ".log";
    logger($logsFile, SEP);
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 1.1  Validamos los atributos leidos, necesarios para la validacion.
    // -------------------------------------------------------------------------
    $mensaje = "Comprobando activacion del proceso...";
    if ("true" != $propertiesArray["certproxy2"]["processActivated"]) {
        $mensaje .= "El proceso no esta activado. Por favor activelo en el "
                . " fichero de configuracion";
        throw new Exception($mensaje);
    }
    $mensaje .= "... El proceso esta activado.";
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 1.2  Validamos que el nombre del fichero, donde se van a guardar el
    // historico de las acciones realizadas sobre los ficheros de claves,
    // es correcto.
    // -------------------------------------------------------------------------
    $certificatesFile = $propertiesArray["certproxy2"]["certificatesFile"];
    $mensaje = "Validando nombre del fichero de historico ...'"
            . $certificatesFile . "' ...";

    if ("certificates.properties" != $certificatesFile) {
        $mensaje .= "Error, el nombre del fichero de historico de los certificados '"
                . $certificatesFile . "' --> NO es correcto!";
        throw new Exception($mensaje);
    }
    $mensaje .= "El fichero '" . $certificatesFile . "' es correcto.";
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 1.3  Comprobamos que existe la carpeta donde se van a guardar los
    // ficheros de claves, de acuerdo con lo recogido en el fichero de
    // configuracion.
    // -------------------------------------------------------------------------
    $certificatesPath = $propertiesArray["certproxy2"]["certificatesPath"];

    $mensaje = "Validando ruta de almacenamiento de claves: '"
            . $certificatesPath . "' ...";

    // Si no existe el directorio de certificados, tratamos de crearlo.
    if (!is_dir($certificatesPath)) {
        comprobarDirectorio($certificatesPath);
        $mensaje .= "Carpeta " . $certificatesPath . " creada correctamente.";
    } else {
        $mensaje .= "La ruta a la carpeta de logs: " . $certificatesPath
                . ", es correcta.";
    }
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------
    /* ********************************************************************** */


    /* *************************************************************************
     * 2º   LECTURA DEL ARCHIVO XML
     ************************************************************************ */
    // -------------------------------------------------------------------------
    // 2.1  Descargamos el fichero xml desde la ruta...
    //      $propertiesArray["certproxy2"]["endpoint"]
    //
    // Para las pruebas hay una linea en el fichero de configuracion que guarda
    // el nombre de fichero xml que se va a utilizar para las mismas y que debe
    // estar en dicha ruta.
    // $propertiesArray["certproxy2"]["filename"]
    // -------------------------------------------------------------------------
    $xmlPath = $propertiesArray["certproxy2"]["endpoint"];

    // -------------------------------------------------------------------------
    // Comprobamos la ruta a la que vamos a acceder puesto que, si estamos
    // haciendo pruebas en una ruta local, no empezara por 'https' y habra que
    // comprobar que existen la ruta y el archivo .xml con el que se quiere
    // hacer las pruebas.
    // -------------------------------------------------------------------------
    $mensaje = "Obteniendo datos del fichero XML ... ";
    $strAValidar = substr($xmlPath, 1, 4);
    if ("http" != $strAValidar) {
      //  $xmlFile = $propertiesArray["certproxy2"]["filename"];
        $xmlPath = $xmlPath ;//. '\\' . $xmlFile;

      //  if (!is_file($xmlPath)) {
       //     $mensaje .= " el fichero '" . $xmlPath . " no es un fichero valido.";
        //    throw new Exception($mensaje);
       // }
    }

    // -------------------------------------------------------------------------
    // 2.2  Lo guardamos, una vez procesado, en el array $xmlArray.
    // -------------------------------------------------------------------------
    $xmlData = crearDOM_desdeXML2($xmlPath);
    if (!$xmlData["isOK"]) {
        $mensaje = " Error en la obtencion de los datos del XML ==> "
                . var_export($xmlData);
        throw new Exception($mensaje);
    }
    $mensaje .= " Datos XML obtenidos correctamente.";
    logger($logsFile, $mensaje);

    $mensaje  = "Creando array con los datos XML ... ";
    $xmlArray = procesarDOM($xmlData["nodos"]);
    $mensaje .= " datos formateados correctamente.";
    logger($logsFile, $mensaje);
    //imprimirArray($xmlArray);
    // -------------------------------------------------------------------------
    /* ********************************************************************** */


    /* *************************************************************************
     * 3º   PROCESADO DEL ARCHIVO DE HISTÓRICO
     ************************************************************************ */
    // -------------------------------------------------------------------------
    // 3.1  Procesamos el fichero "certificates.properties" contenido en...
    //      $propertiesArray["certproxy2"]["certificatesFile"]
    //
    //      Si no hay datos el array $certificatesArray, estara vacio.
    //
    // La llamada se realiza con el delimitador de campos '.' ya que, por
    // defecto, se hace con '_'.
    // -------------------------------------------------------------------------
    $certificatesFile = $propertiesArray["certproxy2"]["certificatesFile"];
    $mensaje = "Procesando fichero de historico: '"
            . $certificatesFile . "' ...";

    $certificatesArray =
            procesarFicheroProperties(PROPERTIES_PATH, $certificatesFile, '.');

    if (is_null($certificatesArray)) {
        $mensaje .= " El archivo de Historico esta vacio o NO EXISTE aun.";
    } else {
        $mensaje .= " Procesado correcto.";
        //imprimirArray($certificatesArray);
    }
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------
    /* ********************************************************************** */


    /* *************************************************************************
     * 4º   CRUCE DE LA INFORMACIÓN RECOGIDA EN EL ARCHIVO DE HISTÓRICO Y
     *      EL XML RECIÉN LEIDO
     ************************************************************************ */
    // -------------------------------------------------------------------------
    // 4.1  Se comprueba si hay datos en el fichero de Historico ...
    // -------------------------------------------------------------------------
    if (is_null($certificatesArray)) {
        $mensaje = "No hay datos de historico de claves ... ";
        // ---------------------------------------------------------------------
        // 4.1.1  Si no los hay, se crea a partir de la informacion contenida en
        // el  archivo XML leido.
        // ---------------------------------------------------------------------
        $certificatesArray = CreateHistoryFromXML($xmlArray);
        $mensaje .= " Generando array de historico de claves.";
    }

    // -------------------------------------------------------------------------
    // 4.1.2  Si hay datos, entonces hay que comparar los contenidos de los dos
    // arrays con el fin de modificar el historico y establecer cúales son los
    // registros del archivo XML de los que hay que generar el archivo .cer
    // -------------------------------------------------------------------------
    else {
        $mensaje = "Comparando array XML con el Historico de claves ... ";
        compararArrays_XML_Hist($xmlArray, $certificatesArray);
        $mensaje .= " Comparacion realizada correctamente.";
    }
    logger($logsFile, $mensaje);

    // -------------------------------------------------------------------------
    // 4.2  Se generan los archivos .CER que sean necesarios.
    // -------------------------------------------------------------------------
    $mensaje = "Revisando array XML para crear archivos de claves ... ";
    $contador = crearFicherosCER($certificatesPath, $xmlArray);
    $mensaje .= " Se han creado " . $contador . " archivos .cer";
    logger($logsFile, $mensaje);
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 4.3  Se actualiza el fichero de historico.
    // -------------------------------------------------------------------------
    $mensaje = "Actualizando el fichero de historico de claves ...";
    actualizarFicheroHistorico(PROPERTIES_PATH, $certificatesFile, $certificatesArray);
    $mensaje .= " Fichero de historico actualizado correctamente.";
    logger($logsFile, $mensaje);

} catch (Exception $e) {
    echo $e->getMessage() . LFWEB;

    if (!is_null($logsFile)) {
        logger($logsFile, $mensaje, false);
    }
}
?>
