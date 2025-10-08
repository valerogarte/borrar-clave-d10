<?php
namespace SPClave\Controllers;

use DOMException;
use Exception;
use SPClave\Services\ViewsService;
use SimpleSAML;
use SimpleSAML\Configuration;
use SimpleSAML\Error\AuthSource;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Clase encargada de controlar las páginas y las rutas
 */
class PageController
{

    /**
     * ID del SP
     *
     * @var
     */
    private $spID;

    /**
     * AuthSimple de SimpleSAML
     *
     * @var
     */
    private $as;

    /**
     * Url base de la aplicación
     *
     * @var
     */
    private $assertionUrl;

    /**
     * Servicio encargado de las plantillas twig
     *
     * @var ViewsService
     */
    private ViewsService $viewsService;

    /**
     * Controlador encargado de la autenticación con SimpleSAML
     *
     * @var AuthnController
     */
    private AuthnController $authnController;

    public function __construct()
    {
        $this->authnController = new AuthnController();
        $this->viewsService = new ViewsService();
    }

    /**
     * Ruta home
     *
     * @throws AuthSource
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     * @throws Exception
     */
    public function home(): void
    {
        // Se guarda en la sesión de SimpleSAML el source seleccionado por el usuario
        $session = SimpleSAML\Session::getSessionFromRequest();
        $session->cleanup();
        $session->deleteData('string', 'spid');

        // Se carga el source por defecto por si el usuario no selecciona ninguno
        $config = Configuration::getConfig('config.php');
        $spID = $config->getString('DEFAULT_SPID');

        if (isset($_GET['source'])) {
            $spID = $_GET['source'];
            $session->setData('string', 'spid', $spID);
        }

        $as = new SimpleSAML\Auth\Simple($spID);

        $spSource = $as->getAuthSource();

        $this->spID = $spID;
        $this->as = new SimpleSAML\Auth\Simple($spID);
        $this->assertionUrl = $config->getString('ASSERTION_URL');

        $sources = SimpleSAML\Auth\Source::getSources();
        $idp = $spSource->getMetadata()->getString('idp');
        $assertion_url = $this->assertionUrl;

        $this->viewsService->render('home.twig', [
            'spid' => $spID,
            'sources' => $sources,
            'idp' => $idp,
            'assertion_url' => $assertion_url
        ]);
    }

    /**
     * Ruta de login
     *
     * @throws DOMException
     */
    public function login(): void
    {
        $this->authnController->login($_POST);
    }

    /**
     * Ruta del perfil donde se muestran los datos del usuario
     *
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function profile(): void
    {
        if (! $this->authnController->isAuthenticated()) {
            header('Location: /');
            exit();
        }

        $attributes = $this->authnController->getUserAttributes();

        $this->viewsService->render('profile.twig', [
            'attributes' => $attributes
        ]);
    }

    /**
     * Ruta que muestra el error devuelto por Pasarela Clave
     *
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function error(): void
    {
        $errorTitle = "Ha ocurrido un error";
        $errorMessage = "Error desconocido";

        foreach ($_GET as $getVar) {
            $state = SimpleSAML\Auth\State::loadExceptionState($getVar);
            if (isset($state['\SimpleSAML\Auth\State.exceptionData'])) {
                $errorMessage = $state['\SimpleSAML\Auth\State.exceptionData']->getStatusMessage();
            }
        }

        $this->viewsService->render('error.twig', [
            'errorTitle' => $errorTitle,
            'errorMessage' => $errorMessage
        ]);
    }

    /**
     * Ruta de logout
     *
     * @throws AuthSource
     */
    public function logout(): void
    {
        $this->authnController->logout();
    }
}
