<?php

/**
 * 微信支付
 * 
 * @author 迅虎网络
 * @version 1.0.0
 * @date 2018年3月23日 15:45:35
 */

if (!defined('IN_ECTOUCH'))
{
    die('Hacking attempt');
}

$payment_lang = ROOT_PATH . 'languages/' .$GLOBALS['_CFG']['lang']. '/payment/wechatopen.php';

if (file_exists($payment_lang))
{
    global $_LANG;

    include_once($payment_lang);
}

/* 模块的基本信息 */
if (isset($set_modules) && $set_modules == TRUE)
{
    $i = isset($modules) ? count($modules) : 0;

    /* 代码 */
    $modules[$i]['code']    = 'wechatopen';

    /* 描述对应的语言项 */
    $modules[$i]['desc']    = 'wechatopen_desc';

    /* 是否支持货到付款 */
    $modules[$i]['is_cod']  = '0';

    /* 是否支持在线支付 */
    $modules[$i]['is_online']  = '1';

    /* 作者 */
    $modules[$i]['author']  = '迅虎网络';

    /* 网址 */
    $modules[$i]['website'] = 'https://www.wpweixin.net';

    /* 版本号 */
    $modules[$i]['version'] = '1.0.0';

    /* 配置信息 */
    $modules[$i]['config']  = array(
        array('name' => 'wechatopen_account',   'type' => 'text', 'value' => '20146123713'),
        array('name' => 'wechatopen_key',       'type' => 'text', 'value' => '6D7B025B8DD098C485F0805193136FB9'),
        array('name' => 'wechatopen_prefix',     'type' => 'text', 'value' => 'ec_'),
        array('name' => 'wechatopen_exchange_rate', 'type' => 'text', 'value' => '1'),
        array('name' => 'wechatopen_transaction_url', 'type' => 'text', 'value' => 'https://pay2.xunhupay.com/v2'),
    );
    
    return;
}

/**
 * 类
 */
class wechatopen
{
    private function generate_xh_hash(array $datas,$hashkey){
        ksort($datas);
        reset($datas);
         
        $pre =array();
        foreach ($datas as $key => $data){
            if(is_null($data)||$data===''){continue;}
            if($key=='hash'){
                continue;
            }
            $pre[$key]=$data;
        }
         
        $arg  = '';
        $qty = count($pre);
        $index=0;
         
        foreach ($pre as $key=>$val){
            $arg.="$key=$val";
            if($index++<($qty-1)){
                $arg.="&";
            }
        }
         
        return md5($arg.$hashkey);
    }
    
    private function http_post($url,$data){
        if(!function_exists('curl_init')){
            throw new Exception('php未安装curl组件',500);
        }
        
        $protocol = (! empty ( $_SERVER ['HTTPS'] ) && $_SERVER ['HTTPS'] !== 'off' || $_SERVER ['SERVER_PORT'] == 443) ? "https://" : "http://";
        $siteurl= $protocol.$_SERVER['HTTP_HOST'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_REFERER,$siteurl);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $response = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($httpStatusCode!=200){
            throw new Exception("invalid httpstatus:{$httpStatusCode} ,response:$response,detail_error:".$error,$httpStatusCode);
        }
         
        return $response;
    }
    
    /**
     * 生成支付代码
     * @param   array    $order       订单信息
     * @param   array    $payment     支付方式信息
     */
    function get_code($order, $payment)
    {
        $data=array(
            'version'   => '1.1',//api version
            'lang'       => $GLOBALS['_CFG']['lang'],
            'plugins'   => 'ecshop-wechat',
            'appid'     => $payment['wechatopen_account'],
            'trade_order_id'=>  $payment['wechatopen_prefix'].get_order_id_by_sn($order['order_sn']),
            'payment'   => 'wechat',
            'total_fee' => round(floatval($order['order_amount'])*floatval($payment['wechatopen_exchange_rate']),2),
            'title'     => get_goods_name_by_id($order['order_id']),        
            'time'      => time(),
            'notify_url'=> return_url('wechatopen'),
            'return_url'=> return_url('wechatopen'),
            'callback_url'=>return_url('wechatopen'),
            'nonce_str' => str_shuffle(time())
        );
        
        $hashkey          = $payment['wechatopen_key'];
        $data['hash']     = $this->generate_xh_hash($data,$hashkey);
        $url              = rtrim($payment['wechatopen_transaction_url'],'/').'/payment/do.html';
        
        try {
            $response     = $this->http_post($url, json_encode($data));
            $result       = $response?json_decode($response,true):null;
            if(!$result){
                throw new Exception('Internal server error',500);
            }
             
            $hash         = $this->generate_xh_hash($result,$hashkey);
            if(!isset( $result['hash'])|| $hash!=$result['hash']){
                throw new Exception('Invalid sign!');
            }
        
            if($result['errcode']!=0){
                throw new Exception($result['errmsg'],$result['errcode']);
            }
        
            ob_start();
            ?>
            <script type="text/javascript">
				location.href="<?php echo $result['url'];?>";
			</script>
            <?php 
            return ob_get_clean();
        } catch (Exception $e) {
             return "<div style=\"color:red;\">{$e->getMessage()}</div>";
        }
    }

    /**
     * 响应操作
     */
    function respond()
    {
        $data = $_POST;
        if(!isset($data['hash'])||!isset($data['trade_order_id'])){
           return true;
        }
        if(isset($data['plugins'])&&$data['plugins']!='ecshop-wechat'){
            return true;
        }
        
        $payment    = get_payment('wechatopen');
        $appkey =$payment['wechatopen_key'];
        $hash =$this->generate_xh_hash($data,$appkey);
        if($data['hash']!=$hash){
            return true;
        }
        
        if($data['status']=='OD'){
            order_paid(substr($data['trade_order_id'], strlen($payment['wechatopen_prefix'])));
        }
        
        echo 'success';exit;
    }
}

?>