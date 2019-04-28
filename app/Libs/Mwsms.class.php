<?php
/**
 * 梦网短信
 */
namespace App\Libs;

use GuzzleHttp\Client;

class Mwsms
{

	private $ip;
	private $port;
	private $address;
	private $username;
	private $password;
	private $channel;
	private $sameMsg = '/MWGate/wmgw.asmx/MongateSendSubmit';
	private $diffMsg = '/MWGate/wmgw.asmx/MongateMULTIXSend';
	private $errCode = [-1, -12, -14, -999, -10001, -10003, -10011, -10029, -10030, -10031, -10057, -10056];
	function __construct($option = array())
	{
		$this->ip = $option['sms_ip'];
		// $this->address=$option['address']?$option['address']:C('MWSMS_ADDRESS');
		$this->port = $option['sms_port'];
		$this->username = $option['sms_account'];
		$this->password = $option['sms_passcode'];
		$this->channel = '';//$option['channel']?:'';
	}
	/**
	 * 相同短信内容，不同号码
	 * @param  [type]  $phone      [手机号 (13800138000,13000000001)]
	 * @param  [type]  $content    [短信内容]
	 * @param  boolean $pszSubPort [子帐号]
	 * @param  integer $iMobiCount [短信数量 ]
	 * @param  boolean $MsgId      [短信流水号]
	 * @return [type]              [description]
	 */
	function send($phone, $content, $iMobiCount = 1, $pszSubPort = false, $MsgId = false)
	{
		# 短信发送
		$data['userId'] = $this->username;
		$data['password'] = $this->password;
		$data['pszMobis'] = $phone;
		$data['pszMsg'] = $content;
		$data['iMobiCount'] = $iMobiCount;
		if ($pszSubPort) {
			$data['pszSubPort'] = $this->channel . $pszSubPort;
		} else {
			$data['pszSubPort'] = '*';
		}
		if ($MsgId) {
			$data['MsgId'] = $MsgId;
		} else {
			$data['MsgId'] = mktime(true) * 1000;
		}
		// var_dump($data);
		// $data = iconv('UTF-8', 'GBK//IGNORE', http_build_query($data));
		$http = new Client();
		$response = $http->request('post', 'http://' . $this->ip . ':' . $this->port . $this->sameMsg, [
			'headers' => [
				'Content-type' => 'application/x-www-form-urlencoded'
			],
			'form_params' => $data
		]);
		if ($response->getStatusCode() != 200) return false;
		$content = $response->getBody();
		$content = (array)simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
		if (!is_array($content)) {
			return false;
		}
		$code = $content[0];
		if (in_array($code, $this->errCode)) {
			return false;
		}
		return $code;
	}
	/**
	 * 发送不同内容
	 * @param  [type] $phoneText [发送内容，流水号、通道号、手机号、内容，“|” 分隔]
	 * @return [type]            [description]
	 * $phoneText: 
	 * 流水号|通道号(整数、*或空，*表示不扩展)|手机号|短信内容base64(GBK编码)，“,”号分隔
	 * 457894132578945|41|13800138000|xOO6wyy7ttOtyrnTwyE=,457894132578946|42|13900138000|xOO6wyy7ttOtyrnTwyE=
	 */
	function sendDiff($phoneText)
	{
		$data['userId'] = $this->username;
		$data['password'] = $this->password;
		$data['multixmt'] = $phoneText;
		// $data = http_build_query($data);
		$http = new Client();
		$response = $http->request('post', $this->ip . ':' . $this->port . $this->diffMsg, [
			'headers' => [
				'Content-type' => 'application/x-www-form-urlencoded'
			],
			'form_params' => $data
		]);

		if ($response->getStatusCode() != 200) return false;
		$content = $response->getBody();
		$content = (array)simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
		if (!is_array($content)) {
			return false;
		}
		$code = $content[0];
		if (in_array($code, $this->errCode)) {
			return false;
		}
		return $code;
	}

	function http_post($url, $param)
	{
		$oCurl = curl_init();
		if (stripos($url, "https://") !== FALSE) {
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
		}
		$browsers = array(
			"Content-type" => "application/x-www-form-urlencoded",
		);
		curl_setopt($oCurl, CURLOPT_HTTPHEADER, $browsers); //设置HTTP头
		curl_setopt($oCurl, CURLOPT_URL, $url);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($oCurl, CURLOPT_POST, 1);
		curl_setopt($oCurl, CURLOPT_POSTFIELDS, $param);
		$sContent = curl_exec($oCurl);
		$aStatus = curl_getinfo($oCurl);
		curl_close($oCurl);
		if (intval($aStatus["http_code"]) == 200) {
			return $sContent;
		} else {
			return false;
		}
	}
}
