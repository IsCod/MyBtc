<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * 活动相关model
 */

class getPirce_model extends CI_Model {

    private $market_depth_num = 5;//获取深度

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
        $return['buy'] = $market->market_depth_btccny->bid[0]->price * $market->market_depth_ltcbtc->bid[0]->price;
        $return['sell'] = $market->market_depth_btccny->ask[0]->price * $market->market_depth_ltcbtc->ask[0]->price;

        $return['buy'] = ($return['buy'] + $return['bid']) /2;
        $return['sell'] = ($return['sell'] + $return['sell']) /2;

        //获取市场深度
        $market = $btcAPI->getMarketDepth($this->market_depth_num, 'LTCCNY');

        $depth = array('ask' => array('all_amount' => 0, 'all_amount_cost' => 0), 'bid' => array('all_amount' => 0, 'all_amount_cost' => 0));

        $ask_max_amount = 0;
        foreach ($market->market_depth->ask as $key => $value) {
            if ($key == 0) $depth['ask']['min'] = $value->price;
            if ($key == 3) $depth['ask']['midd'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['ask']['max'] = $value->price;

            if ($value->amount > $ask_max_amount) {
                $depth['ask']['max_amount'] = $value->amount;
                $depth['ask']['max_amount_price'] = $value->price;
            }
            $depth['ask']['all_amount'] += $value->amount;
            $depth['ask']['all_amount_cost'] += $value->price * $value->amount;
        }

        $bid_max_amount = 0;
        foreach ($market->market_depth->bid as $key => $value) {
            
            if ($key == 0) $depth['bid']['max'] = $value->price;
            if ($key == 3) $depth['bid']['midd'] = $value->price;
            if ($key == ($this->market_depth_num -1)) $depth['bid']['min'] = $value->price;

            if ($value->amount > $ask_max_amount) {
                $depth['bid']['max_amount'] = $value->amount;
                $depth['bid']['max_amount_price'] = $value->price;
            }
            $depth['bid']['all_amount'] += $value->amount;
            $depth['bid']['all_amount_cost'] += $value->price * $value->amount;
        }

        //数量最多的价格总量占深度总量大于0.5时
        if ($depth['bid']['all_amount'] / $depth['bid']['max_amount'] < 2) $depth['bid']['midd'] = $depth['bid']['max_amount_price'];
        if ($depth['ask']['all_amount'] / $depth['ask']['max_amount'] < 2) $depth['ask']['midd'] = $depth['ask']['max_amount_price'];

        if (abs($return['buy'] - $depth['bid']['max_amount_price']) > 0.5) $return['buy'] = $depth['bid']['midd'];
        if (abs($return['sell'] - $depth['ask']['max_amount_price']) > 0.5) $return['sell'] = $depth['ask']['midd'];


        $ask_bid_market = $depth['ask']['all_amount'] / $depth['bid']['all_amount'];
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
        if ($return['buy'] > $depth['bid']['max']) $return['buy'] = $depth['bid']['max'];
        if ($return['buy'] < $depth['bid']['min']) $return['buy'] = $depth['bid']['min'];
        if ($return['sell'] < $depth['ask']['min']) $return['sell'] = $depth['ask']['min'];
        if ($return['sell'] > $depth['ask']['max']) $return['sell'] = $depth['ask']['max'];

        $return['buy'] = round($return['buy'], 2);
        $return['sell'] = round($return['sell'], 2);

        return $return;
    }
}