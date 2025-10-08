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
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('minsait_login_clave.settings');
    $kitClavePath = DRUPAL_ROOT . '/../vendor/simplesamlphp';

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

    if (!is_dir($kitClavePath)) {
      $form['verificaciones']['saml_status'] = [
        '#type' => 'markup',
        '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">❌ '
          . $this->t('La carpeta del Kit Cl@ve no existe en @path.', ['@path' => $kitClavePath])
          . '</div>',
      ];
    }
    else {
      if (is_readable($kitClavePath)) {
        $icon = '✅';
        $message = $this->t('@icon El Kit Cl@ve está instalado y con permisos de lectura.', ['@icon' => $icon]);
      }
      else {
        $icon = '⚠';
        $message = $this->t('@icon La carpeta del Kit Cl@ve existe pero sin permisos de lectura.', ['@icon' => $icon]);
      }
      $form['verificaciones']['saml_status'] = [
        '#type' => 'markup',
        '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">'
          . $message . '</div>',
      ];
    }

    $checks = [
      $kitClavePath . '/vendor/autoload.php' => $this->t('Autoloader principal del kit (vendor/autoload.php).'),
      $kitClavePath . '/simplesamlphp/vendor/autoload.php' => $this->t('Autoloader de SimpleSAMLphp (simplesamlphp/vendor/autoload.php).'),
      $kitClavePath . '/simplesamlphp/lib/_autoload.php' => $this->t('Autoloader principal de SimpleSAMLphp (simplesamlphp/lib/_autoload.php).'),
      $kitClavePath . '/simplesamlphp/config/config.php' => $this->t('Fichero de configuración (simplesamlphp/config/config.php).'),
    ];

    foreach ($checks as $path => $description) {
      $message = '❌ ' . $this->t('No se encontró @desc en @path.', ['@desc' => $description, '@path' => $path]);
      if (file_exists($path)) {
        if (is_readable($path)) {
          $message = $this->t('✅ @desc localizado y con permisos de lectura.', ['@desc' => $description]);
        }
        else {
          $message = $this->t('⚠ @desc localizado pero sin permisos de lectura.', ['@desc' => $description]);
        }
      }

      $form['verificaciones']['check_' . md5($path)] = [
        '#type' => 'markup',
        '#markup' => '<div style="border:1px solid #ccc; padding:8px; margin-bottom:20px;">' . $message . '</div>',
      ];
    }

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
    foreach ($user_fields_definitions as $field_name => $definition) {
      if (!in_array($field_name, $excluded_fields)) {
      $user_fields_options[$field_name] = $definition->getLabel()." ($field_name)";
      }
    }
    asort($user_fields_options);
    
    $form['registro']['id_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo número de documento'),
      '#default_value' => $config->get('id_field') ?? FIELD_NUMERO_DOCUMENTO,
      '#options' => $user_fields_options,
      '#description' => $this->t('Campo con el que se hace match el número de documento, debe ser único.'),
    ];

    $form['registro']['nombre_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo para el nombre'),
      '#default_value' => $config->get('nombre_field') ?? 'field_nombre',
      '#options' => $user_fields_options,
      '#description' => $this->t('Nombre del campo en el usuario que almacena el nombre.'),
    ];

    $form['registro']['apellido_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Campo para el apellido'),
      '#default_value' => $config->get('apellido_field') ?? 'field_apellido_1',
      '#options' => $user_fields_options,
      '#description' => $this->t('Nombre del campo en el usuario que almacena el apellido.'),
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
      ->set('AEATIdP', $form_state->getValue('AEATIdP'))
      ->set('EIDASIdP', $form_state->getValue('EIDASIdP'))
      ->set('CLVMOVILIdP', $form_state->getValue('CLVMOVILIdP'))
      ->set('AFirmaIdP', $form_state->getValue('AFirmaIdP'))
      ->set('GISSIdP', $form_state->getValue('GISSIdP'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
