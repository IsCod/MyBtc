<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * 活动相关model
 */

class GetPirce_model extends CI_Model {

    private $market_depth_num = 15;//获取深度

    /**
    *获取ltc出售与买入价格
    *@param bool $test
    *@return array;
    */
    public function Ltc(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //获取市场深度
        $market = $btcAPI->getMarketDepth(1, 'ALL');

        $return = array();

        $return['bid'] = $market->market_depth_ltccny->bid[0]->price;
        $return['ask'] = $market->market_depth_ltccny->ask[0]->price;
        $return['bid_buy'] = $market->market_depth_btccny->bid[0]->price * $market->market_depth_ltcbtc->bid[0]->price;
        $return['ask_sell'] = $market->market_depth_btccny->ask[0]->price * $market->market_depth_ltcbtc->ask[0]->price;

        //获取市场深度
        $market = $btcAPI->getMarketDepth($this->market_depth_num, 'LTCCNY');

        $depth = array('ask' => array('all_amount' => 0, 'all_amount_cost' => 0), 'bid' => array('all_amount' => 0, 'all_amount_cost' => 0));

        $ask_max_amount = $bid_max_amount = 0;
        foreach ($market->market_depth->ask as $key => $value) {
            if ($key == 0) $depth['ask']['min_price'] = $value->price;
            if ($key == 3) $depth['ask']['midd_price'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['ask']['max_price'] = $value->price;

            if ($value->amount > $ask_max_amount) {
                $ask_max_amount = $value->amount;
                $depth['ask']['max_amount'] = $value->amount;
                $depth['ask']['max_amount_price'] = $value->price;
            }
            $depth['ask']['all_amount'] += $value->amount;
            $depth['ask']['all_amount_cost'] += $value->price * $value->amount;
        }

        foreach ($market->market_depth->bid as $key => $value) {
            
            if ($key == 0) $depth['bid']['max_price'] = $value->price;
            if ($key == 3) $depth['bid']['midd_price'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['bid']['min_price'] = $value->price;

            if ($value->amount > $bid_max_amount) {
                $bid_max_amount = $value->amount;
                $depth['bid']['max_amount'] = $value->amount;
                $depth['bid']['max_amount_price'] = $value->price;
            }
            $depth['bid']['all_amount'] += $value->amount;
            $depth['bid']['all_amount_cost'] += $value->price * $value->amount;
        }

        $depth['ask']['market_midd_price'] = $depth['ask']['all_amount_cost'] / $depth['ask']['all_amount'];
        $depth['bid']['market_midd_price'] = $depth['bid']['all_amount_cost'] / $depth['bid']['all_amount'];

        $return['buy'] = $return['bid_buy'];
        $return['sell'] = $return['ask_sell'];

        $return['buy'] = ($return['buy'] +  $depth['bid']['market_midd_price']) / 2;
        $return['sell'] = ($return['sell'] + $depth['ask']['market_midd_price']) / 2;

        //数量最多的价格总量占深度总量大于0.5时
        if ($depth['bid']['all_amount'] / $depth['bid']['max_amount'] < 2) $return['buy'] = ($return['buy'] + $depth['bid']['max_amount_price']) / 2;
        if ($depth['ask']['all_amount'] / $depth['ask']['max_amount'] < 2) $return['sell'] = ($return['sell'] + $depth['ask']['max_amount_price']) / 2;

        $ask_bid_market = $depth['ask']['all_amount'] / $depth['bid']['all_amount'];

        //买单大于卖单5倍
        if ($ask_bid_market && $ask_bid_market < 1) {
            $return['sell'] += 0.01 * ( 1/$ask_bid_market - 10);
        }

        //卖单大于买单5倍
        if ($ask_bid_market && $ask_bid_market > 1) {
            $return['buy'] -= 0.01 * ($ask_bid_market);
            $return['sell'] -= 0.01 * ($ask_bid_market);
        }

        //当前市场的最高出价和最低要价
        if ($return['buy'] > $return['bid']) $return['buy'] = $return['bid'];
        if ($return['buy'] < $return['bid_buy']) $return['buy'] = $return['bid_buy'];

        if ($return['sell'] < $return['ask']) $return['sell'] = $return['ask'];
        if ($return['sell'] > $return['ask_sell']) $return['sell'] = $return['ask_sell'];

        $return['buy'] = round($return['buy'], 2);
        $return['sell'] = round($return['sell'], 2);
        return $return;
    }

    public function Btc(){
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //获取市场深度
        $market = $btcAPI->getMarketDepth(1, 'ALL');

        $return = array();

        $return['bid'] = $market->market_depth_btccny->bid[0]->price;
        $return['ask'] = $market->market_depth_btccny->ask[0]->price;

        //获取市场深度
        $market = $btcAPI->getMarketDepth($this->market_depth_num, 'BTCCNY');

        $depth = array('ask' => array('all_amount' => 0, 'all_amount_cost' => 0), 'bid' => array('all_amount' => 0, 'all_amount_cost' => 0));

        $ask_max_amount = $bid_max_amount = 0;
        foreach ($market->market_depth->ask as $key => $value) {
            if ($key == 0) $depth['ask']['min_price'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['ask']['max_price'] = $value->price;

            $depth['ask']['all_amount'] += $value->amount;
            $depth['ask']['all_amount_cost'] += $value->price * $value->amount;
        }

        foreach ($market->market_depth->bid as $key => $value) {
            
            if ($key == 0) $depth['bid']['max_price'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['bid']['min_price'] = $value->price;

            $depth['bid']['all_amount'] += $value->amount;
            $depth['bid']['all_amount_cost'] += $value->price * $value->amount;
        }

        $depth['ask']['market_midd_price'] = $depth['ask']['all_amount_cost'] / $depth['ask']['all_amount'];
        $depth['bid']['market_midd_price'] = $depth['bid']['all_amount_cost'] / $depth['bid']['all_amount'];

        // bid买 ask卖
        $return['buy'] = ($depth['bid']['market_midd_price'] + $return['bid']) / 2;
        $return['sell'] = ($depth['ask']['market_midd_price'] + $return['ask'] ) /2;
        // $return['buy'] = $depth['bid']['market_midd_price'];
        // $return['sell'] = $depth['ask']['market_midd_price'];

        $ask_bid_market = $depth['ask']['all_amount'] / $depth['bid']['all_amount'];

        //买单大于卖单5倍
        if ($ask_bid_market && $ask_bid_market < 1) {
            $return['buy'] += 0.05*$ask_bid_market;
            $return['sell'] += 0.1/$ask_bid_market;
        }
        //卖单大于买单5倍
        if ($ask_bid_market && $ask_bid_market > 1) {
            $return['buy'] -= 0.1*$ask_bid_market;
            $return['sell'] -= 0.05*$ask_bid_market;
        }

        //买单大于当前市场的最高价格以最高价格买进
        if ($return['buy'] > $return['bid']) $return['buy'] = $return['bid'];
        if ($return['sell'] < $return['ask']) $return['sell'] = $return['ask'];

        //交易量过大时，适当增加比例
        $return['buy'] = $return['buy'] - $depth['ask']['all_amount'] * abs($return['bid'] - $return['buy']) / 100;
        $return['sell'] = $return['sell'] + $depth['bid']['all_amount'] * abs($return['sell'] - $return['ask']) / 100;

        $return['buy'] = round($return['buy'], 2);
        $return['sell'] = round($return['sell'], 2);

        return $return;
    }
}