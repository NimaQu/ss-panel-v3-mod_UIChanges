<?php

namespace App\Services\Gateway;

use App\Services\Auth;
use App\Models\Paylist;

class Vmqpay extends AbstractPayment
{

	public function purchase($request, $response, $args)
    {
        $vmqpay_key = $_ENV['vmqpay_key'];
		$vmqpay_gateway = $_ENV['vmqpay_gateway'];
		$baseUrl = $_ENV['baseUrl'];
        $user = Auth::getUser();
        $price = $request->getParam('price');
        $type = $request->getParam('type');
		$param = '';
		$timestamp = time();
		$sign = md5($timestamp.$param.$type.$price.$vmqpay_key);
		
        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->tradeno = $timestamp; //将订单发起时的时间戳作为流水号
        $pl->save();
		
		$post_url = "$vmqpay_gateway/createOrder?payId=$timestamp&type=$type&price=$price&sign=$sign&param=$param&isHtml=1&returnUrl=$baseUrl/payment/notify";
        header('Location:' . $post_url);
    }
	
	public function notify($request, $response, $args)
    {
		$key = $_ENV['vmqpay_key'];
		$baseUrl = $_ENV['baseUrl'];
		$payId = $_GET['payId']; //被当成流水号的时间戳
		$param = $_GET['param']; //创建订单时传入的自定义参数
		$type = $_GET['type']; // alipay -> 2, wechat -> 1
		$price = $_GET['price'];
		$reallyPrice = $_GET['reallyPrice'];
		
		$sign = $_GET['sign'];
		$_sign =  md5($payId.$param.$type.$price.$reallyPrice.$key);
		if ($_sign != $sign) {
			echo "error_sign";
			exit();
		}
		
		$this->postPayment($payId, '在线支付');
		
		header('Location:' . $baseUrl."/user/code");
    }
	
	public function getPurchaseHTML()
    {
        return '
                        <div class="card-inner">
                        <p class="card-heading">请输入充值金额</p>
                        <form class="vmqpay" name="vmqpay" action="/user/code/vmqpay" method="get">
                            <input class="form-control maxwidth-edit" id="price" name="price" placeholder="输入充值金额后，点击你要付款的应用图标即可" autofocus="autofocus" type="number" min="0.01" max="1000" step="0.01" required="required">
                            <br>
                            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="2" ><img src="/images/alipay.jpg" width="50px" height="50px" /></button>
                            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="1" ><img src="/images/weixin.jpg" width="50px" height="50px" /></button>
                        </form>
                        </div>
';
    }
	
    public function getReturnHTML($request, $response, $args)
    {
        // TODO: Implement getReturnHTML() method.
    }

    public function getStatus($request, $response, $args)
    {
        // TODO: Implement getStatus() method.
    }

}