<?php
namespace SPClave\Services;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

/**
 * Clase encargada de controlar las plantillas de Twig
 */
class ViewsService
{

    /**
     * Guarda la configuración de Twig y renderiza las plantillas
     *
     * @var Environment
     */
    private Environment $twig;

    public function __construct()
    {
        $loader = new FilesystemLoader('../templates');
        $this->twig = new Environment($loader);
    }

    /**
     * Recibe la plantilla y los parámetros que tiene que pintar en el twig
     *
     * @param string $template
     * @param array $params
     * @return void
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function render(string $template, array $params = []): void
    {
        echo $this->twig->render($template, $params);
    }
}
