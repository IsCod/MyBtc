<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/**
 * Description of crontab_pay
 *LTC 交易较慢，进行慢交易
 *BTC 交易频繁，进行快交易
 *
 * @author iscod-ning
 */
class Trading extends MY_Controller{

    public function __construct() {
        parent::__construct(FALSE);
        date_default_timezone_set('Asia/Shanghai');
    }

    private $amount = 10;//每单购买的ltc
    private $max_order_num = 1;//最多同时在量的订单
    private $max_deal_num = 1;//未处理订单数大于该值不进行买入
    private $min_balance_cny_num = 1300;//账户中保持最低人民币
    private $cancel_order_time = 3600;//订单多长时间没有交易就取消(默认)
    private $cancel_sell_order_time = 7200;//取消买订单的时间


     //是否下订单
    public function IsPlaceOrder($type = 'all'){
        $result = array('buy' => TRUE, 'sell' => TRUE);

        if ($type == "LTCCNY") $result = array('buy' => TRUE, 'sell' => TRUE);
        if ($type == "BTCCNY") $result = array('buy' => FALSE, 'sell' => TRUE);

        // //这里严重影响性能
        // include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        // $btcAPI = new BTCChinaAPI();
        // // 获取我账户的信息
        // $ret = $btcAPI->getAccountInfo();

        // // 保持最低人民币
        // if ($ret->balance->cny->amount < $this->min_balance_cny_num) $result['buy'] = FALSE;
        return $result;
    }

     /*
    *买订单脚本
    *买入ltc脚本
    */
    public function buyltc(){

        $is_price_order = $this->IsPlaceOrder('LTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();
        if (!$price || !isset($price['buy'])) die('error: get price');

        //已经买入的订单
        $redis = Rcache::init();
        if ($redis->sCard('Trading:Ltc:OrderIds')) die("order nums is max\n");

        //未处理的订单数量
        if($redis->lSize('trans:buy:ltc') > $this->max_deal_num) die("waiting deal order num is max\n");

        $orderId = 0;

        $amount = 1 * rand(80, 120) /100;
        $amount = 0.001;
        $price['buy'] = $price['ask'];
        $orderId = $btcAPI->placeOrder($price['buy'], $amount, 'LTCCNY');

        if (!is_int($orderId) && $orderId) die("error: " . var_dump($orderId));

        $redis->sAdd('Trading:Ltc:OrderIds', $orderId);

        echo "done\n";
    }

    /**
    *卖订单脚本
    *出售ltc脚本
    */
    public function sellltc(){
        $is_price_order = $this->IsPlaceOrder('LTCCNY');
        if(!$is_price_order['sell']) die("done\n");

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $redis = Rcache::init();

        if (count($redis->hGetAll('trans:sell:ltc')) > $this->max_order_num) die("order nums is max\n");

        $tran = $redis->lPop('trans:buy:ltc');
        if (!$tran) die("no trans\n");

        $tran = json_decode($tran);

        //超过交易时间那么就止损
        if ($tran->avg_price > ($price['sell'] && (time() - $tran->date) < $this->cancel_sell_order_time * 12)) {
            $redis->rPush('trans:buy:ltc', json_encode($tran));
            die("price is small\n");
        }

        $amount = $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;//订单量

        //现在最高买的订单大于订单的价格4%的时候下单成交
        if ($price['bid'] > ($tran->avg_price*1.04)) $price['sell'] = $price['bid'];

        $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 3), 'LTCCNY');

        //订单结束
        if (is_int($sell_id) && $sell_id) {
            $redis->hSet('trans:sell:ltc', $sell_id, json_encode($tran));
        }else{
            $redis->rPop('trans:buy:ltc', json_encode($tran));
        }

