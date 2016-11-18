<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * 扩展CI的主控制器类
 * 请开发人员自己的页面控制器一定要继承本扩展类，而不要直接继承CI_Controller
 * 因为后续的全系统逻辑，比如页面请求统计
 *
 * @author ARTFANTASY (iscodd@gmail.com)
 * @version 2016.09.28
 */

class MY_Controller  extends CI_Controller {
    function __construct($IsCheck = TRUE)
    {
        parent::__construct();
        if ($IsCheck) {
            $this->CheckSgin();
        }
    }

    public function CheckSgin(){

        $AppId = isset($_SERVER['HTTP_APPID']) ? $_SERVER['HTTP_APPID'] : "";
        $Nonce = isset($_SERVER['HTTP_NONCE']) ? $_SERVER['HTTP_NONCE'] : "";
        $Time = isset($_SERVER['HTTP_TIME']) ? $_SERVER['HTTP_TIME'] : "";
        $Sign = $AppId . $Nonce . $Time;

        if (abs($Time - time()) > 5 ) {
            echo json_encode(array('result' => -99, 'msg' => 'Error TimeOut'));
            exit();
        }


        $redis = Rcache::init();
        if ($Nonce == $redis->get('HTTP:NONCE')) {
            echo json_encode(array('result' => -99, 'msg' => 'Repeat Request'));
            exit();
        }

        $redis->set('HTTP:NONCE', $Nonce);

        $Sign = sha1($Sign);

        $HttpSign = isset($_SERVER['HTTP_SIGN']) ? $_SERVER['HTTP_SIGN'] : '';


        if (!$HttpSign || $Sign != $HttpSign) {
            echo json_encode(array('result' => -99, 'msg' => 'Error Sign'));
            exit();
        }
    }
}