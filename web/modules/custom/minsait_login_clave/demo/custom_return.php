<?php
ini_set('display_errors', 1);
error_reporting(0);

require_once('../../lib/_autoload.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/EidasMessage.php');
require_once('../../modules/saml/lib/Message.php');
require_once('../../lib/SAML2/Constants.php');
require_once('../../vendor/simplesamlphp/saml2/src/SAML2/HTTPPost.php');
require_once('../../lib/SimpleSAML/Auth/Source.php');

function processClaveSamlAndGenerateHtml($sspBasePath, $sspConfigDir, $request) {
  $success = false;
  $data = [];
  
  try {
    $b = new SAML2\HTTPPost();
    $response = $b->receive();

    $authSource = SAML2\Constants::SPID;
    $as = SimpleSAML_Auth_Source::getById($authSource);
    $splocalConfig = $as->getLocalConfig();

    $idp = [$as->getidp(), $as->getidp_binding()];
    $idplocalConfig = $as->getIdPfromConfig($idp);

    if (eidas_saml_Message::processResponse($splocalConfig, $idplocalConfig, $response)) {
      $assertions = $response->getAssertions();
      if ($assertions[0] instanceof SAML2\EncryptedAssertion) {
        $keyArray = SimpleSAML\Utils\Crypto::loadPrivateKey($splocalConfig, true);
        if (!isset($keyArray["PEM"])) {
          throw new \Exception("Clave PEM no encontrada");
        }
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type'=>'private']);
        if (!empty($keyArray['password'])) {
          $key->passphrase = $keyArray['password'];
        }
        $key->loadKey($keyArray['PEM']);
        $assertions[0] = $assertions[0]->getAssertion($key, []);
      }
      $attributes = $assertions[0]->getAttributes();
      $status = $response->getStatus();

      if ($status['Code'] === 'urn:oasis:names:tc:SAML:2.0:status:Success') {
        foreach ($attributes as $key => $value) {
          $keyval = array_search($key, SAML2\Constants::$attrs);
          $attrName = explode('.', $keyval)[0] ?? $key;
          if (in_array($attrName, ['PersonIdentifier', 'CurrentGivenName', 'CurrentFamilyName', 'RelayState'])) {
            $data[$attrName] = $value[0];
          }
        }
        $success = true;
      }
    }
  }
  catch (\Exception $e) {
    $success = false;
  }
  
  return [
    'success' => $success,
    'html'    => $html,
    'data'    => $data,
  ];
}
