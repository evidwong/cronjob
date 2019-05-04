<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Package extends Base
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expire:package';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '套餐到期提醒';

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
        if (!$this->redis || !$this->confRedis) return false;
        // Log::useDailyFiles(storage_path('logs/job/expire_awoke.log'));
        DB::enableQueryLog();
        $MemberSetSalesCodes = $this->redis->hGetAll('MemberSetSalesM:MemberSetSalesCode:'.date('Ymd'));
        if($MemberSetSalesCodes){
            $rows = DB::table('c_membersetsalesm')->whereRaw("TIMESTAMPDIFF(DAY,'" . date('Y-m-d H:i:s') . "',LimitDate) IN(30,15,7,3,1)")->whereNotIn('MemberSetSalesCode',$MemberSetSalesCodes)->get();
        }else{
            $rows = DB::table('c_membersetsalesm')->whereRaw("TIMESTAMPDIFF(DAY,'" . date('Y-m-d H:i:s') . "',LimitDate) IN(30,15,7,3,1)")->get();
        }
        if($rows->isEmpty()) return;
        $rows=$rows->toArray();
        array_walk($rows,function($row,$index) {
            // 获取定时任务配置
            $cron = $this->redis->hGet('cron_config', 'company:' . $row['cid']);
            // 获取推送时间类型
            $step = explode(',', $cron['packageStatus']['expire_step']);
            $days = ceil((strtotime($row['returnPlanDate']) - time()) / 86400);
            if (!$cron || strtotime($cron['packageStatus']['start_time']) > time() || strtotime($cron['packageStatus']['end_time']) < time() || $cron['status'] <= 0 || ($step && !in_array($days, $step)) || ($cron['packageStatus']['start_at'] && date('H:i:s') < $cron['packageStatus']['start_at']) || ($cron['packageStatus']['end_at'] && date('H:i:s') > $cron['packageStatus']['end_at'])) {
                // 获取不到“证件提醒”的推送设置；开始、结束时间不符合设置要求；已禁用；日期时间不符合推送设置要求；当前不符合推送时间设置要求
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }
            $title = '';
            $customer = '尊敬的';
            $customer .= $row['CustomerName'] ? : '客户';
            $title .= $customer.'，您的';
            $title .= '服务 ' . $row['MemberSetName'];
            $title .= ' 套餐';
            $type = '套餐';
            $expireDate = date('Y-m-d', strtotime($row['LimitDate']));
            $title .= '即将到期';
            $pushType = explode(',', $cron['push_type']);
            $user = $this->mydb->table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['HandPhone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'credentials_notice');
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
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'job_from_id' => $row['id'],
                    'job_property' => 'push',
                    'job_type' => 'wechat',
                    'job_content' => json_encode($msg, JSON_UNESCAPED_UNICODE),
                    'create_at' => Carbon::now(),
                    'comno' => $row['COMNo'],
                    'opt_uid' => 0,
                    'status' => 0
                ];
            }
            $time=time();
            $_conf = $this->confRedis->hGetAll('wechat_config:' . $row['cid']);
            if (in_array('sms', $pushType) && $row['HandPhone'] &&  $_conf && $_conf['sms_account'] && $_conf['sms_passcode'] && $_conf['sms_ip']) {
                // 短信推送
                $data = [];
                $customer = '尊敬的';
                $customer .= $row['CustomerName'] ? : '客户';
                $smsContent = '尊敬的' . $customer . '，您的车辆：' . $row['RegisterNo'] . ' ' . $type . '将于' . $expireDate . '到期';
                if ($row['COMNo']) {
                    $store = $this->db->table('store')->where('comno', $row['COMNo'])->where('cid', $row['cid'])->first();
                    if ($store) {
                        $data['store_id'] = $store->id;
                        $data['store_name'] = $store->branch;
                        if ($store->tel) $smsContent .= '，如需办理请联系：' . $store->tel;
                    }
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
                $data['addtime'] = $time;
                $this->smsRecords[] = $data;
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'job_from_id' => $row['id'],
                    'job_property' => 'push',
                    'job_type' => 'sms',
                    'job_content' => json_encode($sendInfo, JSON_UNESCAPED_UNICODE),
                    'create_at' => Carbon::now(),
                    'comno' => $row['COMNo'],
                    'opt_uid' => 0,
                    'status' => 0
                ];
            }
        });
        if (empty($this->jobData)) {
            return false;
        }
        $this->db->beginTransaction();
        $result = $this->db->table('cron_job')->insert($this->jobData);
        if (!$result) {
            Log::info('create job fail');
            $this->db->rollBack();
            return false;
        }
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if ($this->smsRecords) {
            $result = $this->db->table('r_smsrecord')->insert($this->smsRecords);
            Log::info('insert sms record: ' . $result);
            if (!$result) {
                Log::info('insert sms record fail');
                $this->db->rollBack();
                return false;
            }
        }
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        $this->db->commit();
        Log::info('End success');
    }
}
