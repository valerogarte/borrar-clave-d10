<?php

//Configuración del IdP
$metadata['https://se-pasarela.clave.gob.es'] = [

    //Configuración del login en Pasarela
    'SingleSignOnService' => [
        [
            'Location' => 'https://se-pasarela.clave.gob.es/Proxy2/ServiceProvider',
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ]
    ],

    //Configuración del logout en Pasarela
    'SingleLogoutService' => [
        [
            'Location' => 'https://se-pasarela.clave.gob.es/Proxy2/ServiceProvider',
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
    ],

    //Certificado público que permite confiar con el certificado que firma las peticiones SAML que vuelven de CLAVE PASARELA
    'certificate' => 'sello_entidad_sgad_pruebas.cer',

    //Establecer sha512 como algoritmo de cifrado
    'signature.algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
];

$metadata['http://localhost:8888'] = [

    //Configuración del login en Pasarela
    'SingleSignOnService' => [
        [
            'Location' => 'http://localhost:8888/Proxy2/ServiceProvider',
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ]
    ],

    //Configuración del logout en Pasarela
    'SingleLogoutService' => [
        [
            'Location' => 'http://localhost:8888/Proxy2/ServiceProvider',
            'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
    ],

    //Certificado público que permite confiar con el certificado que firma las peticiones SAML que vuelven de CLAVE PASARELA
    'certificate' => 'sello_entidad_sgad_pruebas.cer',

    //Establecer sha512 como algoritmo de cifrado
    'signature.algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
];
