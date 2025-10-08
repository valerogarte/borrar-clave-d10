<?php

$config = [
    '21114293V_E04975701' => [
        'clave:SPClave', //IMPORTANTE configurar este SP para funcionar con el módulo custom 'clave'
        'certificate' => 'eidas_certificado_pruebas___99999972c.crt',
        'privatekey' => 'eidas_certificado_pruebas___99999972c.pem',
        'privatekey_pass' => 'changeit',
        'name' => [
            'en' => 'demo-sp-php',
            'pt' => 'demo-sp-php',
            'es' => 'demo-sp-php'
        ],
        'sign.authnrequest' => TRUE,
        'sign.logout' => TRUE,
        'entityID' => 'https://' . $_SERVER['HTTP_HOST'] . '/simplesaml/module.php/saml/sp/saml2-acs.php/21114293V_E04975701',
        'idp' => 'https://se-pasarela.clave.gob.es',
        'ProviderName' => '21114293V_E04975701;SPApp',
        'OrganizationName' => [
            'es' => 'SP nodo Eidas'
        ],
        'OrganizationDisplayName' => [
            'es' => 'SP nodo Eidas'
        ],
        'OrganizationURL' => [
            'es' => 'https://se-eidas.redsara.es'
        ],
    ],

    '11111111H_E04995902' => [
        'clave:SPClave',
        'certificate' => 'eidas_certificado_pruebas___99999972c.crt',
        'privatekey' => 'eidas_certificado_pruebas___99999972c.pem',
        'privatekey_pass' => 'changeit',
        'name' => [
            'en' => 'demo-sp-php',
            'pt' => 'demo-sp-php',
            'es' => 'demo-sp-php'
        ],
        'attributes.NameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri',
        'sign.authnrequest' => TRUE,
        'sign.logout' => TRUE,
        'entityID' => 'https://' . $_SERVER['HTTP_HOST'] . '/simplesaml/module.php/saml/sp/saml2-acs.php/11111111H_E04995902',
        'idp' => 'http://localhost:8888',
        'ProviderName' => '11111111H_E04995902;SPApp',
        'OrganizationName' => [
            'es' => 'SP nodo Eidas'
        ],
        'OrganizationDisplayName' => [
            'es' => 'SP nodo Eidas'
        ],
        'OrganizationURL' => [
            'es' => 'https://se-eidas.redsara.es'
        ],
    ],
];
