<?php

    namespace App\Controllers;
    use App\Library\Controller;

    class SiteController extends Controller {


        public function index() {
            return $this->view::render('hey');
        }
    }