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

    private $market = 'LTCCNY';
    private $amount = 10;//每单购买的ltc
    private $max_order_num = 1;//最多同时在量的订单
    private $min_balance_cny_num = 1400;//账户中保持最低人民币
    
    private $cancel_order_time = 1200;//订单多长时间没有交易就取消(默认)
    private $cancel_sell_order_time = 1800;//取消买订单的时间

    /*
    *买订单脚本
    *买入ltc脚本
    */
    public function buyltc(){

        $is_price_order = $this->IsPlaceOrder('LTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        //当前开放的订单
        $openOrders = $this->openOrders();
        if (count($openOrders['bid']) >= $this->max_order_num) die("order nums is max\n");


        //已经买入的订单
        $redis = Rcache::init();
        if (count($redis->hGetAll('trans:buyltc')) > 4) {
            echo "waiting deal order num is max\n";
            return true;
        }

        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();

        $orderId = 0;

        $amount = $this->amount * rand(80, 120) /100;
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

        //当前开放的订单
        $openOrders = $this->openOrders();
        if (count($openOrders['ask']) >= $this->max_order_num) die("order nums is max\n");

        $redis = Rcache::init();
        $trans = $redis->hGetAll('trans:buyltc');//预处理列表
        if (!count($trans)) die("no trans\n");

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $i = 0;

        foreach ($trans as $value) {
            if ($i++ > 2) continue;
            $value = json_decode($value);
            $value = json_decode($redis->hGet('trans:buyltc', $value->id));
            //已在处理中列表
            if ($value->is_take || $value->avg_price > ($price['sell'] - 0.05)) continue;

            $amount = $value->amount ? $value->amount_original - $value->amount : $value->amount_original;//订单量

            //现在最高买的订单大于订单的价格4%的时候下单成交
            if ($price->bid > ($value->avg_price*1.04)) $price['sell'] = $price->bid;

            $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 3), 'LTCCNY');

            if (is_int($sell_id) && $sell_id) {
                $value->is_take = 1;//处理中状态
                $redis->hSet('trans:buyltc', $value->id, json_encode($value));
                $redis->hSet('trans:sellltc', $sell_id, $value->id);
            }
        }

        echo "done\n";
    }

    public function buybtc(){

        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['buy']) die("done\n");

        //当前开放的订单
        $openOrders = $this->openOrders('BTCCNY');
        if (count($openOrders['bid']) >= $this->max_order_num) die("order nums is max\n");

        //已经买入的订单
        $redis = Rcache::init();
        if (count($redis->hGetAll('trans:buybtc')) > 5) die("waiting deal order num is max\n");

        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->btc();

        $orderId = 0;

        $amount = 0.04 * rand(80, 120) /100;
        $amount = round($amount, 4);

        $orderId = $btcAPI->placeOrder($price['buy'], $amount, 'BTCCNY');

        if (!is_int($orderId) && $orderId) die("error: " . var_dump($orderId));

        $redis->sAdd('Trading:Btc:OrderIds', $orderId);

        echo "done\n"; 
    }

     public function sellbtc(){

        $is_price_order = $this->IsPlaceOrder('BTCCNY');
        if(!$is_price_order['sell']) die("done\n");

        //当前开放的订单
        $openOrders = $this->openOrders('BTCCNY');
        $openOrders = $this->openOrders();
        if (count($openOrders['ask']) >= $this->max_order_num) die("order nums is max\n");


        $redis = Rcache::init();
        $trans = $redis->hGetAll('trans:buybtc');//预处理列表
        if (!count($trans)) die("no trans\n");

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->btc();
        if (!$price || !isset($price['sell'])) die('error: get price');

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $i = 0;

        foreach ($trans as $value) {
            if ($i++ > 1) continue;

            $value = json_decode($value);
            $value = json_decode($redis->hGet('trans:buybtc', $value->id));

            //已在处理中列表
            if ($value->is_take) continue;

            if ($value->avg_price > $price['sell'] && (time() - $value->date) < (int)$value->avg_price) continue;

            $amount = $value->amount ? $value->amount_original - $value->amount : $value->amount_original;//订单量

            //现在最高买的订单大于订单的价格4%的时候下单成交
            if ($price->bid > ($value->avg_price*1.01)) $price['sell'] = $price->bid;


            $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 4), 'BTCCNY');

            if (is_int($sell_id) && $sell_id) {
                $value->is_take = 1;//处理中状态
                $redis->hSet('trans:buybtc', $value->id, json_encode($value));
                $redis->hSet('trans:sellbtc', $sell_id, $value->id);
            }
        }

        echo "done\n";
    }

    //是否下订单
    public function IsPlaceOrder($type = 'all'){

        // $redis = Rcache::init();
        // $result = (bool)$redis->hGet('TradingSwitch', 'all');
        // $result['buy'] = (bool)$redis->hGet('TradingSwitch', 'buy');
        // $result['sell'] = (bool)$redis->hGet('TradingSwitch', 'sell');

        $result = array('buy' => TRUE, 'sell' => TRUE);

        if ($type == "LTCCNY") $result = array('buy' => TRUE, 'sell' => FALSE);
        if ($type == "BTCCNY") $result = array('buy' => TRUE, 'sell' => TRUE);

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();
        //获取我账户的信息
        $ret = $btcAPI->getAccountInfo();

        //保持最低人民币
        if ($ret->balance->cny->amount < $this->min_balance_cny_num) $return['buy'] = FALSE;

        return $result;
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

    //订单处理
    public function orderManage(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $trans =  $btcAPI->getOrders(FALSE, 'ALL', 30);

        $redis = Rcache::init();
        // $redis->delete('trans:sellltc');
        // $redis->delete('trans:buyltc');
        // $redis->delete('trans:buyltc:deal');
        // $redis->delete('Trading:Ltc:OrderIds');
        foreach ($trans->order_ltccny as $value) {

            if (!$redis->sIsMember('Trading:Ltc:OrderIds' , $value->id) && !$redis->hGet('trans:sellltc', $value->id)) continue;

            switch ($value->status) {
                //开放的订单
                case 'open':
                    $cancel_order_time = $value->type == 'bid' ? $this->cancel_order_time : $this->cancel_sell_order_time;
                    if((time() - $value->date) > $cancel_order_time) {
                        $return = $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
                    }
                    break;
                //取消的订单
                case 'cancelled':
                    if ($value->type == 'ask') {
                        $id = $redis->hGet('trans:sellltc', $value->id);

                        if (!$id)  continue;

                        $info = $redis->hGet('trans:buyltc', $id);
                        $info = json_decode($info);
                        $info->is_take = 0;//恢复到未处理的状态
                        //部分交易的订单需要处理
                        if($value->amount_original != $value->amount && $value->amount > 0){
                            $info->amount += $value->amount_original - $value->amount;//交易过的数量
                        }

                        $redis->hSet('trans:buyltc', $id, json_encode($info));//更新订单信息
                        $redis->hDel('trans:sellltc', $value->id);
                    }

                    if ($value->type == "bid") {
                        if($value->amount > 0 && $value->amount != $value->amount_original){
                            if ($redis->sIsMember('trans:buyltc:deal', $value->id) || $redis->hGet('trans:buyltc', $value->id)) {
                                continue;
                            }

                            $value->is_take = 0;//进入预处理状态
                            //未处理进入预处理列表
                            $redis->hSet('trans:buyltc', $value->id, json_encode($value));
                        }
                    }

                    break;

                //成交的订单
                case 'closed':
                        //买的订单
                        if ($value->type == 'bid') {
                            //是否处理过
                            if ($redis->sIsMember('trans:buyltc:deal', $value->id) || $redis->hGet('trans:buyltc', $value->id)) {
                                continue;
                            }

                            $value->is_take = 0;//进入预处理状态
                            //未处理进入预处理列表
                            $redis->hSet('trans:buyltc', $value->id, json_encode($value));
                        }

                        //卖的订单
                        if ($value->type == 'ask') {
                             //查询卖单对应的买单
                            $id = (int)$redis->hGet('trans:sellltc', $value->id);

                            $redis->hDel('trans:sellltc', $value->id);
                            $redis->hDel('trans:buyltc', $id);
                            $redis->sAdd('trans:buyltc:deal', $id);//记录处理过的订单
                        }
                    break;
                default:
                    # code...
                    break;
            }
        }

        // $redis->delete('Trading:Btc:OrderIds');
        // $redis->delete("trans:buybtc");
        // $redis->delete("trans:sellbtc");
        // $redis->delete('Trading:Btc:OrderIds');
        foreach ($trans->order_btccny as $value) {
            if (!$redis->sIsMember('Trading:Btc:OrderIds' , $value->id) && !$redis->hGet('trans:sellbtc', $value->id)) continue;

            switch ($value->status) {
                //开放的订单
                case 'open':
                    $cancel_order_time = $value->type == 'bid' ? 90 : 120;
                    if((time() - $value->date) > $cancel_order_time) {
                        $return = $btcAPI->cancelOrder((int)$value->id, 'BTCCNY');
                    }
                    break;
                //取消的订单
                case 'cancelled':
                    if ($value->type == 'ask') {
                        $id = $redis->hGet('trans:sellbtc', $value->id);

                        if (!$id)  continue;

                        $info = $redis->hGet('trans:buybtc', $id);
                        $info = json_decode($info);
                        $info->is_take = 0;//恢复到未处理的状态
                        //部分交易的订单需要处理
                        if($value->amount_original != $value->amount && $value->amount > 0){
                            $info->amount += $value->amount_original - $value->amount;//交易过的数量
                        }

                        $redis->hSet('trans:buybtc', $id, json_encode($info));//更新订单信息
                        $redis->hDel('trans:sellbtc', $value->id);
                    }

                    if ($value->type == "bid") {
                        if($value->amount > 0 && $value->amount != $value->amount_original){
                            if ($redis->sIsMember('trans:buybtc:deal', $value->id) || $redis->hGet('trans:buybtc', $value->id)) {
                                continue;
                            }

                            $value->is_take = 0;//进入预处理状态
                            //未处理进入预处理列表
                            $redis->hSet('trans:buybtc', $value->id, json_encode($value));
                        }
                    }

                    break;
                 //成交的订单
                case 'closed':
                        //买的订单
                        if ($value->type == 'bid') {
                            //是否处理过
                            if ($redis->sIsMember('trans:buybtc:deal', $value->id) || $redis->hGet('trans:buybtc', $value->id)) {
                                continue;
                            }

                            $value->is_take = 0;//进入预处理状态
                            //未处理进入预处理列表
                            $redis->hSet('trans:buybtc', $value->id, json_encode($value));
                        }

                        //卖的订单
                        if ($value->type == 'ask') {
                             //查询卖单对应的买单
                            $id = (int)$redis->hGet('trans:sellbtc', $value->id);

                            $redis->hDel('trans:sellbtc', $value->id);
                            $redis->hDel('trans:buybtc', $id);
                            $redis->sAdd('trans:buybtc:deal', $id);//记录处理过的订单
                        }
                    break;
                default:
                    # code...
                    break;
            }
        }

        echo "done\n";
    }
}