<?php
error_reporting(0);
require_once('../_include.php');
require_once('../../lib/_autoload.php');
require_once('../../lib/SAML2/Constants.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasMessage.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasAuthnRequest.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasHTTPPost.php');
require_once('../../lib/AESGCM.php');

use SAML2\Constants;
use Auth\Source\SP;
$authSource = SAML2\Constants::SPID;
$as = new SimpleSAML_Auth_Simple($authSource);
$returnTo = SimpleSAML_Utilities::selfURL();
//error_reporting(0);
$state = array_merge(array(), array(
        'SimpleSAML_Auth_Default.id' => $authSource,
        'SimpleSAML_Auth_Default.Return' => $returnTo,
        'SimpleSAML_Auth_Default.ErrorURL' => NULL,
        'LoginCompletedHandler' => array(false, 'loginCompleted'),
        'LogoutCallback' => array(false, 'logoutCallback'),
        'LogoutCallbackState' => array('SimpleSAML_Auth_Default.logoutSource' => $authSource,),)
);

/*
 * Se carga la configuración de nuestro Proveedor de Servicio de config/authsources.php
 */
$as = SimpleSAML_Auth_Source::getById($authSource);
$localConfig = $as->getLocalConfig();
//Se carga de la configuración la url del IdP Proxy.
$idp[0]= $as->getidp();
$idp[1]= $as->getidp_binding();

//Cargar la información del IdP de la configuración
$idplocalConfig = $as->getIdPfromConfig($idp);
$session = SimpleSAML_Session::getSessionFromRequest();
$session->cleanup();

try {
    $state['saml:sp:AuthId'] = $authSource;

    /**
     * Cargar extensiones. Se cargan los atributos seleccionados
     */
  //  $forceauthn = filter_input(INPUT_POST, 'forceauthn');
  //  if($forceauthn==NULL){$forceauthn = false;}
    // Define authentication request parameters
    $_POST = [
        "loa" => "http://eidas.europa.eu/LoA/low", // http://eidas.europa.eu/LoA/substantial http://eidas.europa.eu/LoA/high
        "comparisonofloa" => "minimum",
        "SPType" => "public",
        "forceauthn" => "true",
        // "AEATIdP" => "off", // DESACTIVAR Cl@ve PIN ?
        // "EIDASIdP" => "off", // DESACTIVAR Ciudadanos UE ?
        // "CLVMOVILIdP" => "off", // DESACTIVAR Cl@ve Móvil ?
        // "AFirmaIdP" => "off", // DESACTIVAR DNI Electrónico ?
        // "GISSIdP" => "off", // DESACTIVAR Cl@ve Permantente ?
    ];
    $extensions = Constants::genDefaultAttrs($_POST);

    /**
     * Build an authentication request based on information in the configuration and the requested atributes. Use of the subclass SAML2_EidasAuthnRequest which extends from AuthnRequest class.
     */

    $ar = eidas_saml_Message::buildAuthnRequest($extensions, $forceauthn, $localConfig, $idplocalConfig);

    $ar->setRelayState('MyRelayState');

    if (isset($state['saml:AuthnContextClassRef'])) {
        $accr = SimpleSAML_Utilities::arrayize($state['saml:AuthnContextClassRef']);
        $ar->setRequestedAuthnContext(array('AuthnContextClassRef' => $accr));
    }

    if (isset($state['isPassive'])) {
        $ar->setIsPassive((bool)$state['isPassive']);
    }

    if (isset($state['saml:NameIDPolicy'])) {
        if (is_string($state['saml:NameIDPolicy'])) {
            $policy = array(
                'Format' => (string)$state['saml:NameIDPolicy'],
                'AllowCreate' => TRUE,
            );
        } elseif (is_array($state['saml:NameIDPolicy'])) {
            $policy = $state['saml:NameIDPolicy'];
        } else {
            throw new SimpleSAML_Error_Exception('Invalid value of $state[\'saml:NameIDPolicy\'].');
        }
        $ar->setNameIdPolicy($policy);
    }

    /**
     * In case of multiple IDP, the list of them is retrieved. In this case, the list of IDPs must be specified in metadata/saml20-idp-remote.php file.
     */
    if (isset($state['saml:IDPList'])) {
        $IDPList = $state['saml:IDPList'];
    } else {
        $IDPList = array();
    }

    $ar->setIDPList(array_unique(array_merge($localConfig->getArray('IDPList', array()),
        $idplocalConfig->getArray('IDPList', array()),
        (array) $IDPList)));

    if (isset($state['saml:ProxyCount']) && $state['saml:ProxyCount'] !== null) {
        $ar->setProxyCount($state['saml:ProxyCount']);
    } elseif ($idplocalConfig->getInteger('ProxyCount', null) !== null) {
        $ar->setProxyCount($idplocalConfig->getInteger('ProxyCount', null));
    } elseif ($localConfig->getInteger('ProxyCount', null) !== null) {
        $ar->setProxyCount($localConfig->getInteger('ProxyCount', null));
    }

    $requesterID = array();
    if (isset($state['saml:RequesterID'])) {
        $requesterID = $state['saml:RequesterID'];
    }

    if (isset($state['core:SP'])) {
        $requesterID[] = $state['core:SP'];
    }

    $ar->setRequesterID($requesterID);

    $session = SimpleSAML_Session::getSessionFromRequest();
    $session->cleanup();

    $id = SimpleSAML_Auth_State::saveState($state, 'saml:sp:sso', TRUE);

    $ar->setId($id);

    $b = new SAML2_EidasHTTPPost($_POST);
    $b->send($ar);
} catch (SimpleSAML_Error_Exception $e) {
    SimpleSAML_Auth_State::throwException($state, $e);
} catch (Exception $e) {
    $e = new SimpleSAML_Error_UnserializableException($e);
    SimpleSAML_Auth_State::throwException($state, $e);
}

?>
