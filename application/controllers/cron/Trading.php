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

    private $max_order_num = 1;//最多同时在量的订单
    private $max_deal_num = 1;//未处理订单数大于该值不进行买入
    private $min_balance_cny_num = 800;//账户中保持最低人民币
    private $BuySellPro = 0.8;//先买策略与先卖策略比重buy/sell
    private $amount = 0.04;//默认订单量


     //是否下订单
    public function IsPlaceOrder($type = 'all'){
        $result = array('buy' => TRUE, 'sell' => TRUE);

        if ($type == "LTCCNY") $result = array('buy' => FALSE, 'sell' => FALSE);
        if ($type == "BTCCNY") $result = array('buy' => TRUE, 'sell' => TRUE);

        // //这里严重影响性能
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();
        // 获取我账户的信息
        $ret = $btcAPI->getAccountInfo();

        // 保持最低人民币 通过客户端远程控制是否交易下一个较大的单使用户少于最低人民币
        if ($ret->balance->cny->amount < $this->min_balance_cny_num) {
            $result['buy'] = FALSE;
            // $result['sell'] = FALSE;
        }

        return $result;
    }

     /*
    *买订单脚本
    *买入ltc脚本
    */
    public function buyltc(){

        $is_price_order = $this->IsPlaceOrder('LTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        //当前开放的订单
        $openOrders = $this->openOrders('LTCCNY');
        if (count($openOrders['bid']) >= $this->max_order_num) die("order nums is max\n");

        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('GetPirce_model');
        $price = $this->GetPirce_model->Ltc();
        if (!$price || !isset($price['buy'])) die('error: get price');

        //已经买入的订单
        $redis = Rcache::init();
        if ($redis->sCard('Trading:Ltc:OrderIds') > 1) die("order nums is max\n");

        //未处理的订单数量
        if($redis->lSize('trans:buy:ltc') > $this->max_deal_num) die("waiting deal order num is max\n");

        $orderId = 0;

        $price['buy'] = $price['ask'];
        $orderId = $btcAPI->placeOrder($price['buy'], $this->amount * 100, 'LTCCNY');

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

        $this->load->model('GetPirce_model');
        $price = $this->GetPirce_model->Ltc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $redis = Rcache::init();

        if (count($redis->hGetAll('trans:sell:ltc')) > $this->max_order_num) die("order nums is max\n");

        $tran = $redis->lPop('trans:buy:ltc');
   
        if (!$tran) die("no trans\n");

        $tran = unserialize($tran);

        //超过交易时间那么就止损
        if ($tran->avg_price > $price['sell'] && time() - $tran->date < 3600 * 12) {
            $redis->rPush('trans:buy:ltc', serialize($tran));
            die("price is small\n");
        }

        $amount = $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;//订单量

        //现在最高买的订单大于订单的价格4%的时候下单成交
        if ($price['bid'] > ($tran->avg_price*1.04)) $price['sell'] = $price['bid'];

        $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 3), 'LTCCNY');

        //订单结束
        if (is_int($sell_id) && $sell_id) {
            $redis->hSet('trans:sell:ltc', $sell_id, serialize($tran));
        }else{
            $redis->rPush('trans:buy:ltc', serialize($tran));
        }

        echo "done\n";
    }

    public function buybtc(){
        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        $this->load->model('GetPirce_model');
        $price = $this->GetPirce_model->Btc();
        if (!$price || !isset($price['buy'])) die('error: get price');

        //已经买入的订单
        $redis = Rcache::init();

        $stop_loss_ttl = $redis->ttl('sell:btc:stop:loss');
        if ($stop_loss_ttl > 0) $price['buy'] = 0.995 * $price['buy'];

        $orderId = 0;
        //先买策略
        $buy_deal_num = (int)$redis->sCard('Trading:Btc:OrderIds') + (int)$redis->lSize('trans:buy:btc') + count($redis->hGetAll('trans:sell:btc'));

        if ($buy_deal_num < $this->max_deal_num) {
            $orderId = $btcAPI->placeOrder($price['buy'], $this->amount, 'BTCCNY');

            if (is_int($orderId) && $orderId > 0) {
                $redis->sAdd('Trading:Btc:OrderIds', $orderId);
                echo "First buy ok\n";
            }

        }elseif($buy_deal_num < $this->max_deal_num + 1){
            $orderId = $btcAPI->placeOrder($price['buy'] * 0.92, $this->amount, 'BTCCNY');

            if (is_int($orderId) && $orderId > 0) {
                $redis->sAdd('Trading:Btc:OrderIds', $orderId);
                echo "Two buy ok\n";   
            }
        }else{
            echo "First buy done\n";
        }



        $orderId = 0;
        //先卖策略
        if (count($redis->hGetAll('reverse:trans:buy:btc')) < $this->max_order_num) {
            $tran = $redis->lPop('reverse:trans:sell:btc');

            if ($tran) {
                $tran = unserialize($tran);
                if ($tran->avg_price < $price['buy']) {
                    $redis->rPush('reverse:trans:sell:btc', serialize($tran));
                }else{

                    $amount = $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;//订单量

                    //现在最高买的订单低于卖订单的价格4%的时候下单成交    
                    if ($price['ask'] < ($tran->avg_price*0.96)) $price['buy'] = $price['ask'];

                    $orderId = $btcAPI->placeOrder($price['buy'], round(($amount  * 100)/100 , 4), 'BTCCNY');

                    //订单结束
                    if (is_int($orderId) && $orderId > 0) {
                        $redis->hSet('reverse:trans:buy:btc', $orderId, serialize($tran));
                        echo "First sell ok\n";
                    }else{
                        $redis->rPush('reverse:trans:sell:btc', serialize($tran));
                    }
                }
            }
        }else{
            echo "First sell done\n";
        }

        echo "done\n"; 
    }

    public function sellbtc(){
        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['sell']) die("dones\n");

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('GetPirce_model');
        $price = $this->GetPirce_model->Btc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        $redis = Rcache::init();

        $orderId = 0;
        //先买策略
        if (count($redis->hGetAll('trans:sell:btc')) < $this->max_order_num) {
            $tran = $redis->lPop('trans:buy:btc');
            if ($tran) {
                $tran = unserialize($tran);

                //超过交易时间那么就止损
                if ($tran->avg_price > $price['sell'] && (time() - $tran->date) < 60 * 10) {
                    $redis->rPush('trans:buy:btc', serialize($tran));
                }else{
                    if(time() - $tran->date > 60 * 10) $redis->setex('sell:btc:stop:loss', 60, 1);
                    $amount = $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;//订单量

                    //现在最高买的订单大于订单的价格0.1%的时候下单成交
                    if ($price['bid'] > ($tran->avg_price*1.001)) $price['sell'] = $price['bid'];

                    $orderId = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 4), 'BTCCNY');

                    //订单结束
                    if (is_int($orderId) && $orderId > 0) {
                        $redis->hSet('trans:sell:btc', $orderId, serialize($tran));
                        echo "First buy ok\n";
                    }else{
                        $redis->rPush('trans:buy:btc', serialize($tran));
                    }
                }
            }
        }else{
            echo "First buy is done\n";
        }


        $sell_btc_num = (int)$redis->sCard('Reverse:Trading:Btc:OrderIds') + (int)$redis->lSize('reverse:trans:sell:btc') + count($redis->hGetAll('reverse:trans:buy:btc'));

        //先卖策略
        /*
        if ($sell_btc_num < $this->max_deal_num) {
            $orderId = $btcAPI->placeOrder($price['sell'], -$this->amount, 'BTCCNY');
            if (is_int($orderId) && $orderId > 1) {
                $redis->sAdd('Reverse:Trading:Btc:OrderIds', $orderId);
                echo "First sell ok\n";    
            }
        }else{
            echo "First sell is done\n";
        }
        */

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
                        if ($value->amount == $value->amount_original) continue;
                        $redis->rPush('trans:buy:ltc', serialize($value));
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->rPush('trans:buy:ltc', serialize($value));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 3600) $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
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

                $info = unserialize($info);

                switch ($value->status) {
                    //取消的订单
                    case 'cancelled':
                        $info->amount += $value->amount_original - $value->amount;
                        if ($info->amount < $info->amount_original) $redis->rPush('trans:buy:ltc', serialize($info));

                        $redis->hDel('trans:sell:ltc', $value->id);
                        break;

                    //成交的订单
                    case 'closed':
                        $redis->hDel('trans:sell:ltc', $value->id);
                        //只用作统计
                        // $redis->hSet('trans:sell:ltc:all:deal', $info->id, serialize($info));
                        break;

                    //开放的订单
                    case 'open':
                        if((time() - $value->date) > 3600) $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
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

                //先买策略
                if ($redis->sIsMember('Trading:Btc:OrderIds' , $value->id)) {

                    switch ($value->status) {
                        //取消的订单
                        case 'cancelled':
                            if ($value->amount == $value->amount_original) continue;
                            $redis->rPush('trans:buy:btc', serialize($value));
                            break;

                        //成交的订单
                        case 'closed':
                            $redis->rPush('trans:buy:btc', serialize($value));
                            break;

                        //开放的订单
                        case 'open':
                            if((time() - $value->date) > 60) $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                            break;

                        default:
                            # code...
                            break;
                    }

                    if ($value->status == "cancelled" || $value->status == "closed") $redis->sRem('Trading:Btc:OrderIds', $value->id);
                }

                //先卖策略
                $info = $redis->hGet('reverse:trans:buy:btc', $value->id);
                if ($info) {
                    $info = unserialize($info);

                    switch ($value->status) {
                        //取消的订单
                        case 'cancelled':
                            $info->amount += $value->amount_original - $value->amount;
                            if($info->amount < $info->amount_original) $redis->rPush('reverse:trans:sell:btc', serialize($info));
                            $redis->hDel('reverse:trans:buy:btc', $value->id);
                            break;

                        //成交的订单
                        case 'closed':
                            $redis->hDel('reverse:trans:buy:btc', $value->id);
                            //只用作统计
                            // $redis->hSet('reverse:trans:buy:btc:all:deal', $info->id, serialize($info));
                            break;

                        //开放的订单
                        case 'open':
                            if((time() - $value->date) > 90) $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                            break;

                        default:
                            # code...
                            break;
                    }
                }
            }

            //卖订单处理
            if ($value->type == 'ask') {
                //先买策略
                $info = $redis->hGet('trans:sell:btc', $value->id);
                if ($info) {
                    $info = unserialize($info);

                    switch ($value->status) {
                        //取消的订单
                        case 'cancelled':
                            $info->amount += $value->amount_original - $value->amount;
                            if($info->amount < $info->amount_original) $redis->rPush('trans:buy:btc', serialize($info));
                            $redis->hDel('trans:sell:btc', $value->id);
                            break;

                        //成交的订单
                        case 'closed':
                            $redis->hDel('trans:sell:btc', $value->id);
                            //只用作统计
                            // $redis->hSet('trans:sell:btc:all:deal', $info->id, serialize($info));
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

                //先卖策略
                if ($redis->sIsMember('Reverse:Trading:Btc:OrderIds' , $value->id)) {
                    switch ($value->status) {

                        //取消的订单
                        case 'cancelled':
                            if ($value->amount == $value->amount_original) continue;
                            $redis->rPush('reverse:trans:sell:btc', serialize($value));
                            break;

                        //成交的订单
                        case 'closed':
                            $redis->rPush('reverse:trans:sell:btc', serialize($value));
                            break;

                        //开放的订单
                        case 'open':
                            if((time() - $value->date) > 90) $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                            break;

                        default:
                            # code...
                            break;
                    }
                    if ($value->status == "cancelled" || $value->status == "closed") $redis->sRem('Reverse:Trading:Btc:OrderIds', $value->id);
                }
            }
        }

        echo "done\n";
    }

    /**
    * 当前开放订单信息
    *@param $type LTCCNY || BTCCNY || LTCCNY
    *@param return array('ask' => array(), 'bid' => array())
    */
    public function openOrders($type = 'LTCCNY'){
        $return = array('ask' => array(), 'bid' => array());
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();
        $orders = $btcAPI->getOrders(true, $type);

        foreach ($orders->order as $key => $value) {
            if($value->type == 'ask'){
                $return['ask'][] = $value;
            }
            if ($value->type == 'bid') {
                $return['bid'][] = $value;
            }
        }

        return $return;
    }

    //谨慎操作
    public function reverse(){
        die("Warning!!!!!! \nAre you sure?\nPlease amend file!\n");
        $redis = Rcache::init();

        $redis->delete('Trading:Ltc:OrderIds');
        $redis->delete('trans:buy:ltc');
        $redis->delete('trans:sell:ltc');
        $redis->delete('trans:sell:ltc:all:deal');

        $redis->delete('trans:buy:btc');
        $redis->delete('trans:sell:btc');
        $redis->delete('Trading:Btc:OrderIds');
        $redis->delete('trans:sell:btc:all:deal');

        $redis->delete('reverse:trans:buy:btc');
        $redis->delete('reverse:trans:sell:btc');
        $redis->delete('Reverse:Trading:Btc:OrderIds');
        $redis->delete('reverse:trans:buy:btc:all:deal');
        echo "done\n";
    }

    /**
    *删除统计
    */
    public function deleteDeal(){
        $redis = Rcache::init();
        $redis->delete('trans:sell:btc:all:deal');
        $redis->delete('trans:sell:ltc:all:deal');
        echo "done\n";
    }
}