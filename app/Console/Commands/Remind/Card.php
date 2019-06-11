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
        $rows = DB::table('coupon_card')->select(DB::raw("COUNT(id) AS num,DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d'),NOW()) AS expire_day,FROM_UNIXTIME(expire_time,'%Y-%m-%d') AS expired,phone,cid,registerno,nickname"))->whereRaw("DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d %H:%I:%S'),NOW()) IN(30,15,7,3,1)")->where('remind_at', '!=', Carbon::now()->format('Y-m-d'))->groupBy('phone', 'cid')->get()->toArray();
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        if (!$rows) return false;
        $redisExpireTime = strtotime(date("Y-m-d", strtotime("+1 day")));


        $phones = [];
        array_walk($rows, function ($row, $index) use (&$phones) {
            $row = json_decode(json_encode($row), true);
            $redisSet = 'remind:couponcard:' . date('Ymd') . ':' . $row['cid'];
            $isMember = $this->redis->sIsMember($redisSet, $row['phone']);
            if ($isMember) return false;
            $cron = $this->cronConf($row['cid'], 'jobExpire');
            // 获取推送时间类型
            $days = $row['expire_day'];
            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }

            $company = $this->confRedis->hGetAll('company:' . $row['cid']);
            if (!$company) return false;

            $phones[$row['cid']][] = $row['phone'];

            $storeInfo = '';
            $store = null;
            if (isset($row['comno']) && $row['comno']) {
                $store = DB::table('store')->where('comno', $row['comno'])->where('cid', $row['cid'])->first();
                if ($store) {
                    if ($store->tel) $storeInfo .= "如有问题请联系 【" . $store->branch . "】 " . $store->tel;
                }
            } else {
                if ($company['tel']) $storeInfo .= '如有问题请联系：' . $company['tel'];
            }
            $pushType = explode(',', $cron['push_type']);
            // $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['phone']])->first();
            $user = Member::where('phone', $row['phone'])->where('cid', $row['cid'])->with('wechat')->first();
            Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'service_expire_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) { //

                $url = config('app.url') . '/User/Coupon/couponList/amcc/' . $row['cid'];
                $customer = '尊敬的客户';
                $num = $row['num'];
                $msg = array(
                    'touser' => $user->wechat->openid,
                    'template_id' => $tpl,
                    'url' => $url,
                    'data' => array(
                        'first' => array('value' => "{$customer}，您有{$num}张优惠券即将到期，请尽快使用哦！", 'color' => ''),
                        'keyword1' => array('value' => '优惠券', 'color' => ''),
                        'keyword2' => array('value' => $row['expired'], 'color' => '',),
                        'remark' => array('value' => "\n感谢选择我们的服务！\n" . $storeInfo, 'color' => '')
                    )
                );

                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                $index = "wechat:" . md5($msg . microtime(true));
                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => 'A00',
                    'property' => '卡券到期提醒',
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => 0,
                    'phone' => $row['phone'],
                    'limit_at' => $row['expired'],
                    'function_code' => '',
                    'relation_code' => '',
                    'redis_key_index' => $index,
                    'job' => $msg,
                    'fail_content' => '',
                    'create_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];

                $redisIndexContent = [
                    'cid' => $row['cid'],
                    'comno' => 'A00',
                    'type' => 'wechat',
                    'phone' => $row['phone'],
                    'smsnum' => 0,
                    'job' => $msg,
                    'jobtype' => 'couponCard'
                ];
                $this->redisIndex[] = $index;
                $this->redisContent[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
        });
        if (empty($this->jobData)) {
            return false;
        }

        DB::beginTransaction();
        array_walk($phones, function ($row, $cid) use ($redisExpireTime) {
            $redisSet = 'remind:couponcard:' . date('Ymd') . ':' . $cid;
            array_map(function ($v) use ($redisSet) {
                $this->redis->sAdd($redisSet, $v);
            }, $row);


            $this->redis->expireAt($redisSet, $redisExpireTime);
            $result = DB::table('coupon_card')->whereRaw("DATEDIFF(FROM_UNIXTIME(expire_time,'%Y-%m-%d %H:%I:%S'),NOW()) IN(30,15,7,3,1)")->whereIn('phone', $row)->update(['remind_at' => date('Y-m-d')]);

            if (!$result) {
                array_map(function ($v) use ($redisSet) {
                    $this->redis->sRem($redisSet, $v);
                }, $row);
                Log::info('update coupon_card remind_at sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
                Log::info('update coupon_card remind_at fail');
                DB::rollBack();
                exit();
            }
        });
        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            array_walk($phones, function ($row, $cid) {
                $redisSet = 'remind:couponcard:' . date('Ymd') . ':' . $cid;
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
            $result = $this->redis->mSet($this->wechatList);
            if (!$result) {
                DB::rollBack();
                array_walk($phones, function ($row, $cid) {
                    $redisSet = 'remind:couponcard:' . date('Ymd') . ':' . $cid;
                    array_map(function ($v) use ($redisSet) {
                        $this->redis->sRem($redisSet, $v);
                    }, $row);
                });
                return false;
            }
        }
        DB::commit();
        Log::info('remind coupon card end success');
    }
}
