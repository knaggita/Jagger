<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}


class Awaitinglist extends MY_Controller
{

    public function __construct() {
        parent::__construct();
    }

    public function index() {
        if (!$this->jauth->isLoggedIn()) {
            redirect('auth/login', 'location');
        }
        try {
            $this->load->library(array('zacl', 'j_queue'));
        } catch (Exception $e) {
            log_message('error', __METHOD__ . ' ' . $e);
            set_status_header(500);
            echo 'Internal server error';

            return;
        }
        $this->title = lang('rr_listawaiting');
        $data = array(
            'titlepage'    => lang('rr_listawaiting'),
            'content_view' => 'reports/awaiting_view',
            'breadcrumbs'  => array(
                array('url' => '#', 'name' => lang('rr_listawaiting'), 'type' => 'current'),
            )
        );
        $this->load->view(MY_Controller::$page, $data);
    }

}
