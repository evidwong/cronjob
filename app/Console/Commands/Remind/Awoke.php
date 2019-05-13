<?php

namespace App\Console\Commands\Remind;

use App\Models\Awoke as AwokeModel;
use App\Libs\Wechat;
use App\Libs\Mwsms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class Awoke extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'remind:awoke';

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
    protected $jobData = [];

    protected $db = null;
    public function __construct()
    {
        parent::__construct();
        $this->db = new DB();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->redis || !$this->confRedis) return false;
        DB::enableQueryLog();
        $this->redis->select(10);
        $redisMembers = "remind:credentials:expire:" . date('Ymd');
        // $vehicles = $this->redis->sMembers($redisMembers);
        // if ($vehicles) {
        //     $rows = AwokeModel::whereRaw("DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1)")->whereIn('BusinessType', ['年审', '保险', '证件'])->get()->toArray();
        // } else {
        // }
        $rows = DB::select("SELECT * FROM `c_awoke` WHERE id IN(SELECT MAX(id) AS id FROM `c_awoke` WHERE DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1) AND BusinessType IN('年审', '保险', '证件') GROUP BY cid,RegisterNo,BusinessType)");
        // $rows = AwokeModel::whereRaw("DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1)")->whereIn('BusinessType', ['年审', '保险', '证件'])->orderBy('id','DESC')->get()->toArray();
        // dd($rows);
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if (!$rows) return false;

        $time = time();
        $temps = [];
        $actionId = [];
        $vehicles = [];
        array_walk($rows, function ($row, $index) use ($time, &$temps, &$actionId, &$vehicles, $redisMembers) {
            $row = json_decode(json_encode($row), true);
            $isMember = $this->redis->sIsMember($redisMembers, $row['id']);
            if ($isMember) return false;
            // 获取定时任务配置
            $cron = $this->cronConf($row['cid'], 'credentialsExpire');
            // 获取推送时间类型
            $days = floor((strtotime(date('Y-m-d', strtotime($row['BookingDate']))) - strtotime(date('Y-m-d'))) / 86400);
            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }
            $company = $this->confRedis->hGetAll('company:' . $row['cid']);
            if (!$company) return false;

            if ($row['ComNo']) {
                $store = DB::table('store')->where('comno', $row['ComNo'])->where('cid', $row['cid'])->first();
            }
            $customer = $row['CustomerName'] ?: '客户';
            $type = $row['BusinessType'];
            if (!$type) return false;
            $actionId[] = $row['id'];
            if (!$row['PlanVisitDate'] || $row['PlanVisitDate']=='0000-00-00 00:00:00' || strtotime($row['PlanVisitDate']) < $time) {
                $vehicles[] = $row['id'];
            }

            $expireDate = date('Y-m-d', strtotime($row['BookingDate']));

            $pushType = explode(',', $cron['push_type']);
            $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['HandPhone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'credentials_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) { //
                // 默认微信推送，或设置了有微信推送
                $title = '';
                $title .= '尊敬的' . $customer . '，您的车辆 ' . $row['RegisterNo'] . ' ' . $type;
                $title .= '即将到期';
                $remark = '';
                if ($store) {
                    if ($store->tel) $remark .= '如有问题请联系：' . $store->tel;
                } else {
                    if ($company['tel']) $remark .= '如有问题请联系：' . $company['tel'];
                }
                $msg = [
                    'touser' => $user->openid,
                    'template_id' => $tpl,
                    'url' => config('app.url') . '/User/Notifycenter/index/amcc/' . $row['cid'],
                    'data' => array(
                        'first' => array('value' => $title, 'color' => ''),
                        'keyword1' => array('value' => $type, 'color' => ''),
                        'keyword2' => array('value' => $expireDate, 'color' => '',),
                        'remark' => array('value' => "\n感谢选择我们的服务！\n" . $remark, 'color' => '')
                    )
                ];
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                $index = "wechat:" . $row['cid'] . ":" . md5($msg . microtime(true));
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => $row['ComNo'] ?: 'A00',
                    'property' => $type . '到期提醒',
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone' => $row['HandPhone'],
                    'flag_num' => 0,
                    'flag_time' => $time,
                    'limit_at' => $row['BookingDate'],
                    'relation_code' => $row['AwokeListCode'],
                    'redis_key_index' => $index,
                    'job' => $msg,
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];
                $redisIndexContent = [
                    'cid' => $row['cid'],
                    'type' => 'wechat',
                    'comno' => $row['ComNo'] ?: 'A00',
                    'phone' => $row['HandPhone'],
                    'smsnum'=>0,
                    'job' => $msg,
                ];

                $this->wechatIndex[] = $index;
                $this->wechatList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
            $_conf = $this->confRedis->hGetAll('wechat_config:' . $row['cid']);
            if (in_array('sms', $pushType) && $row['HandPhone'] &&  $_conf && $_conf['sms_account'] && $_conf['sms_passcode'] && $_conf['sms_ip']) {
                // 短信推送
                $data = [];
                $smsContent = $customer . '，您的：' . $row['RegisterNo'] . ' ' . $type . ' ' . $expireDate . '到期';

                if ($store) {
                    $data['store_id'] = $store->id;
                    $data['store_name'] = $store->branch;
                    if ($store->tel) $smsContent .= '，请联系：' . $store->tel;
                } else {
                    if ($company['tel']) $smsContent .= '，请联系：' . $company['tel'];
                }
                // 短信签名
                if (trim($_conf['sms_sign'])) {
                    if ($_conf['sms_sign_location'] > 0) {
                        $smsContent = '&【' . $_conf['sms_sign'] . '】' . $smsContent;
                    } else {
                        $smsContent .= '【' . $_conf['sms_sign'] . '】';
                    }
                }
                $sendInfo = (mktime(true) * 1000) . '|' . (empty($_conf['sms_subaccount']) ? '*' : $_conf['sms_subaccount']) . '|' . $row['HandPhone'] . '|' . base64_encode(iconv('UTF-8', 'GBK//IGNORE', $smsContent));

                $temps[$row['cid']][] = $sendInfo;
                $index = "sms:" . $row['cid'] . ":" . md5($smsContent . microtime(true));

                $data['sms_num'] = ceil(mb_strlen($smsContent, 'UTF-8') / 60);
                # 发送短信
                $data['registerNo'] = $row['RegisterNo'];
                $data['customerName'] = $row['RegisterNo'] ?: $row['CustomerName'];
                $data['status'] = 0;
                $data['pid'] = 0;
                $data['type'] = $type . '到期提醒';
                $data['content'] = $smsContent;
                $data['phone'] = $row['HandPhone'];
                $data['cid'] = $row['cid'];
                $data['flag_content'] = $index;
                $data['addtime'] = $time;
                $this->smsRecords[] = $data;

                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => $row['ComNo'] ?: 'A00',
                    'property' => $type . '到期提醒',
                    'type' => 'sms',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone' => $row['HandPhone'],
                    'flag_num' => $data['sms_num'],
                    'flag_time' => $time,
                    'limit_at' => $row['BookingDate'],
                    'relation_code' => $row['AwokeListCode'],
                    'redis_key_index' => $index,
                    'job' => $smsContent,
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];
                $redisIndexContent = [
                    'cid' => $row['cid'],
                    'type' => 'sms',
                    'comno' => $row['ComNo'] ?: 'A00',
                    'phone' => $row['HandPhone'],
                    'smsnum'=>$data['sms_num'],
                    'job' => $smsContent,
                ];
                $this->smsIndex[] = $index;
                $this->smsList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
        });
        if (empty($this->jobData)) {
            return false;
        }
        array_map(function ($v) use ($redisMembers) {
            $this->redis->sAdd($redisMembers, $v);
        }, $actionId);
        $redisExpireTime = strtotime(date("Y-m-d", strtotime("+1 day")));
        $this->redis->expireAt($redisMembers, $redisExpireTime);

        DB::beginTransaction();
        // 写入推送任务
        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            Log::info('create job fail');
            DB::rollBack();
            array_map(function ($v) use ($redisMembers) {
                $this->redis->sRem($redisMembers, $v);
            }, $actionId);
            return false;
        }
        // 如果有需要更新计划回访时间的，更新
        if (!empty($vehicles)) {
            $result = DB::table('c_awoke')->whereIn('id', $actionId)->update(['PlanVisitDate' => date('Y-m-d')]);
            if (!$result) {
                Log::info('update c_awoke planvisitdate fail');
                DB::rollBack();
                array_map(function ($v) use ($redisMembers) {
                    $this->redis->sRem($redisMembers, $v);
                }, $actionId);
                exit();
            }
        }

        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));

        /** 
         * 短信记录写入数据库 
         */
        if ($this->smsRecords) {
            $result = DB::table('r_smsrecord')->insert($this->smsRecords);
            Log::info('insert sms record: ' . $result);
            if (!$result) {
                Log::info('insert sms record fail');
                DB::rollBack();
                array_map(function ($v) use ($redisMembers) {
                    $this->redis->sRem($redisMembers, $v);
                }, $actionId);
                return false;
            }
        }
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));

        /** 
         * 推送任务写入Redis 
         */
        if ($this->wechatIndex) {
            array_unshift($this->wechatIndex, 'wechat:message:template');
            call_user_func_array([$this->redis, 'lPush'], $this->wechatIndex);
        }
        if ($this->smsIndex) {
            array_unshift($this->smsIndex, 'sms:message');
            call_user_func_array([$this->redis, 'lPush'], $this->smsIndex);
        }
        if ($this->wechatIndex || $this->smsIndex) {
            $result = $this->redis->mSet(array_merge($this->wechatList, $this->smsList));
            if (!$result) {
                DB::rollBack();
                array_map(function ($v) use ($redisMembers) {
                    $this->redis->sRem($redisMembers, $v);
                }, $actionId);
                return false;
            }
        }
        DB::commit();
        Log::info('remind awoke end success');
    }
}
