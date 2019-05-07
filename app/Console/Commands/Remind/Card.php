<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Card extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:couponcard';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '卡券到期提醒';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $db = null;
    protected $jobData = [];
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
        // $phones=$this->redis->sMembers('');
        $rows = DB::table('coupon_card')->select(DB::raw("COUNT(id) AS num,DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d'),NOW()) AS expire_day,FROM_UNIXTIME(expire_time,'%Y-%m-%d') AS expired,phone,cid,registerno,nickname"))->whereRaw("DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d %H:%I:%S'),NOW()) IN(30,15,7,3,1)")->where('remind_at', '!=', Carbon::now()->format('Y-m-d'))->groupBy('phone', 'cid')->get()->toArray();
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if (!$rows) return false;
        $redisExpireTime = strtotime(date("Y-m-d", strtotime("+1 day")));

        DB::beginTransaction();

        array_walk($rows, function ($row, $index) use ($redisExpireTime) {
            $row = get_object_vars($row);
            $redisSet = 'remind:couponcard:' . date('Ymd') . ':' . $row['cid'];
            $checkRedisMember = $this->redis->sIsMember($redisSet, $row['phone']);
            Log::info('sIsMember: ' . $redisSet . ' ' . $row['phone'] . ' result: ' . $checkRedisMember);
            if ($checkRedisMember) return false;
            $sAddRedis = $this->redis->sAdd($redisSet, $row['phone']);
            Log::info('sAdd: ' . $redisSet . ' ' . $row['phone'] . ' result: ' . $sAddRedis);
            if (!$sAddRedis) exit(); // 添加到集合失败

            $setExpireAt = $this->redis->expireAt($redisSet, $redisExpireTime);
            Log::info('expireAt: ' . $redisSet . ' result: ' . $setExpireAt);
            if (!$setExpireAt) {
                $result = $this->redis->sRem($redisSet, $row['phone']);
                Log::info('sRem: ' . $redisSet . ' ' . $row['phone'] . ' result: ' . $result);
                exit(); // 添加到集合失败
            }
            $cron = $this->cronConf($row['cid'], 'jobExpire');
            // 获取推送时间类型
            $days = $row['expire_day'];
            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                exit;
            }

            $storeInfo = '';
            $store = null;
            if (isset($row['comno']) && $row['comno']) {
                $store = DB::table('store')->where('comno', $row['comno'])->where('cid', $row['cid'])->first();
                if ($store) {
                    if ($store->tel) $storeInfo .= " 【" . $store->branch . "】 " . $store->tel;
                }
            }
            $pushType = explode(',', $cron['push_type']);
            $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['phone']])->first();
            Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'service_expire_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) { //
                
                $url = config('app.url') . '/user/coupon/couponList/amcc/' . $row['cid'];
                $customer = '尊敬的';
                $customer .= $row['registerno'] ? $row['registerno'] . '车主' : '客户';
                $num = $row['num'];
                $msg = array(
                    'touser' => $user->openid,
                    'template_id' => $tpl,
                    'url' => $url,
                    'data' => array(
                        'first' => array('value' => "{$customer}，您有{$num}张优惠券即将到期，请尽快使用哦！", 'color' => ''),
                        'keyword1' => array('value' => '优惠券', 'color' => ''),
                        'keyword2' => array('value' => $row['expired'], 'color' => '',),
                        'remark' => array('value' => "\n感谢选择我们的服务！\n" . $storeInfo, 'color' => '')
                    )
                );

                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => 'A00',
                    'property' => '卡券到期提醒',
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => 0,
                    'phone' => $row['phone'],
                    'function_code' => '',
                    'relation_code' => '',
                    'job' => json_encode($msg, JSON_UNESCAPED_UNICODE),
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];
            }
            $result = DB::table('coupon_card')->whereRaw("DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d %H:%I:%S'),NOW()) IN(30,15,7,3,1)")->where('phone', $row['phone'])->update(['remind_at' => date('Y-m-d')]);

            if (!$result) {
                Log::info('update coupon_card remind_at sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
                Log::info('update coupon_card remind_at fail');
                DB::rollBack();
                exit();
            }
        });
        if (empty($this->jobData)) {
            return false;
        }

        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            Log::info('create job fail');
            DB::rollBack();
            return false;
        }
        DB::commit();
        Log::info('remind coupon card end success');
    }
}
