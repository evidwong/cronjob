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
    protected $jobData = [];
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
        DB::enableQueryLog();
        $rows = DB::table('c_membersetsalesm')->whereRaw("TIMESTAMPDIFF(DAY,'" . date('Y-m-d H:i:s') . "',LimitDate) IN(30,15,7,3,1)")->groupBy('MemberSetCode', 'cid')->get()->toArray();
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if (!$rows) return;
        $MemberSetCode = [];
        $redisExpireTime = strtotime(date("Y-m-d", strtotime("+1 day")));
        array_walk($rows, function ($row, $index) use (&$MemberSetCode) {
            $row = get_object_vars($row);
            $redisSet = 'remind:membersetsalescode:' . date('Ymd') . ':' . $row['cid'];
            $this->redis->sIsMember($redisSet, $row['MemberSetCode']);
            $isMember = $this->redis->sIsMember($redisSet, $row['MemberSetCode']);
            if ($isMember) return false;
            // 获取定时任务配置
            $cron = $this->cronConf($row['cid'], 'packageStatus');
            // 获取推送时间类型
            $days = floor((strtotime(date('Y-m-d', strtotime($row['LimitDate']))) - strtotime(date('Y-m-d'))) / 86400);

            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                exit;
            }
            $company = $this->confRedis->hGetAll('company:' . $row['cid']);
            if (!$company) return false;
            $MemberSetCode[$row['cid']] = $row['MemberSetCode'];
            $storeInfo = '';
            if (isset($row['COMNo']) && $row['COMNo']) {
                $store = DB::table('store')->where('comno', $row['COMNo'])->where('cid', $row['cid'])->first();
                if ($store) {
                    if ($store->tel) $storeInfo .= "如有问题请联系 【" . $store->branch . "】 " . $store->tel;
                }
            } else {
                if ($company['tel']) $storeInfo .= '如有问题请联系：' . $company['tel'];
            }
            $title = '尊敬的客户，您的套餐即将到期';
            $type = '套餐';
            $expireDate = date('Y-m-d', strtotime($row['LimitDate']));
            $pushType = explode(',', $cron['push_type']);
            $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['HandPhone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'package_status_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) {
                // 默认微信推送，或设置了有微信推送
                $msg = [
                    'touser' => $user->openid,
                    'template_id' => $tpl,
                    'url' => config('app.url') . '/User/Package/index/amcc/' . $row['cid'],
                    'data' => array(
                        'first' => array('value' => $title, 'color' => ''),
                        'keyword1' => array('value' => $row['CustomerName'], 'color' => ''),
                        'keyword2' => array('value' => $row['MemberSetName'], 'color' => '',),
                        'keyword3' => array('value' => $expireDate, 'color' => '',),
                        'keyword4' => array('value' => '即将到期', 'color' => '',),
                        'remark' => array('value' => "\n感谢选择我们的服务！\n" . $storeInfo, 'color' => '')
                    )
                ];
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                $index = "wechat:" . $row['cid'] . ":" . md5($msg . microtime(true));
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => $row['COMNo'],
                    'property' => '套餐到期提醒',
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone' => $row['HandPhone'],
                    'limit_at' => $row['LimitDate'],
                    'function_code' => '',
                    'relation_code' => '',
                    'redis_key_index' => $index,
                    'job' => $msg,
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'status' => 0,
                    'opt_uid' => 0,
                ];
                $redisIndexContent = [
                    'cid' => $row['cid'],
                    'type' => 'wechat',
                    'comno' => $row['COMNo'] ?: 'A00',
                    'phone' => $row['HandPhone'],
                    'job' => $msg,
                ];

                $this->wechatIndex[] = $index;
                $this->wechatList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
            $time = time();
            $_conf = $this->confRedis->hGetAll('wechat_config:' . $row['cid']);
            if (in_array('sms', $pushType) && $row['HandPhone'] &&  $_conf && $_conf['sms_account'] && $_conf['sms_passcode'] && $_conf['sms_ip']) {
                // 短信推送
                $data = [];
                $smsContent = '尊敬的客户，您的 ' . $row['MemberSetName'] . ' 套餐将于' . $expireDate . '到期';
                if ($store) {
                    $data['store_id'] = $store->id;
                    $data['store_name'] = $store->branch;
                    if ($store->tel) $smsContent .= '，请联系：' . $store->tel;
                } else {
                    if ($company['tel']) $smsContent .= '请联系：' . $company['tel'];
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

                $index = "sms:" . $row['cid'] . ":" . md5($sendInfo . microtime(true));
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => $row['COMNo'] ?: 'A00',
                    'property' => $type . '到期提醒',
                    'type' => 'sms',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone' => $row['HandPhone'],
                    'flag_num' => $data['sms_num'],
                    'limit_at' => $row['LimitDate'],
                    'function_code' => '',
                    'relation_code' => '',
                    'redis_key_index' => $index,
                    'job' => $sendInfo,
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'status' => 0,
                    'opt_uid' => 0,
                ];
                $redisIndexContent = [
                    'cid' => $row['cid'],
                    'type' => 'sms',
                    'comno' => $row['ComNo'] ?: 'A00',
                    'phone' => $row['HandPhone'],
                    'job' => $sendInfo,
                ];

                $this->smsIndex[] = $index;
                $this->smsList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
        });
        if (empty($this->jobData)) {
            return false;
        }
        array_walk($MemberSetCode, function ($row, $cid) use ($redisExpireTime) {
            $redisSet = 'remind:membersetsalescode:' . date('Ymd') . ':' . $cid;
            array_map(function ($v) use ($redisSet) {
                $this->redis->sAdd($redisSet, $v);
            }, $row);
            $this->redis->expireAt($redisSet, $redisExpireTime);
        });
        DB::beginTransaction();
        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            array_walk($MemberSetCode, function ($row, $cid) {
                $redisSet = 'remind:membersetsalescode:' . date('Ymd') . ':' . $cid;
                array_map(function ($v) use ($redisSet) {
                    $this->redis->sRem($redisSet, $v);
                }, $row);
            });
            Log::info('create job fail');
            DB::rollBack();
            return false;
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
                array_walk($MemberSetCode, function ($row, $cid) {
                    $redisSet = 'remind:membersetsalescode:' . date('Ymd') . ':' . $cid;
                    array_map(function ($v) use ($redisSet) {
                        $this->redis->sRem($redisSet, $v);
                    }, $row);
                });
                DB::rollBack();
                return false;
            }
        }
        DB::commit();
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        Log::info('End success');
    }
}
