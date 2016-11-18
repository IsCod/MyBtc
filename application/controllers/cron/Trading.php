<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/**
 * Description of crontab_pay
 *
 * @author iscod-ning
 */
class Trading extends MY_Controller{

    private $is_start = true;//全局关闭交易
    private $is_buy_start = false;//买订单关闭接口
    private $is_sell_start = false;//卖订单关闭交易
    public function __construct() {
        parent::__construct(FALSE);
        date_default_timezone_set('Asia/Shanghai');

        // $redis = Rcache::init();
        // $TradingSwitch = $redis->hGetAll('TradingSwitch');
        // $this->is_start = (bool)$redis->hGet('TradingSwitch', 'all');
        // $this->is_buy_start = (bool)$redis->hGet('TradingSwitch', 'buy');
        // $this->is_sell_start = (bool)$redis->hGet('TradingSwitch', 'sell');
    }

    private $market = 'LTCCNY';
    private $amount = 10;//每单购买的ltc
    private $max_order_num = 0;//最多同时在量的订单
    private $min_balance_cny_num = 600;//账户中保持最少800人民币
    
    private $cancel_order_time = 1800;//订单多长时间没有交易就取消

    private $place_order_num = 1;//同时下订单的数量

    /*
    *买订单脚本
    *买入ltc脚本
    */
    public function buyltc(){
        $is_price_order = $this->IsPlaceOrder('buy');
        if (is_array($is_price_order) || !$is_price_order) die("is price order die!\n");

        //当前开放的订单
        $openOrders = $this->openOrders();

        //订单数量限制
        if (count($openOrders['bid']) > $this->max_order_num) {
            echo "order nums is max\n";
            return true;
        }

        //加载BtcChinaApi
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //获取我账户的信息
        $ret = $btcAPI->getAccountInfo();

        //保持有500的人民币
        if ($ret->balance->cny->amount < $this->min_balance_cny_num) {
            die("cny balance amount is small\n");
        }

        //已经买入的订单
        $redis = Rcache::init();
        if (count($redis->hGetAll('trans:buyltc')) > 1) {
            echo "waiting deal order num is max\n";
            return true;
        }

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();

        //下两单
        for ($i=0; $i < $this->place_order_num; $i++) {
            $amount = $this->amount * rand(80, 120) /100;
            $orderId = $btcAPI->placeOrder($price['buy'], $amount, 'LTCCNY');

            $redis->sAdd('Trading:Ltc:OrderIds', $orderId);
        }

        if (!is_int($orderId) && $orderId) {
            echo "error: " . var_dump($orderId);
        }

        echo "done\n";
    }

    /**
    *卖订单脚本
    *出售ltc脚本
    */
    public function sellltc(){
        $is_price_order = $this->IsPlaceOrder('sell');
        if (is_array($is_price_order) || !$is_price_order) die("is price order die!\n");

        //当前开放的订单
        $openOrders = $this->openOrders();

        //订单数量限制
        if (count($openOrders['ask']) > $this->max_order_num) {
            echo "order nums is max\n";
            return true;
        }

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $redis = Rcache::init();
        $trans = $redis->hGetAll('trans:buyltc');//预处理列表

        $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();

        if (!$price || !isset($price['sell'])) die('error: get price');

        foreach ($trans as $value) {
            $value = json_decode($value);
            //已在处理中列表
            if ($value->is_take || $value->avg_price > $price['sell']) continue;

            $amount = $value->amount ? $value->amount_original - $value->amount : $value->amount_original;//订单量


            //下订单
            // if ($price['sell'] < ($value->avg_price + 0.05)) $price['sell'] = $value->avg_price + 0.05;

            //现在最高买的订单大于订单的价格0.5的时候下单成交
            // if ($price->bid > ($value->avg_price + 1.00)) $price['sell'] = $price->bid;

            // if ($value->cancelled_num > 10) {
            //     if ($price['sell'] - $value->avg_price < 0.05 * $value->cancelled_num && ($price['ask'] - $value->avg_price) < 0.2 ) {
            //         $price['sell'] = $value->avg_price + 0.01;
            //     }
            // }

            $sell_id = $btcAPI->placeOrder($price['sell'], -round(($amount  * 100)/100 , 2), 'LTCCNY');
            if (is_int($sell_id) && $sell_id) {

                $value->is_take = 1;//处理中状态
                $redis->hSet('trans:buyltc', $value->id, json_encode($value));
                $redis->hSet('trans:sellltc', $sell_id, $value->id);
            }
        }

        echo "done\n";
    }

