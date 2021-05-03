<?php

namespace App\Services\Gateway;
use App\Services\View;
use App\Services\Auth;
use App\Services\Config;
use App\Models\Paylist;

class Pays
{
    private $pid;
    private $key;

    public function __construct($pid, $key)
    {
        $this->pid = $pid;
        $this->key = $key;
    }

//初始变量

    public function submit($type, $out_trade_no, $notify_url, $return_url, $name, $money, $sitename)
    {
        $data = [
            'pid' => $this->pid,
            'type' => $type,
            'out_trade_no' => $out_trade_no,
            'notify_url' => $notify_url,
            'return_url' => $return_url,
            'name' => $name,
            'money' => $money,
            'sitename' => $sitename
        ];
        $string = http_build_query($data);
        $sign = $this->getsign($data);
        return 'https://unionpay.moe/submit.php?' . $string . '&sign=' . $sign . '&sign_type=MD5';
    }

    public function verify($data)
    {
        if (!isset($data['sign']) || !$data['sign']) {
            return false;
        }
        $sign = $data['sign'];
        unset($data['sign']);
        unset($data['sign_type']);
        $sign3 = $this->getSign($data, $this->key);
        if ($sign != $sign3) {
            return false;
        }
        return true;
    }

    private function getSign($data)
    {
        $data = array_filter($data);
        ksort($data);
        $str1 = '';
        foreach ($data as $k => $v) {
            $str1 .= '&' . $k . "=" . $v;
        }
        $str = $str1 . $this->key;
        $str = trim($str, '&');
        $sign = md5($str);
        return $sign;
    }
}

class unionpay extends AbstractPayment
{

    public function isHTTPS()
    {
        define('HTTPS', false);
        if (defined('HTTPS') && HTTPS) {
            return true;
        }
        if (!isset($_SERVER)) {
            return false;
        }
        if (!isset($_SERVER['HTTPS'])) {
            return false;
        }
        if ($_SERVER['HTTPS'] === 1) {  //Apache
            return true;
        }

        if ($_SERVER['HTTPS'] === 'on') { //IIS
            return true;
        }

        if ($_SERVER['SERVER_PORT'] == 443) { //其他
            return true;
        }
        return false;
    }


