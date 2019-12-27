<?php

namespace Modules\Retro;

use Modules\Interfaces\ModuleInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ModuleController extends Parser implements ModuleInterface
{
    public function page(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $args = $this->prepare($args);
        $args = $this->controller($args);

        $template = filter('modal') !== null ? 'add' : 'controller';

        return $this->twig->render($response, 'old/'.$template.'.twig', $args);
    }

    public function content(array $args)
    {
        $args = $this->prepare($args);
        $args = $this->controller($args);

        return $args['content'];
    }

    public function add(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $args = $this->prepare($args);
        $args = parent::add($args);

        return $this->twig->render($response, 'old/add.twig', $args);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $id_record = $this->actions($args);
        $params = [
            'record_id' => $id_record,
        ];

        if (!isAjaxRequest()) {
            $path = $args['module']->url('record', $params);

            $response = $response->withRedirect($path);
        }

        return $response;
    }
}
