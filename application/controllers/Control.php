<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/**
 * Description of crontab_pay
 *
 * @author iscod-ning
 */
class Control extends MY_Controller{
    public function __construct() {
        parent::__construct(TRUE);
        date_default_timezone_set('Asia/Shanghai');
    }

    /*
    *获取交易开关状态
    */
    public function getTrading(){
        $redis = Rcache::init();
        $TradingSwitch = $redis->hGetAll('TradingSwitch');

        $data['all'] = isset($TradingSwitch['all']) ? (int)$TradingSwitch['all'] : 0;
        $data['buy'] = isset($TradingSwitch['buy']) ? (int)$TradingSwitch['buy'] : 0;
        $data['sell'] = isset($TradingSwitch['sell']) ? (int)$TradingSwitch['sell'] : 0;

        header('Content-type: text/json');
        echo json_encode(array('result' => 1, 'msg' => 'ok', 'data' => $data));
        return true;
    }

    /*
    *设置交易开关
    */
    public function SetTradingState(){
        $state = (int)$this->input->post('state');
        $type = (string)$this->input->post('type');

        header('Content-type: text/json');
        if (!in_array($state, array(0,1))) {
            echo json_encode(array('result' => -97, 'msg' => 'error state'));
            return false;
        }

        if (!in_array($type, array('all', 'buy' , 'sell'))) {
            echo json_encode(array('result' => -98, 'msg' => 'error type'));
            return false;
        }

        $redis = Rcache::init();
        $return = $redis->hSet('TradingSwitch', $type, $state);

        $state = $redis->hGet('TradingSwitch', $type);

        $data = array('type' =>$type, 'state' => $state);

        echo json_encode(array('result' => 1, 'msg' => 'ok', 'data' => $data));
        return true;
    }
}