    public function purchase($request, $response, $args)
    {
        $price = $request->getParam('price');

        if($price <= 0){
            return json_encode(['errcode'=>-1,'errmsg'=>"非法的金额."]);
        }else if($price > 0 && $price < 0.01){
            return json_encode(['errcode'=>-1,'errmsg'=>"低于最低金额0.01元."]);
        }
        $user = Auth::getUser();
        $type = $request->getParam('type');
        $settings = Config::get("unionpay")['config'];

        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->tradeno = self::generateGuid();
        $pl->save();
        $url = ($this->isHTTPS() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

        $return=$url.'/payment/unionpay_back';
        $pay = new Pays($settings['unionpay_id'], $settings['unionpay_key']);

//支付方式
        $type = $type;

        $out_trade_no = $pl->tradeno;
        $notify_url = $return;
        $return_url = $return;
        $name = $settings['gdname'];
        $money = $price;
        $sitename = $settings['sitename'];

//发起支付
        $query = $pay->submit($type, $out_trade_no, $notify_url, $return_url, $name, $money, $sitename);
        $url = $query;
        header('Location:' . $url);
    }

    public function notify($request, $response, $args)
    {
        $settings = Config::get("unionpay")['config'];

        $security['orderid'] = $_REQUEST['out_trade_no'];

        if($security['orderid']=='' OR $security['orderid']==null){header("Location: /user/code");}else{

            $pay = new Pays($settings['unionpay_id'], $settings['unionpay_key']);
            $data = $_REQUEST;
            $out_trade_no = $data['out_trade_no'];

            if ($pay->verify($data)) {
                //验证支付状态
                if ($data['trade_status'] == 'TRADE_SUCCESS') {

                    $this->postPayment($data['out_trade_no'], "UnionPay");
                    header("Location: /user/code");
                    echo "success";
                }
            } else {
                echo 'error';
            }
        }}
    public function getPurchaseHTML()
    {
        return '
                        <div class="card-inner">
                        <p class="card-heading">请输入充值金额</p>
                        <form class="unionpay" name="unionpay" action="/user/code/unionpay" method="get">
                            <input class="form-control maxwidth-edit" id="price" name="price" placeholder="输入充值金额后，点击你要付款的应用图标即可" autofocus="autofocus" type="value" min="0.01" max="1000" step="0.01" required="required">
                            <br>
                            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="alipay" ><img src="/images/alipay.png"  height="25" /></button>
                            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="qqpay" ><img src="/images/qqpay.png"  height="25" /></button>
                            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="wxpay" ><img
                src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTUwIiBoZWlnaHQ9IjM0IiB2aWV3Qm94PSIwIDAgMTUwIDM0IiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPgogICAgPGcgaWQ9IuW+ruS/oeaUr+S7mGxvZ28iPgogICAgICAgIDxwYXRoIGQ9Ik0xMy45ODc5MzI2LDIxLjQxNTU4NDQgQzEzLjgxMjUzNTMsMjEuNTAzODk2MSAxMy42MzcxMzgsMjEuNTQ4MDUxOSAxMy40MTc4OTE0LDIxLjU0ODA1MTkgQzEyLjkzNTU0ODksMjEuNTQ4MDUxOSAxMi41NDA5MDUxLDIxLjI4MzExNjkgMTIuMzIxNjU4NSwyMC44ODU3MTQzIEwxMi4yMzM5NTk4LDIwLjcwOTA5MDkgTDguNzY5ODYzNjgsMTMuMTE0Mjg1NyBDOC43MjYwMTQzNiwxMy4wMjU5NzQgOC43MjYwMTQzNiwxMi45Mzc2NjIzIDguNzI2MDE0MzYsMTIuODQ5MzUwNiBDOC43MjYwMTQzNiwxMi40OTYxMDM5IDguOTg5MTEwMjcsMTIuMjMxMTY4OCA5LjMzOTkwNDgyLDEyLjIzMTE2ODggQzkuNDcxNDUyNzcsMTIuMjMxMTY4OCA5LjYwMzAwMDczLDEyLjI3NTMyNDcgOS43MzQ1NDg2OCwxMi4zNjM2MzY0IEwxMy44MTI1MzUzLDE1LjI3NzkyMjEgQzE0LjExOTQ4MDUsMTUuNDU0NTQ1NSAxNC40NzAyNzUxLDE1LjU4NzAxMyAxNC44NjQ5MTg5LDE1LjU4NzAxMyBDMTUuMDg0MTY1NSwxNS41ODcwMTMgMTUuMzAzNDEyMSwxNS41NDI4NTcxIDE1LjUyMjY1ODcsMTUuNDU0NTQ1NSBMMzQuNjQwOTYxNSw2Ljg4ODMxMTY5IEMzMS4yMjA3MTQ3LDIuODI1OTc0MDMgMjUuNTY0MTUyNiwwLjE3NjYyMzM3NyAxOS4xNjIxNTIxLDAuMTc2NjIzMzc3IEM4LjcyNjAxNDM2LDAuMTc2NjIzMzc3IDAuMjE5MjQ2NTkyLDcuMjg1NzE0MjkgMC4yMTkyNDY1OTIsMTYuMDcyNzI3MyBDMC4yMTkyNDY1OTIsMjAuODQxNTU4NCAyLjc2MjUwNzA2LDI1LjE2ODgzMTIgNi43NTI3OTUwMywyOC4wODMxMTY5IEM3LjA1OTc0MDI2LDI4LjMwMzg5NjEgNy4yNzg5ODY4NSwyOC43MDEyOTg3IDcuMjc4OTg2ODUsMjkuMDk4NzAxMyBDNy4yNzg5ODY4NSwyOS4yMzExNjg4IDcuMjM1MTM3NTMsMjkuMzYzNjM2NCA3LjE5MTI4ODIxLDI5LjQ5NjEwMzkgQzYuODg0MzQyOTksMzAuNjg4MzExNyA2LjM1ODE1MTE3LDMyLjYzMTE2ODggNi4zNTgxNTExNywzMi43MTk0ODA1IEM2LjMxNDMwMTg1LDMyLjg1MTk0ODEgNi4yNzA0NTI1MywzMy4wMjg1NzE0IDYuMjcwNDUyNTMsMzMuMjA1MTk0OCBDNi4yNzA0NTI1MywzMy41NTg0NDE2IDYuNTMzNTQ4NDQsMzMuODIzMzc2NiA2Ljg4NDM0Mjk5LDMzLjgyMzM3NjYgQzcuMDE1ODkwOTQsMzMuODIzMzc2NiA3LjE0NzQzODksMzMuNzc5MjIwOCA3LjIzNTEzNzUzLDMzLjY5MDkwOTEgTDExLjM1Njk3MzUsMzEuMjYyMzM3NyBDMTEuNjYzOTE4NywzMS4wODU3MTQzIDEyLjAxNDcxMzIsMzAuOTUzMjQ2OCAxMi4zNjU1MDc4LDMwLjk1MzI0NjggQzEyLjU0MDkwNTEsMzAuOTUzMjQ2OCAxMi43NjAxNTE2LDMwLjk5NzQwMjYgMTIuOTM1NTQ4OSwzMS4wNDE1NTg0IEMxNC44NjQ5MTg5LDMxLjYxNTU4NDQgMTYuOTY5Njg2MiwzMS45MjQ2NzUzIDE5LjExODMwMjgsMzEuOTI0Njc1MyBDMjkuNTU0NDQwNiwzMS45MjQ2NzUzIDM4LjA2MTIwODQsMjQuODE1NTg0NCAzOC4wNjEyMDg0LDE2LjAyODU3MTQgQzM4LjA2MTIwODQsMTMuMzc5MjIwOCAzNy4yNzE5MjA2LDEwLjg2MjMzNzcgMzUuOTEyNTkxOCw4LjY1NDU0NTQ1IEwxNC4xMTk0ODA1LDIxLjMyNzI3MjcgTDEzLjk4NzkzMjYsMjEuNDE1NTg0NCBaIiBpZD0iWE1MSURfODFfIiBmaWxsPSIjMjJBQzM4Ij48L3BhdGg+CiAgICAgICAgPHJlY3QgaWQ9IlhNTElEXzgwXyIgZmlsbD0iIzQ5NDk0OSIgeD0iODMuNjIwNjUwMiIgeT0iMTEuNDM2MzYzNiIgd2lkdGg9IjE0LjIwNzE3OTIiIGhlaWdodD0iMS4yODA1MTk0OCI+PC9yZWN0PgogICAgICAgIDxyZWN0IGlkPSJYTUxJRF83OV8iIGZpbGw9IiM0OTQ5NDkiIHg9IjgzLjYyMDY1MDIiIHk9IjE1LjQxMDM4OTYiIHdpZHRoPSIxNC4yMDcxNzkyIiBoZWlnaHQ9IjEuMzY4ODMxMTciPjwvcmVjdD4KICAgICAgICA8cGF0aCBkPSJNOTcuNDMzMTg1NCwyNy4yODgzMTE3IEw5Ny40MzMxODU0LDE5LjUxNjg4MzEgTDgzLjk3MTQ0NDcsMTkuNTE2ODgzMSBMODMuOTcxNDQ0NywyNy4yNDQxNTU4IEw5Ny40MzMxODU0LDI3LjI0NDE1NTggTDk3LjQzMzE4NTQsMjcuMjg4MzExNyBaIE04NS4zMzA3NzM2LDIwLjc5NzQwMjYgTDk2LjA3Mzg1NjYsMjAuNzk3NDAyNiBMOTYuMDczODU2NiwyNS45NjM2MzY0IEw4NS4zMzA3NzM2LDI1Ljk2MzYzNjQgTDg1LjMzMDc3MzYsMjAuNzk3NDAyNiBaIiBpZD0iWE1MSURfNzZfIiBmaWxsPSIjNDk0OTQ5IiBmaWxsLXJ1bGU9Im5vbnplcm8iPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNNzkuNzE4MDYwOCwyOC4wODMxMTY5IEw3OS43MTgwNjA4LDEwLjI4ODMxMTcgQzgwLjUwNzM0ODYsOC41MjIwNzc5MiA4MS4yNTI3ODcsNi42Njc1MzI0NyA4MS44MjI4MjgxLDQuNzY4ODMxMTcgTDgwLjU1MTE5NzksNC4wMTgxODE4MiBDNzkuMjc5NTY3Niw4LjQ3NzkyMjA4IDc3LjQ4MTc0NTYsMTIuNDk2MTAzOSA3NS4xNTc3MzE3LDE1Ljg5NjEwMzkgTDc1LjkwMzE3MDEsMTcuMjIwNzc5MiBDNzYuNjkyNDU3OSwxNi4wMjg1NzE0IDc3LjUyNTU5NDksMTQuNjE1NTg0NCA3OC4zNTg3MzIsMTMuMTU4NDQxNiBMNzguMzU4NzMyLDI4LjEyNzI3MjcgTDc5LjcxODA2MDgsMjguMTI3MjcyNyBMNzkuNzE4MDYwOCwyOC4wODMxMTY5IFoiIGlkPSJYTUxJRF83NV8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNMTMxLjI4NDg1OSwyOC4wODMxMTY5IEwxMzEuMjg0ODU5LDEwLjI4ODMxMTcgQzEzMi4wNzQxNDcsOC41MjIwNzc5MiAxMzIuODE5NTg1LDYuNjY3NTMyNDcgMTMzLjM4OTYyNyw0Ljc2ODgzMTE3IEwxMzIuMTE3OTk2LDQuMDE4MTgxODIgQzEzMC44NDYzNjYsOC40Nzc5MjIwOCAxMjkuMDQ4NTQ0LDEyLjQ5NjEwMzkgMTI2LjcyNDUzLDE1Ljg5NjEwMzkgTDEyNy40Njk5NjksMTcuMjIwNzc5MiBDMTI4LjI1OTI1NiwxNi4wMjg1NzE0IDEyOS4wOTIzOTMsMTQuNjE1NTg0NCAxMjkuOTI1NTMsMTMuMTU4NDQxNiBMMTI5LjkyNTUzLDI4LjEyNzI3MjcgTDEzMS4yODQ4NTksMjguMTI3MjcyNyBMMTMxLjI4NDg1OSwyOC4wODMxMTY5IFoiIGlkPSJYTUxJRF83NF8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNOTkuMzE4NzA2MSw3LjMyOTg3MDEzIEw5MC45ODczMzU2LDcuMzI5ODcwMTMgTDkxLjg2NDMyMiw2LjggTDkxLjkwODE3MTMsNi44IEw5MS45MDgxNzEzLDYuNzU1ODQ0MTYgQzkxLjgyMDQ3MjcsNi41MzUwNjQ5NCA5MS41NTczNzY4LDUuOTYxMDM4OTYgOTEuMjUwNDMxNiw1LjM0Mjg1NzE0IEM5MC45ODczMzU2LDQuNzY4ODMxMTcgOTAuNzI0MjM5Nyw0LjIzODk2MTA0IDkwLjU5MjY5MTgsMy45Mjk4NzAxMyBMOTAuNTkyNjkxOCwzLjg4NTcxNDI5IEw4OS4zMjEwNjE1LDQuNjM2MzYzNjQgTDg5LjMyMTA2MTUsNC42ODA1MTk0OCBDODkuNzU5NTU0Nyw1LjUxOTQ4MDUyIDkwLjE1NDE5ODYsNi4zMTQyODU3MSA5MC41MDQ5OTMxLDcuMDY0OTM1MDYgQzkwLjU0ODg0MjUsNy4xNTMyNDY3NSA5MC41OTI2OTE4LDcuMjQxNTU4NDQgOTAuNTkyNjkxOCw3LjI4NTcxNDI5IEw4Mi4yNjEzMjEzLDcuMjg1NzE0MjkgTDgyLjI2MTMyMTMsOC42NTQ1NDU0NSBMOTkuMzE4NzA2MSw4LjY1NDU0NTQ1IEw5OS4zMTg3MDYxLDcuMzI5ODcwMTMgWiIgaWQ9IlhNTElEXzczXyIgZmlsbD0iIzQ5NDk0OSI+PC9wYXRoPgogICAgICAgIDxwb2x5Z29uIGlkPSJYTUxJRF83Ml8iIGZpbGw9IiM0OTQ5NDkiIHBvaW50cz0iNjIuNjYwNjc2IDYuMjcwMTI5ODcgNjEuMzg5MDQ1NyA2LjI3MDEyOTg3IDYxLjM4OTA0NTcgMTAuNzc0MDI2IDU4Ljk3NzMzMzIgMTAuNzc0MDI2IDU4Ljk3NzMzMzIgNC4zNzE0Mjg1NyA1Ny43MDU3MDMgNC4zNzE0Mjg1NyA1Ny43MDU3MDMgMTAuNzc0MDI2IDU1LjIwNjI5MTggMTAuNzc0MDI2IDU1LjIwNjI5MTggNi4yNzAxMjk4NyA1My45MzQ2NjE2IDYuMjcwMTI5ODcgNTMuOTM0NjYxNiAxMi4wNTQ1NDU1IDYyLjY2MDY3NiAxMi4wNTQ1NDU1Ij48L3BvbHlnb24+CiAgICAgICAgPHJlY3QgaWQ9IlhNTElEXzcxXyIgZmlsbD0iIzQ5NDk0OSIgeD0iNTQuMzI5MzA1NSIgeT0iMTQuNTcxNDI4NiIgd2lkdGg9IjcuNzE3NDgwMDQiIGhlaWdodD0iMS4yODA1MTk0OCI+PC9yZWN0PgogICAgICAgIDxwYXRoIGQ9Ik01NS45OTU1Nzk2LDIwLjg0MTU1ODQgTDU1Ljk5NTU3OTYsMTkuNjkzNTA2NSBMNTkuODk4MTY4OSwxOS42OTM1MDY1IEw1OS44OTgxNjg5LDIyLjUxOTQ4MDUgQzU5Ljg1NDMxOTYsMjMuNTM1MDY0OSA1OS44MTA0NzAzLDIzLjg0NDE1NTggNTkuNDU5Njc1NywyNC4yODU3MTQzIEw2MC4yNDg5NjM1LDI1LjY1NDU0NTUgTDYwLjI5MjgxMjgsMjUuNjEwMzg5NiBDNjAuOTk0NDAxOSwyNS4wMzYzNjM2IDYyLjEzNDQ4NDEsMjQuMDY0OTM1MSA2My43NTY5MDg5LDIyLjYwNzc5MjIgTDYzLjgwMDc1ODIsMjIuNjA3NzkyMiBMNjMuMTg2ODY3OCwyMS41NDgwNTE5IEw2My4xNDMwMTg1LDIxLjUwMzg5NjEgTDYxLjE2OTc5OTEsMjMuMTgxODE4MiBMNjEuMTY5Nzk5MSwxOC4zNjg4MzEyIEw1NC42MzYyNTA3LDE4LjM2ODgzMTIgTDU0LjYzNjI1MDcsMjAuMzExNjg4MyBDNTQuODExNjQ4LDIyLjk2MTAzOSA1NC4xNTM5MDgyLDI0Ljg1OTc0MDMgNTIuNjYzMDMxNCwyNS45NjM2MzY0IEw1Mi42MTkxODIxLDI1Ljk2MzYzNjQgTDUzLjMyMDc3MTIsMjcuMiBMNTMuMzY0NjIwNSwyNy4yNDQxNTU4IEw1My40MDg0Njk4LDI3LjIgQzU1LjI1MDE0MTIsMjUuNzQyODU3MSA1Ni4xMjcxMjc1LDIzLjU3OTIyMDggNTUuOTk1NTc5NiwyMC44NDE1NTg0IFoiIGlkPSJYTUxJRF83MF8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNNDcuNjY0MjA5MSwxMC40MjA3NzkyIEw0OC40NTM0OTY4LDExLjc0NTQ1NDUgQzUwLjQyNjcxNjEsOS43NTg0NDE1NiA1Mi4xMzY4Mzk2LDcuMzc0MDI1OTcgNTMuMzY0NjIwNSw0Ljc2ODgzMTE3IEw1Mi4xODA2ODg5LDQuMDYyMzM3NjYgQzUwLjkwOTA1ODYsNi42MjMzNzY2MiA0OS4zNzQzMzI1LDguNzg3MDEyOTkgNDcuNjY0MjA5MSwxMC40MjA3NzkyIFoiIGlkPSJYTUxJRF82OV8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNNDcuMzEzNDE0NSwxOC41MDEyOTg3IEw0OC4wMTUwMDM2LDE5LjczNzY2MjMgTDQ4LjA1ODg1MjksMTkuNzgxODE4MiBMNDguMTAyNzAyMywxOS43Mzc2NjIzIEM0OC44NDgxNDA3LDE4Ljg1NDU0NTUgNDkuNTkzNTc5MSwxNy45MjcyNzI3IDUwLjI5NTE2ODIsMTYuOTU1ODQ0MiBMNTAuMjk1MTY4MiwyOC4wODMxMTY5IEw1MS41NjY3OTg0LDI4LjA4MzExNjkgTDUxLjU2Njc5ODQsMTQuODM2MzYzNiBDNTIuMTM2ODM5NiwxMy44MjA3NzkyIDUyLjcwNjg4MDcsMTIuNjcyNzI3MyA1My4yMzMwNzI1LDExLjM5MjIwNzggTDUzLjIzMzA3MjUsMTEuMzQ4MDUxOSBMNTIuMDA1MjkxNiwxMC42NDE1NTg0IEw1Mi4wMDUyOTE2LDEwLjY4NTcxNDMgQzUwLjc3NzUxMDcsMTMuNjQ0MTU1OCA0OS4yNDI3ODQ1LDE2LjI5MzUwNjUgNDcuMzEzNDE0NSwxOC41MDEyOTg3IFoiIGlkPSJYTUxJRF82OF8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNNzIuMDAwNTgwOCwxMC4wNjc1MzI1IEw3Mi4wMDA1ODA4LDguNzg3MDEyOTkgTDY2LjM0NDAxODcsOC43ODcwMTI5OSBDNjYuODI2MzYxMiw3LjI4NTcxNDI5IDY3LjM5NjQwMjQsNS41NjM2MzYzNiA2Ny41NzE3OTk2LDQuMTA2NDkzNTEgTDY2LjI1NjMyMDEsNC4xMDY0OTM1MSBDNjUuNDIzMTgzLDcuOTkyMjA3NzkgNjQuMTUxNTUyOCwxMS4zOTIyMDc4IDYyLjUyOTEyOCwxNC4wNDE1NTg0IEw2My4zMTg0MTU3LDE1LjQ1NDU0NTUgTDYzLjg4ODQ1NjksMTQuMjYyMzM3NyBDNjQuMTA3NzAzNSwxMy44NjQ5MzUxIDY0LjI4MzEwMDgsMTMuNTU1ODQ0MiA2NC40MTQ2NDg3LDEzLjI5MDkwOTEgQzY0LjgwOTI5MjYsMTYuNzM1MDY0OSA2NS41MTA4ODE3LDE5LjUxNjg4MzEgNjYuNDc1NTY2NywyMS41NDgwNTE5IEM2NS41NTQ3MzEsMjMuMTgxODE4MiA2NC4xMDc3MDM1LDI0Ljg1OTc0MDMgNjIuMTc4MzMzNSwyNi41ODE4MTgyIEw2Mi4xMzQ0ODQxLDI2LjU4MTgxODIgTDYyLjg3OTkyMjYsMjcuODYyMzM3NyBMNjIuOTIzNzcxOSwyNy45MDY0OTM1IEw2Mi45Njc2MjEyLDI3Ljg2MjMzNzcgQzY0Ljk4NDY4OTgsMjUuOTYzNjM2NCA2Ni4zODc4NjgsMjQuMjg1NzE0MyA2Ny4xMzMzMDY0LDIyLjkxNjg4MzEgQzY3Ljk2NjQ0MzUsMjQuNDE4MTgxOCA2OS40MTM0NzEsMjYuMjI4NTcxNCA3MS4wMzU4OTU4LDI3Ljk1MDY0OTQgTDcxLjA3OTc0NTEsMjcuOTk0ODA1MiBMNzEuODI1MTgzNSwyNi42NzAxMjk5IEw3MS44MjUxODM1LDI2LjYyNTk3NCBMNzEuNzgxMzM0MiwyNi42MjU5NzQgQzcwLjAyNzM2MTUsMjQuOTAzODk2MSA2OC42MjQxODMzLDIzLjEzNzY2MjMgNjcuODM0ODk1NSwyMS42MzYzNjM2IEM2OS4yODE5MjMsMTguOTg3MDEzIDcwLjE1ODkwOTQsMTUuMTAxMjk4NyA3MC41MDk3MDQsMTAuMDY3NTMyNSBMNzIuMDAwNTgwOCwxMC4wNjc1MzI1IFogTTY3LjMwODcwMzcsMjAuMDAyNTk3NCBDNjYuNDc1NTY2NywxNy44Mzg5NjEgNjUuODYxNjc2MiwxNC44ODA1MTk1IDY1LjQ2NzAzMjMsMTEuMjE1NTg0NCBDNjUuNTk4NTgwMywxMC44MTgxODE4IDY1Ljc3Mzk3NzYsMTAuNDIwNzc5MiA2NS45NDkzNzQ4LDEwLjA2NzUzMjUgTDY5LjM2OTYyMTcsMTAuMDY3NTMyNSBDNjkuMDYyNjc2NSwxNC4yMTgxODE4IDY4LjQwNDkzNjcsMTcuNTc0MDI2IDY3LjMwODcwMzcsMjAuMDAyNTk3NCBaIiBpZD0iWE1MSURfNjVfIiBmaWxsPSIjNDk0OTQ5IiBmaWxsLXJ1bGU9Im5vbnplcm8iPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNMTI1LjEwMjEwNSw4LjY5ODcwMTMgTDEyNS4xMDIxMDUsNy4zMjk4NzAxMyBMMTE0LjI3MTMyNCw3LjMyOTg3MDEzIEwxMTQuMjcxMzI0LDMuODQxNTU4NDQgTDExMi44NjgxNDYsMy44NDE1NTg0NCBMMTEyLjg2ODE0Niw3LjMyOTg3MDEzIEwxMDIuMTI1MDYzLDcuMzI5ODcwMTMgTDEwMi4xMjUwNjMsOC42OTg3MDEzIEwxMTIuODY4MTQ2LDguNjk4NzAxMyBMMTEyLjg2ODE0NiwxMy4yNDY3NTMyIEwxMTIuNjQ4ODk5LDEzLjI0Njc1MzIgTDEwNS44OTYxMDQsMTMuMjQ2NzUzMiBMMTA1Ljg5NjEwNCwxNC40ODMxMTY5IEMxMDUuODk2MTA0LDE0LjQ4MzExNjkgMTA2Ljk0ODQ4OCwxNi45MTE2ODgzIDEwOS4xNDA5NTMsMTkuNTYxMDM5IEMxMTAuMDE3OTQsMjAuNjIwNzc5MiAxMTEuMDI2NDc0LDIxLjcyNDY3NTMgMTEyLjM0MTk1NCwyMi44Mjg1NzE0IEMxMDkuODg2MzkyLDI0LjMyOTg3MDEgMTA2Ljg2MDc4OSwyNS43ODcwMTMgMTAyLjYwNzQwNSwyNi42MjU5NzQgTDEwMy4zOTY2OTMsMjguMDM4OTYxIEMxMDMuMzk2NjkzLDI4LjAzODk2MSAxMDguNDM5MzY0LDI2Ljk3OTIyMDggMTEzLjQzODE4NywyMy43MTE2ODgzIEMxMTQuMDk1OTI2LDI0LjE1MzI0NjggMTE3LjIwOTIyOCwyNi42MjU5NzQgMTIyLjk5NzMzOCwyOC4wMzg5NjEgQzEyMi45OTczMzgsMjguMDM4OTYxIDEyMi45OTczMzgsMjguMDM4OTYxIDEyMi45OTczMzgsMjguMDM4OTYxIEwxMjMuMDQxMTg3LDI4LjAzODk2MSBMMTIzLjYxMTIyOSwyNi45NzkyMjA4IEMxMjMuNjExMjI5LDI2Ljk3OTIyMDggMTIyLjEyMDM1MiwyNi41ODE4MTgyIDExOS44NDAxODcsMjUuNjk4NzAxMyBDMTE4LjI2MTYxMiwyNS4wODA1MTk1IDExNi4yODgzOTIsMjQuMTUzMjQ2OCAxMTQuNDAyODcyLDIyLjg3MjcyNzMgQzExNy4yMDkyMjgsMjAuNzk3NDAyNiAxMTkuNzA4NjM5LDE3Ljc5NDgwNTIgMTIxLjE5OTUxNiwxMy45NTMyNDY4IEwxMjAuMDU5NDM0LDEzLjI5MDkwOTEgTDExNC4yMjc0NzQsMTMuMjkwOTA5MSBMMTE0LjIyNzQ3NCw4LjY5ODcwMTMgTDEyNS4xMDIxMDUsOC42OTg3MDEzIFogTTExOS42MjA5NDEsMTQuNDM4OTYxIEMxMTkuNjIwOTQxLDE0LjQzODk2MSAxMTguMjE3NzYyLDE4LjQ1NzE0MjkgMTEzLjQzODE4NywyMS45MDEyOTg3IEMxMTEuMTU4MDIyLDIwLjEzNTA2NDkgMTA4Ljc5MDE1OSwxNy41NzQwMjYgMTA3LjI5OTI4MiwxNC40Mzg5NjEgTDExOS42MjA5NDEsMTQuNDM4OTYxIFoiIGlkPSJYTUxJRF8zNV8iIGZpbGw9IiM0OTQ5NDkiIGZpbGwtcnVsZT0ibm9uemVybyI+PC9wYXRoPgogICAgICAgIDxwYXRoIGQ9Ik0xNDIuNTU0MTM0LDI2LjMxNjg4MzEgQzE0Mi4yOTEwMzgsMjYuMzE2ODgzMSAxNDIuMDI3OTQyLDI2LjMxNjg4MzEgMTQxLjc2NDg0NiwyNi4zMTY4ODMxIEwxNDEuNzY0ODQ2LDI3LjY4NTcxNDMgQzE0Mi4yOTEwMzgsMjcuNjg1NzE0MyAxNDIuNjg1NjgyLDI3LjY4NTcxNDMgMTQyLjk0ODc3OCwyNy42ODU3MTQzIEMxNDQuMzA4MTA3LDI3LjY4NTcxNDMgMTQ1LjAwOTY5NiwyNy4zNzY2MjM0IDE0NS42MjM1ODYsMjYuODAyNTk3NCBDMTQ2LjE5MzYyNywyNi4yMjg1NzE0IDE0Ni40NTY3MjMsMjUuMzg5NjEwNCAxNDYuNDEyODc0LDI0LjI0MTU1ODQgTDE0Ni40MTI4NzQsMTEuMzkyMjA3OCBMMTUwLjA5NjIxNywxMS4zOTIyMDc4IEwxNTAuMDk2MjE3LDEwLjAyMzM3NjYgTDE0Ni40MTI4NzQsMTAuMDIzMzc2NiBMMTQ2LjQxMjg3NCw0LjEwNjQ5MzUxIEwxNDUuMDUzNTQ1LDQuMTA2NDkzNTEgTDE0NS4wNTM1NDUsMTAuMDIzMzc2NiBMMTMzLjc0MDQyMSwxMC4wMjMzNzY2IEwxMzMuNzQwNDIxLDExLjM5MjIwNzggTDE0NS4wNTM1NDUsMTEuMzkyMjA3OCBMMTQ1LjA1MzU0NSwyNC4yNDE1NTg0IEMxNDUuMDA5Njk2LDI1LjY1NDU0NTUgMTQ0LjEzMjcxLDI2LjMxNjg4MzEgMTQyLjU1NDEzNCwyNi4zMTY4ODMxIFoiIGlkPSJYTUxJRF8zNF8iIGZpbGw9IiM0OTQ5NDkiPjwvcGF0aD4KICAgICAgICA8cGF0aCBkPSJNMTM5LjQ4NDY4MiwyMS43MjQ2NzUzIEwxNDAuODQ0MDExLDIwLjkyOTg3MDEgQzE0MC4wOTg1NzIsMTkuNDI4NTcxNCAxMzkuMTMzODg3LDE3LjQ0MTU1ODQgMTM3Ljk0OTk1NiwxNS4wMTI5ODcgTDEzNi43MjIxNzUsMTUuNzYzNjM2NCBDMTM3LjUxMTQ2MiwxNy4zOTc0MDI2IDEzOC40MzIyOTgsMTkuMzg0NDE1NiAxMzkuNDg0NjgyLDIxLjcyNDY3NTMgWiIgaWQ9IlhNTElEXzMzXyIgZmlsbD0iIzQ5NDk0OSI+PC9wYXRoPgogICAgPC9nPgo8L3N2Zz4="width="110.25" height="25"></button>
                        </form>
                        </div>
';
    }
    public function getReturnHTML($request, $response, $args)
    {

    }
    public function getStatus($request, $response, $args)
    {
        // TODO: Implement getStatus() method.
    }
}