    /**
    *当前开放订单信息
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
                $return['ask'] = $value;
            }
            if ($value->type == 'bid') {
                $return['bid'] = $value;
            }
        }

        return $return;
    }

    //订单处理
    public function orderManage(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $trans =  $btcAPI->getOrders(FALSE, 'LTCCNY', 30);

        $redis = Rcache::init();

        // $redis->delete('trans:sellltc');
        // $redis->delete('trans:buyltc');
        // $redis->delete('trans:buyltc:deal');
        // $redis->delete('Trading:Ltc:OrderIds');
        foreach ($trans->order as $value) {

            if (!$redis->sIsMember('Trading:Ltc:OrderIds' , $value->id) && !$redis->hGet('trans:sellltc', $value->id)) continue;

            switch ($value->status) {
                //开放的订单
                case 'open':
                    if((time() - $value->date) > $this->cancel_order_time) {
                        $return = $btcAPI->cancelOrder((int)$value->id, 'LTCCNY');
                        $redis->sRem('Trading:Ltc:OrderIds', $value->id);
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
                        $info->cancelled_num += 1;
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
                            $value->cancelled_num = 0;//记录取消次数
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

        echo "done\n";
    }


    //是否下订单
    public function IsPlaceOrder($type = 'all'){
        $redis = Rcache::init();
        $result = $redis->get('is:place:order');

        if ($result) {
            $result = unserialize($result);
        }else{
            $result = array('buy' => true, 'sell' => true);//默认不进行交易
            include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
            $btcAPI = new BTCChinaAPI();

            // 查询目前10个LTC价格深度
            $market = $btcAPI->getMarketDepth(5, 'LTCCNY');

            // 卖方数据深度
            $ask = $market->market_depth->ask;

            // 买方数据深度
            $bid = $market->market_depth->bid;

            $ask_all_amount = $bid_all_amount = $ask_all = $bid_all = 0;

            foreach ($ask as $key => $value) {
                $ask_all_amount += $value->amount;
                if ($key == 0) $max_price = $value->price;
                $ask_all += $value->price * $value->amount;
            }

            foreach ($bid as $key => $value) {
                $bid_all_amount += $value->amount;
                if ($key == 0) $min_price = $value->price;
                $bid_all += $value->price * $value->amount;
            }

            // echo "ask all: " . $ask_all_amount ."\n" ;
            // echo "bid all: " . $bid_all_amount . "\n";

            // var_dump(floor($ask_all_amount * 100 / $bid_all_amount));

            // 卖方数量与买方比例在5倍以上
            if (floor($ask_all_amount * 100 / $bid_all_amount) > 2000) $result['buy'] = false;
                // echo "ask greater than bid 400%\n";
                // die();
        }

        $redis->set('is:place:order', serialize($result));

        $redis->setTimeout('is:place:order', 180);//5分钟的缓存
        if (!$this->is_start || !$this->is_buy_start) $result['buy'] = false;
        if (!$this->is_start || !$this->is_sell_start) $result['sell'] = false;

        $result = array('buy' => true, 'sell' => true);//默认不进行交易

        switch ($type) {
            case 'all':
                return $result;
                break;
            case 'buy':
                return $result['buy'];
                break;
            case 'sell':
                return $result['sell'];
                break;
            default:
                var_dump($result);
                return $result;
                break;
        }
        return false;
    }


    public function LtcBtcOrder($test = false){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        // ask卖bid买
        //获取市场深度
        $market = $btcAPI->getMarketDepth(1, 'ALL');

        // if ($test) var_dump($market);

        $return = false;
        //买入BTC换LTC兑换RMB，需要算出允许的最小ltc数量
        $btc = $market->market_depth_btccny->ask[0];//市场中卖的btc数量
        $ltc = $market->market_depth_ltccny->bid[0];//市场中买ltc的数量
        $ltcbtc = $market->market_depth_ltcbtc->ask[0];//市场中卖ltc的btc价格

        if ($test) {
            var_dump( $ltc->price/$btc->price );
            var_dump( $ltcbtc->price);
            var_dump($btc->price * $ltcbtc->price);
            var_dump($ltc->price);
            var_dump($btc->price * $ltcbtc->price < $ltc->price);
        }

        if ($btc->price * $ltcbtc->price < $ltc->price) {
            $ltc_num_asort = array('btc' => $btc->amount / $ltcbtc->price, 'ltc' => $ltc->amount, 'ltcbtc' => $ltcbtc->amount);

            asort($ltc_num_asort);
            reset($ltc_num_asort);
            $min_key = key($ltc_num_asort);

            $return = $this->ltcbtc_placeOrder($min_key, $btc, $ltc, $ltcbtc, 'btc');
        }

        // ask卖bid买
        //买入LTC换BTC兑换RMB，需要计算出允许的最小btc数量

        $btc = $market->market_depth_btccny->bid[0];//市场中买的btc
        $ltc = $market->market_depth_ltccny->ask[0];//市场中卖ltc
        $ltcbtc = $market->market_depth_ltcbtc->bid[0];//市场中买ltc的btc

        if ($test) {
            var_dump( $ltc->price/$btc->price );
            var_dump( $ltcbtc->price );
            var_dump($btc->price * $ltcbtc->price);
            var_dump($ltc->price);
            var_dump($btc->price * $ltcbtc->price > $ltc->price);
        }

        if ($btc->price * $ltcbtc->price > $ltc->price) {
            $btc_num_asort = array('btc' => $btc->amount, 'ltc' => $ltc->amount * $ltcbtc->price, 'ltcbtc' => $ltcbtc->amount * $ltcbtc->price);

            asort($btc_num_asort);
            reset($btc_num_asort);
            $min_key = key($btc_num_asort);

            $return = $this->ltcbtc_placeOrder($min_key, $btc, $ltc, $ltcbtc , 'ltc');

        }

        echo "done\n";
    }


    public function ltcbtc_placeOrder($min_key, $btc, $ltc, $ltcbtc, $type = false){
            $min_ltc = $min_btc = $min_ltcbtc = 0;
            if ($min_key == 'btc') {
                $min_ltc = $btc->amount / $ltcbtc->price;
                $min_btc = $btc->amount;
                $min_ltcbtc = $btc->amount / $ltcbtc->price;
            }

            if ($min_key == 'ltc' || $min_key == 'ltcbtc') {
                $min_ltc = $$min_key->amount;
                $min_btc = $$min_key->amount * $ltcbtc->price;
                $min_ltcbtc = $$min_key->amount;
            }

            if (!$min_ltc || !$min_btc || !$min_ltcbtc || !$type) return false;

            //获取我账户的信息
            $ret = $btcAPI->getAccountInfo();

            //买入btc出售ltc
            if ($type == 'btc') {

                $ltcbtc_id = $btcAPI->placeOrder(1000, 0.001, 'LTCBTC');

                if ($btc->price * $min_btc > ($ret->balance->cny->amount - 100)) return false;

                $btc_id = $btcAPI->placeOrder($btc->price, $min_btc, 'BTCCNY');
                if(!$btc_id) $btc_id = $btcAPI->placeOrder($btc->price, $min_btc, 'BTCCNY');

                if (!$btc_id) return false;

                $ltcbtc_id = $btcAPI->placeOrder($ltcbtc->price, $min_ltcbtc, 'LTCBTC');
                if(!$ltcbtc_id) $ltcbtc_id = $btcAPI->placeOrder($ltcbtc->price, $min_ltcbtc, 'LTCBTC');

                $ltc_id = $btcAPI->placeOrder(-$ltc->price, $min_ltc, 'LTCCNY');
                if(!$ltc_id) $ltc_id = $btcAPI->placeOrder(-$ltc->price, $min_ltc, 'LTCCNY');

            }

            //买入ltc出售btc
            if ($type == 'ltc') {

                $ltcbtc_id = $btcAPI->placeOrder(1500, 0.001, 'LTCBTC');

                if ($ltc->price * $min_ltc > ($ret->balance->cny->amount - 100)) return false;

                $ltc_id = $btcAPI->placeOrder($ltc->price, $min_ltc, 'LTCCNY');
                if(!$ltc_id) $ltc_id = $btcAPI->placeOrder($ltc->price, $min_ltc, 'LTCCNY');

                if(!$ltc_id) return false;

                $ltcbtc_id = $btcAPI->placeOrder(-$ltcbtc->price, $min_ltcbtc, 'LTCBTC');
                if(!$ltcbtc_id) $ltcbtc_id = $btcAPI->placeOrder(-$ltcbtc->price, $min_ltcbtc, 'LTCBTC');

                $btc_id = $btcAPI->placeOrder(-$btc->price, $min_btc, 'BTCCNY');
                if(!$btc_id) $btc_id = $btcAPI->placeOrder(-$btc->price, $min_btc, 'BTCCNY');

            }

            $redis = Rcache::init();
            $redis->sAdd('ltcbtc_placeOrder' , $btc_id);
            $redis->sAdd('ltcbtc_placeOrder' , $ltc_id);
            $redis->sAdd('ltcbtc_placeOrder' , $ltcbtc_id);

            return true;
    }
}