<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tmp extends CI_Controller {
    public function __construct() {
        parent::__construct(TRUE);
        date_default_timezone_set('Asia/Shanghai');
    }
}