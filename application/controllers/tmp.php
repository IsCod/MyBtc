 <?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Welcome extends CI_Controller {

 /**
    *获取ltc出售与买入价格
    *@param bool $test
    *@return array;
    */
    public function getPirceLtc($test = false){

         $this->load->model('getPirce_model');
        $price = $this->getPirce_model->ltc();
        var_dump($price);
        die();

        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //获取市场深度
        $market = $btcAPI->getMarketDepth(1, 'ALL');

        $return = array();

        $return['bid'] = $market->market_depth_ltccny->bid[0]->price;
        $return['ask'] = $market->market_depth_ltccny->ask[0]->price;
        $return['buy'] = $market->market_depth_btccny->bid[0]->price * $market->market_depth_ltcbtc->bid[0]->price;
        $return['sell'] = $market->market_depth_btccny->ask[0]->price * $market->market_depth_ltcbtc->ask[0]->price;

        if($test) var_dump($return);

        //获取市场深度
        $market = $btcAPI->getMarketDepth(5, 'LTCCNY');

        //卖方深度
        $ask = $market->market_depth->ask;

        // 买方数据深度
        $bid = $market->market_depth->bid;

        $ask_all_amount = $bid_all_amount = $ask_all = $bid_all = $ask_min_ticker = $bid_max_ticker = 0;

        $ask_max_amount = 0;
        foreach ($ask as $key => $value) {
            if ($key == 0) $ask_min_ticker = $value->price;
            if ($value->amount > $ask_max_amount) {
                $ask_max_amount = $value->amount;
                $ask_max_amount_price = $value->price;
            }
            $ask_all_amount += $value->amount;
            $ask_all += $value->price * $value->amount;

            if($key == 2) $ask_midd = $value->price;
        }

        $bid_max_amount = 0;
        foreach ($bid as $key => $value) {
            if ($key == 0) $bid_max_ticker = $value->price;

            if ($value->amount > $bid_max_amount) {
                $bid_max_amount = $value->amount;
                $bid_max_amount_price = $value->price;
            }

            $bid_all_amount += $value->amount;
            $bid_all += $value->price * $value->amount;

            if($key == 2) $bid_midd = $value->price;
        }

        //数量最多的价格总量占深度总量大于0.5时
        if ($bid_all_amount / $bid_max_amount < 2) $bid_midd = $bid_max_amount_price;
        if ($ask_all_amount / $ask_max_amount < 2) $ask_midd = $ask_max_amount_price;

        if (abs($return['buy'] - $bid_max_amount_price) > 0.5) $return['buy'] = $bid_midd;
        if (abs($return['sell'] - $ask_max_amount_price) > 0.5) $return['sell'] = $ask_midd;

        $ask_bid_market = $ask_all_amount / $bid_all_amount;
        //卖单大于买单5倍
        if ($ask_bid_market > 10) {
            $return['buy'] -= ($ask_bid_market - 10) * 0.01;
            $return['sell'] -= ($ask_bid_market - 10) * 0.01;
        }

        //买单大于卖单5倍
        if ($ask_bid_market && $ask_bid_market < 0.1) {
            $return['sell'] += 0.01 * ( 1/$ask_bid_market - 10);
        }


        // //当前市场的最高出价和最低要价
        if ($return['buy'] > $bid_max_ticker) $return['buy'] = $bid_max_ticker;
        if ($return['sell'] < $ask_min_ticker) $return['sell'] = $ask_min_ticker;

        $return['buy'] = round($return['buy'], 2);
        $return['sell'] = round($return['sell'], 2);

        if($test) var_dump($return);

        return $return;
    }



}