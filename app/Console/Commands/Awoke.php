<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Awoke as AwokeModel;
use App\Libs\Wechat;
use Illuminate\Support\Facades\DB;
use App\Libs\Mwsms;

class Awoke extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $wechatTemplateMsg = [];
    protected $smsRecords = [];

    protected $signature = 'expire:awoke';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '年审、保险、证件到期提醒';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $awokes = AwokeModel::whereRaw("TIMESTAMPDIFF(DAY,'" . date('Y-m-d H:i:s') . "',BookingDate) IN(30,15,7,3,1)")->whereIn('BusinessType', ['年审', '保险', '证件'])->get();
        if (!$awokes) return;
        $redis = app('redis.wechatMessage');
        $confRedis = app('redis.companyInfo');
        if (!$redis || !$confRedis) return false;
        $time=time();
        array_walk($awokes, function ($awoke, $index) use ($redis, $confRedis,$time) {
            $cron = $redis->hGet('cron_config', 'company:' . $awoke['cid']);
            $step = explode(',', $cron['credentialsExpire']['expire_step']);
            $days = ceil((strtotime($awoke['BookingDate']) - time()) / 86400);
            if (!$cron || strtotime($cron['credentialsExpire']['start_time']) > time() || strtotime($cron['credentialsExpire']['end_time']) < time() || $cron['status'] <= 0 || ($step && !in_array($days, $step)) || ($cron['credentialsExpire']['start_at'] && date('H:i:s') < $cron['credentialsExpire']['start_at']) || ($cron['credentialsExpire']['end_at'] && date('H:i:s') > $cron['credentialsExpire']['end_at'])) {
                // 获取不到“证件提醒”的推送设置；开始、结束时间不符合设置要求；已禁用；日期时间不符合推送设置要求；当前不符合推送时间设置要求
                return false;
            }

            $title = '';
            $title .= '尊敬的' . $awoke['CustomerName'] . '客户，您的';
            $title .= '车辆 ' . $awoke['RegisterNo'];
            if ($awoke['BusinessType'] == '年审') {
                $title .= ' 年审';
                $type = '年审';
            } else if ($awoke['BusinessType'] == '证件') {
                $title .= '证件';
                $type = '证件';
            } else if ($awoke['BusinessType'] == '保险') {
                $title .= '保险';
                $type = '保险';
            } else {
                return false;
            }
            $expireDate = date('Y-m-d', strtotime($awoke['BookingDate']));
            $title .= '马上到期了';
            $pushType = explode(',', $cron['push_type']);
            $user = DB::table('member_openid')->where(['cid' => $awoke['cid'], 'phone' => $awoke['HandPhone']])->first();
            $tpl = $confRedis->hGet('wechat_template:' . $awoke['cid'], 'credentials_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) {
                // 默认微信推送，或设置了有微信推送
                $msg = [
                    'touser' => $user['openid'],
                    'template_id' => $tpl,
                    'url' => '',
                    'data' => array(
                        'first' => array('value' => $title, 'color' => ''),
                        'keyword1' => array('value' => $type, 'color' => ''),
                        'keyword2' => array('value' => $expireDate, 'color' => '',),
                        'remark' => array('value' => '', 'color' => '')
                    )
                ];
                $this->wechatTemplateMsg[] = ['msg' => $msg, 'cid' => $awoke['cid'],'id'=>$awoke['id']];
            }
            $_smsconf = $confRedis->hGetAll('wechat_config:' . $awoke['cid']);
            if (in_array('sms', $pushType) && $_smsconf && $_smsconf['sms_account'] && $_smsconf['sms_passcode'] && $_smsconf['sms_ip']) {
                // 短信推送
                $data = [];
                $smsContent = '您的车辆：【' . $awoke['RegisterNo'] . '】' . $type . '快到期了'; //短信内容
                $smsContent = '尊敬的' . $awoke['CustomerName'] . '，您的车辆：' . $awoke['RegisterNo'] . '】' . $type . ' 将于' . $expireDate . '到期';
                if ($awoke['ComNo']) {
                    $store = DB::table('store')->where('comno', $awoke['ComNo'])->where('cid', $awoke['cid'])->first();
                    if ($store) {
                        $data['store_id'] = $store['id'];
                        if ($store['tel']) $smsContent .= '，如需办理请联系：' . $store['tel'];
                    }
                }

                if ($_smsconf) {
                    if (trim($this->_smsconf['sms_sign'])) {
                        if ($this->_smsconf['sms_sign_location'] > 0) {
                            $smsContent = '&【' . $this->_smsconf['sms_sign'] . '】' . $smsContent;
                        } else {
                            $smsContent .= '【' . $this->_smsconf['sms_sign'] . '】';
                        }
                    }
                }

                $data['sms_num'] = ceil(mb_strlen($smsContent, 'UTF-8') / 60);
                # 发送短信
                $data['registerNo'] = $awoke['RegisterNo'];
                $data['customerName'] = $awoke['RegisterNo'] ?: $awoke['CustomerName'];
                $data['status'] = 0;
                $data['pid'] = 0;
                $data['type'] = $type;
                $data['content'] = $smsContent;
                $data['phone'] = $awoke['HandPhone'];
                $data['cid'] = $awoke['cid'];
                $data['addtime'] = $time;
                $data['store_id'] = 0;
                $this->smsRecords = [];

                # 梦网短信
                $smsApi = new Mwsms($this->_smsconf);
                if (!empty($this->_smsconf['sms_subaccount'])) {
                    $pszSubPort = $this->_smsconf['sms_subaccount'];
                } else {
                    $pszSubPort = false;
                }
                $smsResult = $smsApi->send($awoke['HandPhone'], $smsContent, 1, $pszSubPort);
            }
            $this->wechatTemplateMsg[] = $msg;
            
        });
        if($this->wechatTemplateMsg){
            $db=new DB();
            array_walk($this->wechatTemplateMsg,function($row,$index) use($db){
                $wechat = new Wechat($row['cid']);
                $result = $db->table('c_awoke')->where('id',$row['id'])->whereIn('BusinessType', ['年审', '保险', '证件'])->update(['PlanVisitDate' => date('Y-m-d')]);
                if (!$result) {
                    return;
                }
                $result = $wechat->sendTemplateMessage($row['msg']);
                if(!$result){
                    return false;
                }
            });
        }
        if ($this->smsRecords) {
            $result = DB::table('r_smsrecord')->insert($this->smsRecords);
            if (!$result) {
                return;
            }
        }
    }
}
