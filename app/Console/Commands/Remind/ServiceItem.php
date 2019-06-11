<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Member;

class ServiceItem extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:service_item';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '服务项目到期提醒';

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
        // Log::useDailyFiles(storage_path('logs/job/expire_awoke.log'));
        DB::enableQueryLog();
        //$rows = DB::table('c_awoke')->whereRaw("DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1)")->whereNotIn('BusinessType', ['年审', '保险', '证件'])->whereRaw("DATEDIFF(IFNULL(PlanVisitDate,'1970-01-01 00:00:00'),NOW())!=0")->groupBy('CustomerCode', 'Phone')->get()->toArray();
        $rows = DB::select("SELECT * FROM `c_awoke` WHERE id IN(SELECT MAX(id) AS id FROM `c_awoke` WHERE DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1) AND BusinessType NOT IN('年审', '保险', '证件') GROUP BY cid,RegisterNo,BusinessType)");

        // dd(DB::getQueryLog());
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        Log::info('data：' . json_encode($rows, JSON_UNESCAPED_UNICODE));
        if (!$rows) return false;
        // dd($rows);
        $redisMembers = "remind:serviceitem:expire:" . date('Ymd');
        $time = time();
        $temps = [];
        $actionId = [];
        $vehicles = [];
        array_walk($rows, function ($row, $index) use ($time, &$temps, &$actionId, $redisMembers, &$vehicles) {
            $row = json_decode(json_encode($row), true);
            $isMember = $this->redis->sIsMember($redisMembers, $row['id']);
            if ($isMember) return false;
            // $row = get_object_vars($row);
            // 获取定时任务配置
            $cron = $this->cronConf($row['cid'], 'jobExpire');
            // 获取推送时间类型
            $days = floor((strtotime(date('Y-m-d', strtotime($row['BookingDate']))) - strtotime(date('Y-m-d'))) / 86400);
            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }
            $type = (isset($row['JobName']) && $row['JobName']) ? $row['JobName'] : '';
            if (!$type) return false;
            $actionId[] = $row['id'];
            if (!$row['PlanVisitDate'] || $row['PlanVisitDate'] || strtotime($row['PlanVisitDate']) < $time) {
                $vehicles[] = $row['id'];
            }
            $company = $this->confRedis->hGetAll('company:' . $row['cid']);
            if (!$company) return false;
            $customer = $row['CustomerName'] ?: '客户';

            $type = '服务项目';
            $expireDate = date('Y-m-d', strtotime($row['BookingDate']));

            $pushType = explode(',', $cron['push_type']);
            // $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['HandPhone']])->first();
            $user = Member::where('phone', $row['HandPhone'])->where('cid', $row['cid'])->with('wechat')->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'service_expire_notice');
            if ($row['ComNo']) {
                $store = DB::table('store')->where('comno', $row['ComNo'])->where('cid', $row['cid'])->first();
            }
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) { //
                // 默认微信推送，或设置了有微信推送
                $title = '';
                $title .= '尊敬的' . $customer . '客户，您的';
                $title .= '车辆有服务项目';
                $title .= "即将到期\n";
                $title .= $row['RegisterNo'] ? '车牌号码：' . $row['RegisterNo'] : '';
                $remark = '';
                if ($store) {
                    if ($store->tel) $remark .= '如有问题请联系：' . $store->tel;
                } else {
                    if ($company['tel']) $remark .= '如有问题请联系：' . $company['tel'];
                }
                $msg = [
                    'touser' => $user->wechat->openid,
                    'template_id' => $tpl,
                    'url' => config('app.url') . '/User/Notifycenter/index/amcc/' . $row['cid'],
                    'data' => array(
                        'first' => array('value' => $title, 'color' => ''),
                        'keyword1' => array('value' => $row['JobName'], 'color' => ''),
                        'keyword2' => array('value' => $expireDate, 'color' => '',),
                        'remark' => array('value' => $remark, 'color' => '')
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
                    'flag_num' => 0,
                    'flag_time' => $time,
                    'limit_at' => $row['BookingDate'],
                    'phone' => $row['HandPhone'],
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
                    'smsnum' => 0,
                    'job' => $msg,
                    'jobtype' => 'jobExpire'
                ];

                $this->wechatIndex[] = $index;
                $this->wechatList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
            $_conf = $this->confRedis->hGetAll('wechat_config:' . $row['cid']);
            if (in_array('sms', $pushType) && $row['HandPhone'] &&  $_conf && $_conf['sms_account'] && $_conf['sms_passcode'] && $_conf['sms_ip']) {
                // 短信推送
                $data = [];
                $customer = '尊敬的';
                $customer .= $row['CustomerName'] ?: '客户';
                $smsContent = $customer . '，您的：' . $row['RegisterNo'] . ' ' . $row['JobName'] . ' ' . $expireDate . '到期';

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
                $index = "sms:" . $row['cid'] . ":" . md5($sendInfo . microtime(true));

                $data['sms_num'] = ceil(mb_strlen($smsContent, 'UTF-8') / 60);
                # 发送短信
                $data['registerNo'] = $row['RegisterNo'];
                $data['customerName'] = $row['RegisterNo'] ?: $row['CustomerName'];
                $data['status'] = 0;
                $data['pid'] = 0;
                $data['type'] = $type;
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
                    'flag_num' => $data['sms_num'],
                    'flag_time' => $time,
                    'limit_at' => $row['BookingDate'],
                    'phone' => $row['HandPhone'],
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
                    'smsnum' => $data['sms_num'],
                    'job' => $smsContent,
                    'jobtype' => 'jobExpire'
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
        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            Log::info('create job fail');
            DB::rollBack();
            return false;
        }
        // $result = DB::table('c_awoke')->whereRaw("DATEDIFF(BookingDate,NOW()) IN(30,15,7,3,1)")->whereRaw("DATEDIFF(IFNULL(PlanVisitDate,'1970-01-01 00:00:00'),NOW())!=0")->whereNotIn('BusinessType', ['年审', '保险', '证件'])->whereIn('HandPhone', array_column($rows, 'HandPhone'))->update(['PlanVisitDate' => date('Y-m-d')]);

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
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        DB::commit();
        Log::info('End success');
    }
}
