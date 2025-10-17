<?php

namespace Drupal\minsait_login_clave\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use const FIELD_NUMERO_DOCUMENTO;

/**
 * Configure Minsait login clave settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'minsait_login_clave_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['minsait_login_clave.settings'];
  }

  /**
   * Performs all system verifications and returns markup elements.
   *
   * @return array
   *   Array containing verification form elements.
   */
  private function performVerifications() {
    $elements = [];
    $vendorSimpleSamlPHP = DRUPAL_ROOT . '/../vendor/simplesamlphp';
    $path_module = \Drupal::service('extension.list.module')->getPath('minsait_login_clave');

    // Verificación de SimpleSAMLphp
    if (!is_dir($vendorSimpleSamlPHP)) {
      $elements['saml_status'] = [
        '#type' => 'markup',
        '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">❌ '
          . $this->t('La librería simplesamlphp no existe en @path. Debe instalarla con "<code>composer require simplesamlphp/simplesamlphp:2.4.3</code>"', ['@path' => $vendorSimpleSamlPHP])
          . '</div>',
      ];
    }
    else {
      if (is_readable($vendorSimpleSamlPHP)) {
        $icon = '✅';
        $message = $this->t('@icon La librería simplesamlphp está instalada y con permisos de lectura.', ['@icon' => $icon]);
      }
      else {
        $icon = '⚠';
        $message = $this->t('@icon La librería simplesamlphp existe pero sin permisos de lectura.', ['@icon' => $icon]);
      }
      $elements['saml_status'] = [
        '#type' => 'markup',
        '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">'
          . $message . '</div>',
      ];
    }

    // Verificación de módulos y base de datos clave.sq3
    $modulesPath = $vendorSimpleSamlPHP . '/simplesamlphp/modules';
    $requiredModules = [
      'admin', 'authorize', 'clave', 'consent', 'consentAdmin', 'core', 'cron',
      'discopower', 'exampleauth', 'ldap', 'metarefresh', 'multiauth', 'radius',
      'saml', 'sqlauth', 'statistics'
    ];
    $missingModules = [];
    $message = '❌ ' . $this->t('No se encontró la carpeta de módulos en @path.', ['@path' => $modulesPath]);

    if (is_dir($modulesPath) && is_readable($modulesPath)) {
      foreach ($requiredModules as $module) {
        if (!is_dir($modulesPath . '/' . $module)) {
          $missingModules[] = $module;
        }
      }
      if (empty($missingModules)) {
        $message = $this->t('✅ Todos los módulos requeridos están presentes en @path.', ['@path' => $modulesPath]);
      } else {
        $message = $this->t('⚠ Faltan los siguientes módulos en @path: @missing', [
          '@path' => $modulesPath,
          '@missing' => implode(', ', $missingModules)
        ]);
        $message .= '<br>' . $this->t('Puedes copiar los módulos faltantes ejecutando el siguiente comando en el servidor:');
        $message .= '<br><br><pre><code>rsync -av --delete '. DRUPAL_ROOT . '/' .$path_module .'/demo/3.0.1/simplesamlphp/modules/   '. DRUPAL_ROOT .'/../vendor/simplesamlphp/simplesamlphp/modules/</code></pre>';
      }
    }

    // Verifica que sqlite3 esté instalado
    if (extension_loaded('sqlite3')) {
      $message .= '<br>✅ ' . $this->t('La extensión SQLite3 de PHP está disponible en el sistema.');
    } else {
      $message .= '<br>❌ ' . $this->t('La extensión SQLite3 de PHP no está disponible en el sistema. Debes instalar la extensión php-sqlite3 para que funcione correctamente.');
    }

    // Verificación del fichero de configuración
    $configFilePath = $vendorSimpleSamlPHP . '/simplesamlphp/config/config.php';
    // Revisa que el storetype = 'sql' y que la ruta de la base de datos es correcta
    if (file_exists($configFilePath) && is_readable($configFilePath)) {
      $configContent = file_get_contents($configFilePath);
      // Usar expresiones regulares para permitir espacios arbitrarios.
      $storeTypeSql = preg_match("/'store\.type'\s*=>\s*'sql'/", $configContent);

      if ($storeTypeSql) {
        $message .= '<br>✅ ' . $this->t('El fichero de configuración config.php está correctamente configurado para usar la base de datos SQLite.');
      } else {
        $message .= '<br>❌ ' . $this->t('El fichero de configuración config.php no está correctamente configurado para usar la base de datos SQLite. Debes revisar las opciones store.type y database. Recuerda que tienes que usar el config.php del kit Cl@ve y modificar el store.type a "sql" y la ruta de la base de datos a la ruta completa del fichero clave.sq3.');
        $message .= '<br>' . $this->t('Puedes copiar el fichero de configuración ejecutando el siguiente comando en el servidor:');
        $message .= '<br><br><pre><code>rsync -av '. DRUPAL_ROOT . '/' .$path_module .'/demo/3.0.1/simplesamlphp/config/config.php   '. DRUPAL_ROOT .'/../vendor/simplesamlphp/simplesamlphp/config/</code></pre>';
      }
    } else {
      $message .= '<br>❌ ' . $this->t('El fichero de configuración config.php no existe o no tiene permisos de lectura.');
      $message .= '<br>' . $this->t('Puedes copiar el fichero de configuración ejecutando el siguiente comando en el servidor:');
      $message .= '<br><br><pre><code>rsync -av '. DRUPAL_ROOT . '/' .$path_module .'/demo/3.0.1/simplesamlphp/config/config.php   '. DRUPAL_ROOT .'/../vendor/simplesamlphp/simplesamlphp/config/</code></pre>';
    }

    // Verificación del fichero clave.sq3
    $claveDbPath = DRUPAL_ROOT . '/../clave.sq3';
    if (file_exists($claveDbPath) && is_readable($claveDbPath)) {
      $message .= '<br>✅ ' . $this->t('El fichero clave.sq3 existe y tiene permisos de lectura.');
    } else {
      $message .= '<br>❌ ' . $this->t('El fichero clave.sq3 no existe o no tiene permisos de lectura.');
      $message .= '<br>' . $this->t('Puedes crear la base de datos ejecutando el siguiente comando en el servidor:');
      $message .= '<br><br><pre><code>sqlite3 /var/www/html/web/../clave.sq3 ".databases"</code></pre>';
    }

    // Verificación del servidor web (debe ser Apache, no Nginx)
    $server_software = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if (stripos($server_software, 'apache') !== FALSE) {
      $message .= '<br>✅ ' . $this->t('El servidor web es Apache.');
    } elseif (stripos($server_software, 'nginx') !== FALSE) {
      $message .= '<br>❌ ' . $this->t('El servidor web es Nginx. Se requiere Apache para el correcto funcionamiento de Cl@ve.');
    } else {
      $message .= '<br>⚠ ' . $this->t('No se ha podido determinar el servidor web o no es Apache. Se recomienda usar Apache.');
    }

    // Verifica que simplesaml responde correctamente
    $simplesamlUrl = \Drupal::request()->getSchemeAndHttpHost() . '/simplesaml/';
    $response = @file_get_contents($simplesamlUrl);
    if ($response !== FALSE) {
      $message .= '<br>✅ ' . $this->t('Simplesaml está respondiendo correctamente.');
    } else {
      $message .= '<br>❌ ' . $this->t('<a href="' . $simplesamlUrl . '" target="_blank">Simplesaml</a> no está respondiendo. Verifica la configuración de tu Apache.');
      $message .= '<br>' . $this->t('Puedes configurar Apache ejecutando el siguiente comando en el servidor:');
      $message .= '<br><br><pre><code>cp ' . DRUPAL_ROOT . '/' . $path_module . '/demo/3.0.1/ddev/apache-site.conf /var/www/html/.ddev/apache/apache-site.conf</code></pre>';
    }

    $elements['modules_check'] = [
      '#type' => 'markup',
      '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">' . $message . '</div>',
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('minsait_login_clave.settings');

    // Crear vertical tabs.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-verificaciones',
      '#weight' => -10,
    ];

    // Primer tab: Verificaciones.
    $form['verificaciones'] = [
      '#type' => 'details',
      '#title' => $this->t('Verificaciones'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    // Agregar verificaciones desde el método separado
    $form['verificaciones'] += $this->performVerifications();

    // Segundo tab: Configuración.
    $form['configuracion'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuración'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $form['configuracion']['enable_clave'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activar el uso de Cl@ve en el portal'),
      '#default_value' => $config->get('enable_clave') ?? 0,
      '#description' => $this->t('Si está marcado, se habilitará el uso de Cl@ve en el sistema.'),
    ];

    $form['configuracion']['force_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fuerza al usuario a logarse siempre en Cl@ve'),
      '#default_value' => $config->get('force_login') ?? 1,
      '#description' => $this->t('Si está marcado, se obligará al usuario siempre a logarse.'),
    ];

    $form['configuracion']['sp_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Identificador del Proveedor de Servicio (SPID)'),
      '#default_value' => $config->get('sp_id') ?? '21114293V_E04975701',
      '#description' => $this->t('Identificador del proveedor de servicio configurado en el kit Cl@ve.'),
      '#required' => TRUE,
    ];

    $form['configuracion']['logout_clave'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Logout en Cl@ve tras logout en Drupal'),
      '#default_value' => $config->get('logout_clave') ?? 1,
      '#description' => $this->t('Si está marcado, se cerrará sesión en Cl@ve tras logout en Drupal.'),
    ];

    $form['configuracion']['sync_field'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sincronizar campos de nombre y apellido en cada inicio de sesión'),
      '#default_value' => $config->get('sync_field') ?? 1,
      '#description' => $this->t('Si está marcado, se sincronizarán los campos de nombre y apellido en cada inicio de sesión.'),
    ];

    $form['configuracion']['loa'] = [
      '#type' => 'select',
      '#title' => $this->t('Nivel LOA'),
      '#default_value' => $config->get('loa') ?? "http://eidas.europa.eu/LoA/low",
      '#options' => [
        "http://eidas.europa.eu/LoA/low" => "Bajo (http://eidas.europa.eu/LoA/low)",
        "http://eidas.europa.eu/LoA/substantial" => "Substancial (http://eidas.europa.eu/LoA/substantial)",
        "http://eidas.europa.eu/LoA/high" => "Alto (http://eidas.europa.eu/LoA/high)",
      ],
      '#description' => $this->t('Seleccione el nivel LOA para la autenticación.'),
    ];
    
    $form['configuracion']['login_literal'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Literal en formulario'),
      '#default_value' => $config->get('login_literal') ?? 'Acceso con Cl@ve',
      '#description' => $this->t('Texto que se mostrará en el formulario de login/registro de Cl@ve.'),
    ];

    // Tercer tab: Registro.
    $roles = \Drupal\user\Entity\Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role) {
      $role_options[$role->id()] = $role->label();
    }
    $form['registro'] = [
      '#type' => 'details',
      '#title' => $this->t('Registro'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $form['registro']['register_usuario'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registrar usuario si no existe'),
      '#default_value' => $config->get('register_usuario') ?? 1,
      '#description' => $this->t('Si está marcado, se creará un usuario nuevo en Drupal cuando no exista.'),
    ];

    $form['registro']['fake_domain_mail'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dominio para el Dominio Falso del mail'),
      '#default_value' => $config->get('fake_domain_mail') ?? 'cl-ve.identity.use',
      '#description' => $this->t('Dominio que se usará para el mail del usuario creado.'),
    ];

    $user_fields_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    $user_fields_options = [];
    $excluded_fields = [
      'uid', 
      'uuid', 
      'langcode', 
      'preferred_langcode', 
      'preferred_admin_langcode', 
      'pass', 
      'timezone', 
      'status', 
      'created', 
      'changed', 
      'access', 
      'login', 
      'init', 
      'roles', 
      'default_langcode', 
      'user_picture'
    ];
    $user_fields_options[''] = $this->t('- No configurado -');
    foreach ($user_fields_definitions as $field_name => $definition) {
      if (!in_array($field_name, $excluded_fields)) {
      $user_fields_options[$field_name] = $definition->getLabel()." ($field_name)";
      }
    }
    asort($user_fields_options);
    
    $form['registro']['id_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo número de documento (debe ser único)'),
      '#default_value' => $config->get('id_field') ?? FIELD_NUMERO_DOCUMENTO,
      '#options' => array_slice($user_fields_options, 1), // Remove empty option for required field
      '#description' => $this->t('Campo con el que se hace match el número de documento, debe ser único.'),
      '#attributes' => [
        'class' => ['field-selector'],
        'data-field-type' => 'id_field',
      ],
    ];

    $form['registro']['nombre_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo para el nombre recibido se guarda en:'),
      '#default_value' => $config->get('nombre_field') ?? '',
      '#options' => $user_fields_options,
      '#description' => $this->t('Nombre del campo en el usuario que almacena el nombre. Dejar vacío si no se desea sincronizar.'),
      '#attributes' => [
        'class' => ['field-selector'],
        'data-field-type' => 'nombre_field',
      ],
    ];

    $form['registro']['apellido_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo para el apellido recibido se guarda en:'),
      '#default_value' => $config->get('apellido_field') ?? '',
      '#options' => $user_fields_options,
      '#description' => $this->t('Nombre del campo en el usuario que almacena el apellido. Dejar vacío si no se desea sincronizar.'),
      '#attributes' => [
        'class' => ['field-selector'],
        'data-field-type' => 'apellido_field',
      ],
    ];

    $form['registro']['assign_roles'] = [
      '#type' => 'select',
      '#title' => $this->t('Roles a asignar al usuario nuevo'),
      '#default_value' => $config->get('assign_roles') ?? ["authenticated"],
      '#multiple' => TRUE,
      '#options' => $role_options,
      '#description' => $this->t('Seleccione los roles a asignar al usuario nuevo.'),
    ];

    $form['exclude_login'] = [
      '#type' => 'details',
      '#title' => $this->t('Excluir login'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];

    $form['exclude_login']['exclude_login_roles'] = [
      '#type' => 'select',
      '#title' => $this->t('No permitir login a los siguientes roles'),
      '#default_value' => $config->get('exclude_login') ?? [],
      '#multiple' => TRUE,
      '#options' => $role_options,
      '#description' => $this->t('Seleccione los roles a excluir del login.'),
    ];


    // Cuarto tab: Idps activados.
    $form['idps'] = [
      '#type' => 'details',
      '#title' => $this->t('Idps activados'),
      '#group' => 'tabs',
      '#open' => TRUE,
    ];
    $form['idps']['AEATIdP'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cl@ve PIN'),
      '#default_value' => $config->get('AEATIdP') ?? 1,
    ];
    $form['idps']['EIDASIdP'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Ciudadanos UE'),
      '#default_value' => $config->get('EIDASIdP') ?? 1,
    ];
    $form['idps']['CLVMOVILIdP'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cl@ve Móvil'),
      '#default_value' => $config->get('CLVMOVILIdP') ?? 1,
    ];
    $form['idps']['AFirmaIdP'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('DNI Electrónico'),
      '#default_value' => $config->get('AFirmaIdP') ?? 1,
    ];
    $form['idps']['GISSIdP'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cl@ve Permantente'),
      '#default_value' => $config->get('GISSIdP') ?? 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    
    
    // Get field values
    $id_field = $form_state->getValue('id_field');
    $nombre_field = $form_state->getValue('nombre_field');
    $apellido_field = $form_state->getValue('apellido_field');
    
    // Create array of non-empty fields
    $selected_fields = [];
    if (!empty($id_field)) {
      $selected_fields['id_field'] = $id_field;
    }
    if (!empty($nombre_field)) {
      $selected_fields['nombre_field'] = $nombre_field;
    }
    if (!empty($apellido_field)) {
      $selected_fields['apellido_field'] = $apellido_field;
    }
    
    // Check for duplicates
    $field_values = array_values($selected_fields);
    $unique_values = array_unique($field_values);
    
    if (count($field_values) !== count($unique_values)) {
      // Find which fields are duplicated
      $duplicates = array_diff_assoc($field_values, $unique_values);
      $duplicate_value = reset($duplicates);
      
      // Set error on the fields that have duplicates
      foreach ($selected_fields as $field_name => $value) {
        if ($value === $duplicate_value) {
          $form_state->setErrorByName($field_name, $this->t('No se puede seleccionar el mismo campo para múltiples opciones. El campo "@field" ya está siendo utilizado.', ['@field' => $value]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('minsait_login_clave.settings')
      ->set('enable_clave', $form_state->getValue('enable_clave'))
      ->set('register_usuario', $form_state->getValue('register_usuario'))
      ->set('fake_domain_mail', $form_state->getValue('fake_domain_mail'))
      ->set('login_literal', $form_state->getValue('login_literal'))
      ->set('force_login', $form_state->getValue('force_login'))
      ->set('sp_id', $form_state->getValue('sp_id'))
      ->set('logout_clave', $form_state->getValue('logout_clave'))
      ->set('sync_field', $form_state->getValue('sync_field'))
      ->set('loa', $form_state->getValue('loa'))
      ->set('id_field', $form_state->getValue('id_field'))
      ->set('nombre_field', $form_state->getValue('nombre_field'))
      ->set('apellido_field', $form_state->getValue('apellido_field'))
      ->set('assign_roles', $form_state->getValue('assign_roles'))
      ->set('exclude_login', $form_state->getValue('exclude_login_roles'))
      ->set('AEATIdP', $form_state->getValue('AEATIdP'))
      ->set('EIDASIdP', $form_state->getValue('EIDASIdP'))
      ->set('CLVMOVILIdP', $form_state->getValue('CLVMOVILIdP'))
      ->set('AFirmaIdP', $form_state->getValue('AFirmaIdP'))
      ->set('GISSIdP', $form_state->getValue('GISSIdP'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
