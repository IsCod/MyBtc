<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/**
 * Description of Daemon
 *
 * 根据ltcbtc成交
 * 进行ltc，btc买进卖出操作
 * 
 * @author iscod-ning
 */
class Daemon extends MY_Controller{

    private $is_start = true;//全局关闭交易
    private $is_buy_start = false;//买订单关闭接口
    private $is_sell_start = false;//卖订单关闭交易
    public function __construct() {
        parent::__construct(FALSE);
        date_default_timezone_set('Asia/Shanghai');

        $redis = Rcache::init();
        $TradingSwitch = $redis->hGetAll('TradingSwitch');
        $this->is_start = (bool)$redis->hGet('TradingSwitch', 'all');
        $this->is_buy_start = (bool)$redis->hGet('TradingSwitch', 'buy');
        $this->is_sell_start = (bool)$redis->hGet('TradingSwitch', 'sell');
    }


    public function getPriceForLtcBtc($test = false){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        // ask卖bid买
        //获取市场深度
        $market = $btcAPI->getMarketDepth(1, 'ALL');
        $return = false;

        // // 买入BTC换LTC兑换RMB，需要算出允许的最小ltc数量
        // $btc = $market->market_depth_btccny->ask[0];//市场中卖的btc数量
        // $ltc = $market->market_depth_ltccny->bid[0];//市场中买ltc的数量
        // $ltcbtc = $market->market_depth_ltcbtc->ask[0];//市场中卖ltc的btc价格

        // $return['ask'] = $ltcbtc->price;
        // $return['buy'] = round($ltc->price/$btc->price, 4);//理想的买进ltcbtc价格
        // $return['btc_ask'] = $btc->price;
        // $return['ltc_bid'] = $ltc->price;


        // $ltc_num_sort = array('btc' => $btc->amount / $ltcbtc->price, 'ltc' => $ltc->amount);
        // sort($ltc_num_sort);
        // $return['max_buy'] = $ltc_num_sort[0];

        $btc = $market->market_depth_btccny->bid[0];//市场中买的btc
        $ltc = $market->market_depth_ltccny->ask[0];//市场中卖ltc
        $ltcbtc = $market->market_depth_ltcbtc->bid[0];//市场中买ltc的btc

        $return['bid'] = $ltcbtc->price;

        $return['sell'] = round($ltc->price/$btc->price, 4);//理想的卖出ltcbtc价格

        if ($return['sell'] < $ltcbtc->price) $return['sell'] = $ltcbtc->price;

        $return['btc_bid'] = $btc->price;
        $return['ltc_ask'] = $ltc->price;

        $ltc_num_sort = array('btc' => $btc->amount / $ltcbtc->price, 'ltc' => $ltc->amount);
        sort($ltc_num_sort);
        $return['max_sell'] = $ltc_num_sort[0];

        $price['max_sell'] = $return['max_buy'] = 10;

        var_dump($return);
        return $return;
    }

    /**
    *需要快速的进行处理
    *市场稍有变动就进行调整
    */
    public function orderManage(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        $trans =  $btcAPI->getOrders(FALSE, 'LTCBTC', 30);
        var_dump($trans);
        die();
        $price = $this->getPriceForLtcBtc();

        $ask_num = 0;

        foreach ($trans->order as $value) {
            break 1;
            echo "ok";
            switch ($value->status) {
                //开放的订单
                case 'open':
                    $ask_num++;
                    if ($value->type == 'ask') {
                        if ($value->price != $price['sell']) {
                            $return = $btcAPI->cancelOrder((int)$value->id, 'LTCBTC');
                        }
                    }

                    // if ($value->type == 'bid') {
                    //     if ($value->price != $price['buy']) {
                    //         $return = $btcAPI->cancelOrder((int)$value->id, 'LTCBTC');
                    //     }
                    // }

                    break;
                // 取消的订单
                case 'cancelled':
                    //有成交的部分
                    if ($value->amount > 0 && $value->amount != $value->amount_original) {
                        // $amount_original成交的ltc数量
                        if ($value->type == 'ask') {
                            //卖出ltc买入了btc，进行btc卖出和ltc买进工作
                            $order_id = $btcAPI->placeOrder($price['ltc_bid'], $value->amount_original, 'LTCCNY');//ltc买进
                            $order_id = $btcAPI->placeOrder($price['btc_ask'], -$value->amount_original, 'BTCCNY');//BTC买出
                        }

                        // if ($value->type == 'bid') {
                        //     //买入ltc卖出了btc，进行btc买入和ltc卖出工作
                        //     $order_id = $btcAPI->placeOrder($price['ltc_ask'], $value->amount_original, 'LTCCNY');//ltc买进
                        //     $order_id = $btcAPI->placeOrder($price['btc_bid'], $value->amount_original, 'BTCCNY');//ltc买进
                        // }
                    }

                //成交的订单
                case 'closed':
                        //买的订单
                        if ($type->type == 'ask') {
                            $order_id = $btcAPI->placeOrder($price['ltc_bid'], $value->amount_original, 'LTCCNY');//ltc买进
                            $order_id = $btcAPI->placeOrder($price['btc_ask'], -$value->amount_original, 'BTCCNY');//BTC买出
                        }

                        // if ($value->type == 'bid') {
                        //     $order_id = $btcAPI->placeOrder($price['ltc_bid'], -$value->amount_original, 'LTCCNY');//ltc买进
                        //     $order_id = $btcAPI->placeOrder($price['btc_ask'], $value->amount_original, 'BTCCNY');//BTC买出
                        // }
                default:
                    # code...
                    break;
            }
        }

        if (!$ask_num) {
            $order_id = $btcAPI->placeOrder($price['sell'], -$price['max_sell'], 'LTCBTC');//ltcbtc卖出
        }

        echo "done\n";
    }
}