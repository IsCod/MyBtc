<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Welcome extends CI_Controller {

	/**
	 * Index Page for this controller.
	 *
	 * Maps to the following URL
	 * 		http://example.com/index.php/welcome
	 *	- or -
	 * 		http://example.com/index.php/welcome/index
	 *	- or -
	 * Since this controller is set as the default controller in
	 * config/routes.php, it's displayed at http://example.com/
	 *
	 * So any other public methods not prefixed with an underscore will
	 * map to /index.php/welcome/<method_name>
	 * @see http://codeigniter.com/user_guide/general/urls.html
	 */
	public function index()
	{
		show_404();
		// $this->load->view('welcome_message');
	}

	private function https_get($url, $ctime = 3, $timeout = 4) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $result =  curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /*查询信息*/
    public function getInfo(){
        // if(!$this->is_start) die();
        include_once APPPATH . '/third_party/btcchina-api/BTCChinaLibrary.php';
        $btcAPI = new BTCChinaAPI();

        //获取我账户的信息
        $ret = $btcAPI->getAccountInfo();
        echo "-------------------------------------------------------------\n";
        echo "My Cny:    " . $ret->balance->cny->amount . "\n";
        echo "My Btc:    " . $ret->balance->btc->amount . "\n";
        echo "My Ltc:    " . $ret->balance->ltc->amount . "\n\n";
        echo "Order Cny: " . $ret->frozen->cny->amount . "\n";;
        echo "Order Ltc: " . $ret->frozen->btc->amount . "\n";
        echo "Order Ltc: " . $ret->frozen->ltc->amount . "\n\n";

        $ticker = false;
        //当前市场价格估算资产
        if (time() % 60 < 1) $ticker = $this->https_get('https://data.btcchina.com/data/ticker?market=all');
        $ticker = $this->https_get('https://data.btcchina.com/data/ticker?market=all');

        $redis = Rcache::init();
        if($ticker) $redis->set('ticker:all', $ticker);
        $ticker = $ticker ? json_decode($ticker) : json_decode($redis->get('ticker:all'));

        $all_money = $ticker->ticker_btccny->last *  ($ret->frozen->btc->amount + $ret->balance->btc->amount)
        + $ticker->ticker_ltccny->last *  ($ret->frozen->ltc->amount + $ret->balance->ltc->amount) + $ret->balance->cny->amount + $ret->frozen->cny->amount;

        echo "Asset Cny: " . $all_money . "\n";
        echo "-------------------------------------------------------------\n";

        $redis = Rcache::init();

        //等待处理的买入订单
        echo "While Trans Buy List:\n";
        $ltc = 0;
        $trans = $redis->hGetAll('trans:buyltc');
        foreach ($trans as $value) {
                $value = json_decode($value);
                echo 'orderID: ' . $value->id . '   ltcNum amount original: '. $value->amount_original . "  ltcNum amount :" . $value->amount . "     price : " . $value->avg_price . "    time : ". date('Y-m-d H:i:s', $value->date) . "   Take : " . $value->is_take ."\n";
                $ltc += $value->amount_original != $value->amount ? $value->amount_original - $value->amount : $value->amount_original;
        }

        echo "\n";
        echo "All Ltc Num :" . $ltc . "\n";

        echo "-------------------------------------------------------------\n\n";
        echo "Proce Trans Sell List:\n";
        $trans_sellltc = $redis->hGetAll('trans:sellltc');

        $ltc = 0;
        foreach ($trans_sellltc as $orderID) {
            $tran = json_decode($redis->hGet('trans:buyltc', $orderID));
            echo 'orderID: ' . $orderID . '   ltcMum : '. $tran->amount_original . "    time : ". date('Y-m-d H:i:s', $tran->date) . "   Take : " . $tran->is_take . "\n";
            $ltc += $tran->amount_original != $tran->amount ? $tran->amount_original - $tran->amount : $tran->amount_original;
        }
        echo "All Ltc Num :" . $ltc . "\n";
        echo "-------------------------------------------------------------\n\n";
        // 处理完的订单数量
        echo "Orders takes is ok num:" . $redis->sSize('trans:buyltc:deal') . "\n";

        echo "\n";
    }
}
