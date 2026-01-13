<?php

namespace App\Controllers\v1;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class HeartBeat extends BaseController
{
    /**
     * method for Get
     *
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setBody('status:200,Gateway is lived')
            ->setContentType('text/plain');
    }
    
}