        echo "done\n";
    }



    public function buybtc(){
        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //加载BtcChinaApi
        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->btc();
        if (!$price || !isset($price['buy'])) die('error: get price');

        //已经买入的订单
        $redis = Rcache::init();

        //当前开放的订单
        if ($redis->sCard('Trading:Btc:OrderIds')) die("order nums is max\n");

        //未处理的订单数量
        if($redis->lSize('trans:buy:btc') > $this->max_deal_num) die("waiting deal order num is max\n");

        $amount = 0.05 * rand(80, 120) /100;

        $amount = round($amount, 4);
        $orderId = 0;
        $orderId = $btcAPI->placeOrder($price['buy'], $amount, 'BTCCNY');

        if (!is_int($orderId) && !$orderId) die("error: " . var_dump($orderId));

        $redis->sAdd('Trading:Btc:OrderIds', $orderId);

        echo "done\n"; 
    }


    public function sellbtc(){
        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['sell']) die("dones\n");

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->btc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        $redis = Rcache::init();

        if (count($redis->hGetAll('trans:sell:btc')) > $this->max_order_num) die("order nums is max\n");

        $tran = $redis->lPop('trans:buy:btc');
        if (!$tran) die("no trans\n");
    
        $tran = json_decode($tran);

        //超过交易时间那么就止损
        if ($tran->avg_price > $price['sell'] && (time() - $tran->date) < $this->cancel_sell_order_time) {
            $redis->rPush('trans:buy:btc', json_encode($tran));
            die("price is small\n");
        }

        $amount = $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;//订单量

        //现在最高买的订单大于订单的价格4%的时候下单成交    
        if ($price['bid'] > ($tran->avg_price*1.01)) $price['sell'] = $price['bid'];

        $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 4), 'BTCCNY');

        //订单结束
        if (is_int($sell_id) && $sell_id) {
            $redis->hSet('trans:sell:btc', $sell_id, json_encode($tran));
        }else{
            $redis->rPop('trans:buy:btc', json_encode($tran));
        }

        echo "done\n";
    }


     //订单处理
    public function orderManage(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $trans =  $btcAPI->getOrders(FALSE, 'ALL', 30);

        $redis = Rcache::init();

        foreach ($trans->order_ltccny as $value) {
            // 买订单处理
            if ($value->type == "bid") {
                //非脚本下单和处理过的订单
                if (!$redis->sIsMember('Trading:Ltc:OrderIds' , $value->id)) continue;

                switch ($value->status) {
                    //取消的订单
                    case 'cancelled':
                        if ($value->amount == 0 || $value->amount == $value->amount_original) continue;
                        $redis->rPush('trans:buy:ltc', json_encode($value));
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->rPush('trans:buy:ltc', json_encode($value));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 90) $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
                        break;

                    default:
                        # code...
                        break;
                }

                if ($value->status != "open") $redis->sRem('Trading:Ltc:OrderIds', $value->id);
            }

            //卖订单处理
            if ($value->type == 'ask') {
                $info = $redis->hGet('trans:sell:ltc', $value->id);
                if (!$info) continue;

                $info = json_decode($info);

                switch ($value->status) {
                    //取消的订单
                    case 'cancelled':
                        if($value->amount_original != $value->amount && $value->amount > 0) $info->amount += $value->amount_original - $value->amount;//交易过的数量

                        $redis->rPush('trans:buy:ltc', json_encode($info));
                        $redis->hDel('trans:sell:ltc', $value->id);
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->hDel('trans:sell:ltc', $value->id);
                        //只用作统计
                        $redis->hSet('trans:sell:ltc:all:deal', $info->id, json_encode($info));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 120) $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
                        break;

                    default:
                        # code...
                        break;
                }
            }

        }

        foreach ($trans->order_btccny as $value) {
            //买订单处理
            if ($value->type == "bid") {
                //非脚本下单和处理过的订单
                if (!$redis->sIsMember('Trading:Btc:OrderIds' , $value->id)) continue;

                switch ($value->status) {
                    //取消的订单
                    case 'cancelled':
                        if ($value->amount == 0 || $value->amount == $value->amount_original) continue;
                        $redis->rPush('trans:buy:btc', json_encode($value));
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->rPush('trans:buy:btc', json_encode($value));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 90) $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                        break;

                    default:
                        # code...
                        break;
                }

                if ($value->status != "open") $redis->sRem('Trading:Btc:OrderIds', $value->id);

            }

            //卖订单处理
            if ($value->type == 'ask') {
                $info = $redis->hGet('trans:sell:btc', $value->id);
                if (!$info) continue;

                $info = json_decode($info);

                switch ($value->status) {
                    //取消的订单
                    case 'cancelled':
                        if($value->amount_original != $value->amount && $value->amount > 0) $info->amount += $value->amount_original - $value->amount;//交易过的数量

                        $redis->rPush('trans:buy:btc', json_encode($info));
                        $redis->hDel('trans:sell:btc', $value->id);
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->hDel('trans:sell:btc', $value->id);
                        //只用作统计
                        $redis->hSet('trans:sell:btc:all:deal', $info->id, json_encode($info));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 120) $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                        break;

                    default:
                        # code...
                        break;
                }
            }
        }

        echo "done\n";
    }

    //谨慎操作
    public function reverse(){
        die("Warning!!!!!! \nAre you sure?\nPlease amend file!\n");
        $redis = Rcache::init();
        $redis->delete('trans:buy:btc');
        $redis->delete('trans:sell:btc');
        $redis->delete('Trading:Btc:OrderIds');
        $redis->delete('trans:sell:btc:all:deal');

        $redis->delete('trans:buy:ltc');
        $redis->delete('trans:sell:ltc');
        $redis->delete('Trading:Btc:OrderIds');
        $redis->delete('trans:sell:ltc:all:deal');
        echo "done\n";
    }
}