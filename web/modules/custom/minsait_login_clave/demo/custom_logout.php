<?php
error_reporting(1);
require_once('../_include.php');
require_once('../../lib/_autoload.php');
require_once('../../lib/SAML2/Constants.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/XML/saml/NameIDLogOut.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasMessage.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasAuthnRequest.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasHTTPPost.php');
require_once('../../lib/AESGCM.php');

use SAML2\Constants;
use Auth\Source\SP;

$authSource = SAML2\Constants::SPID;
$as = new SimpleSAML_Auth_Simple($authSource);
$returnTo = SimpleSAML_Utilities::selfURL();

$state = array_merge(array(), array(
        'SimpleSAML_Auth_Default.id' => $authSource,
        'SimpleSAML_Auth_Default.Return' => $returnTo,
        'SimpleSAML_Auth_Default.ErrorURL' => NULL,
        'LoginCompletedHandler' => array(false, 'loginCompleted'),
        'LogoutCallback' => array(false, 'logoutCallback'),
        'LogoutCallbackState' => array('SimpleSAML_Auth_Default.logoutSource' => $authSource,),)
);

/*
 * Se cargan la configuración de nuestro Proveedor de Servicio de config/authsources.php
 */
$as = SimpleSAML_Auth_Source::getById($authSource);
$localConfig = $as->getLocalConfig();
//Se carga de la configuración la url del IdP Proxy.
$idp[0]= $as->getidp();
$idp[1]= $as->getidp_binding();

$_POST['spcountry'] = 'ES';
$_POST['country'] = 'ES';
$_POST['default'] = "true";

//Cargar la información del IdP de la configuración
$idplocalConfig = $as->getIdPfromConfig($idp);
$session = SimpleSAML_Session::getSessionFromRequest();
$session->cleanup();

try {
    $state['saml:sp:AuthId'] = $authSource;

    /**
     * Build an logout request based on information in the configuration and the requested atributes.
     */
    // $ar = eidas_saml_Message::buildAuthnRequest($extensions, $localConfig, $idplocalConfig);

    $lr = eidas_saml_Message::buildLogoutRequest($localConfig, $idplocalConfig);
    $lr->setRelayState('MyRelayState');


    //$NameID['SPNameQualifier']=$localConfig->getString('logout.url');
    //$NameID['Format']='urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';
    //$NameID['Value']=$authSource;
	$nameIdArray = [
    'Value' => $authSource,
    'Format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
    'NameQualifier' => $localConfig->getString('logout.url'),
];

$nameId = new SAML2\XML\saml\NameIDLogOut();
$nameId->value = $nameIdArray['Value']; // Asignar directamente la propiedad 'value'
$nameId->Format = $nameIdArray['Format']; // Asignar el formato
if (isset($nameIdArray['NameQualifier'])) {
    $nameId->NameQualifier = $nameIdArray['NameQualifier']; // Asignar NameQualifier si existe
}

    $lr->setNameId($nameId);

    $session = SimpleSAML_Session::getSessionFromRequest();
    $session->cleanup();

    $id = SimpleSAML_Auth_State::saveState($state, 'saml:sp:sso', TRUE);

    $lr->setId($id);

    $b = new SAML2_EidasHTTPPost($_POST['country'], $_POST);	


   $b->sendDefaultLogout($lr);

} catch (SimpleSAML_Error_Exception $e) {
    SimpleSAML_Auth_State::throwException($state, $e);
} catch (Exception $e) {
    $e = new SimpleSAML_Error_UnserializableException($e);
    SimpleSAML_Auth_State::throwException($state, $e);
}


?>
