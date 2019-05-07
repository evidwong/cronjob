<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Package extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:package';

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
            $cron = $this->cronConf($row['cid'],'packageStatus');
            // 获取推送时间类型
            $days = floor((strtotime(date('Y-m-d', strtotime($row['LimitDate']))) - strtotime(date('Y-m-d'))) / 86400);

            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                exit;
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
            $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['HandPhone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'package_status_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) {
                // 默认微信推送，或设置了有微信推送
                $msg = [
                    'touser' => $user->openid,
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
                    'comno' => $row['COMNo'],
                    'property' => '套餐到期提醒',
                    'type' => 'sms',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone'=>$row['HandPhone'],
                    'function_code' => '',
                    'relation_code' => '',
                    'job' => json_encode($msg, JSON_UNESCAPED_UNICODE),
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'status' => 0,
                    'opt_uid' => 0,
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
                    $store = DB::table('store')->where('comno', $row['COMNo'])->where('cid', $row['cid'])->first();
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
                    'comno' => $row['COMNo']?:'A00',
                    'property' => $type . '到期提醒',
                    'type' => 'sms',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone'=>$row['HandPhone'],
                    'flag_num'=>$data['sms_num'],
                    'function_code' => '',
                    'relation_code' => '',
                    'job' => json_encode($sendInfo, JSON_UNESCAPED_UNICODE),
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'status' => 0,
                    'opt_uid' => 0,
                ];
            }
        });
        if (empty($this->jobData)) {
            return false;
        }
        DB::beginTransaction();
        $result = DB::table('cron_job')->insert($this->jobData);
        if (!$result) {
            Log::info('create job fail');
            DB::rollBack();
            return false;
        }
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if ($this->smsRecords) {
            $result = DB::table('r_smsrecord')->insert($this->smsRecords);
            Log::info('insert sms record: ' . $result);
            if (!$result) {
                Log::info('insert sms record fail');
                DB::rollBack();
                return false;
            }
        }
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        DB::commit();
        Log::info('End success');
    }
}
