<?php

namespace App\Console\Commands\Remind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class Reception extends Remind
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:reception';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '工单回访提醒';

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
        // Log::useDailyFiles(storage_path('logs/job/expire_awoke.log'));
        DB::enableQueryLog();
        $receptionIdRedis = 'return:visit:' . date('Ymd');
        $ids = $this->redis->sMembers($receptionIdRedis);
        if ($ids) {
            $rows = DB::table('return_vist_plan')->whereRaw("DATEDIFF(returnPlanDate,NOW()) IN(30,15,7,3,1)")->whereNotIn('id', $ids)->get()->toArray();
        } else {
            $rows = DB::table('return_vist_plan')->whereRaw("DATEDIFF(returnPlanDate,NOW()) IN(30,15,7,3,1)")->get()->toArray();
        }
        Log::info('sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        // dd($rows);
        if (!$rows) return;
        $actionId = [];
        $redisExpireTime = strtotime(date("Y-m-d", strtotime("+1 day")));
        array_walk($rows, function ($row, $index) use ($receptionIdRedis, &$actionId) {
            $row = json_decode(json_encode($row), true);
            $checkRedisMember = $this->redis->sIsMember($receptionIdRedis, $row['id']);
            if ($checkRedisMember) return false;
            // 获取定时任务配置
            $cron = $this->cronConf($row['cid'], 'needVisit');
            // 获取推送时间类型
            $days = floor((strtotime(date('Y-m-d', strtotime($row['returnPlanDate']))) - strtotime(date('Y-m-d'))) / 86400);
            $check = $this->checkCondition($cron, $days);
            if (!$check) {
                Log::info('不符合推送设置要求: ' . $row['cid']);
                return false;
            }
            $customer = $row['CustomerName'] ?: '客户';
            $reception = DB::table('c_receptionm')->where('id', $row['worker_id'])->first();
            if (!$reception) return false;

            $actionId[] = $row['id'];
            $title = "服务回访\n";
            $remark = '尊敬的';
            $remark .= $customer;
            $remark .= '，烦请点击‘详情’对我们的服务进行评价。感谢您选择我们服务，祝生活愉快！';
            $type = '服务回访';
            $orderTime = date('Y-m-d', strtotime($reception->InDate));
            $pushType = isset($cron['push_type']) ? explode(',', $cron['push_type']) : [];
            $user = DB::table('member_openid')->where(['cid' => $row['cid'], 'phone' => $row['phone']])->first();
            $tpl = $this->confRedis->hGet('wechat_template:' . $row['cid'], 'order_evaluation_notice');
            if ((!$pushType || in_array('wechat', $pushType)) && $user && $tpl) {
                // 默认微信推送，或设置了有微信推送
                $msg = [
                    'touser' => $user->openid,
                    'template_id' => $tpl,
                    'url' => config('app.url') . "/User/Order/detail/amcc/" . $row['cid'] . ".html?orderid=" + $reception->CReceptionCode + "&recdate=" + $reception->RecDate + "&functioncode=" + $reception->FunctionCode,
                    'data' => array(
                        'first' => array('value' => $title, 'color' => ''),
                        'keyword1' => array('value' => $reception->CReceptionCode, 'color' => ''),
                        'keyword2' => array('value' => $orderTime, 'color' => '',),
                        'remark' => array('value' => $remark, 'color' => '')
                    )
                ];
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
                $index = "wechat:" . $row['cid'] . ":" . md5($msg . microtime(true));

                $this->jobData[] = [
                    'cid' => $row['cid'],
                    'comno' => $reception->COMNo,
                    'property' => $type,
                    'type' => 'wechat',
                    'action' => 'push',
                    'from_id' => $row['id'],
                    'phone' => $row['phone'],
                    'limit_at' => $row['returnPlanDate'],
                    'function_code' => $reception->FunctionCode,
                    'relation_code' => $reception->CReceptionCode,
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
                    'smsnum'=>0,
                    'job' => $msg,
                ];

                $this->wechatIndex[] = $index;
                $this->wechatList[$index] = json_encode($redisIndexContent, JSON_UNESCAPED_UNICODE);
            }
        });
        if (empty($this->jobData)) {
            return false;
        }
        array_map(function ($v) use ($receptionIdRedis) {
            $this->redis->sAdd($receptionIdRedis, $v);
        }, $actionId);
        // dd($this->jobData);
        DB::beginTransaction();
        $result = DB::table('remind_job')->insert($this->jobData);
        if (!$result) {
            array_map(function ($v) use ($receptionIdRedis) {
                $this->redis->sRem($receptionIdRedis, $v);
            }, $actionId);
            Log::info('create job fail');
            DB::rollBack();
            return false;
        }
        $result = DB::table('return_vist_plan')->whereIn('id', $actionId)->update(['returnPlanDate' => date('Y-m-d')]);
        if (!$result) {
            array_map(function ($v) use ($receptionIdRedis) {
                $this->redis->sRem($receptionIdRedis, $v);
            }, $actionId);
            Log::info('update return_vist_plan planvisitdate fail');
            DB::rollBack();
            return false;
        }

        if ($this->wechatIndex) {
            array_unshift($this->wechatIndex, 'wechat:message:template');
            call_user_func_array([$this->redis, 'lPush'], $this->wechatIndex);
            $result = $this->redis->mSet($this->wechatList);
            if (!$result) {
                DB::rollBack();
                array_map(function ($v) use ($receptionIdRedis) {
                    $this->redis->sRem($receptionIdRedis, $v);
                }, $actionId);
                return false;
            }
        }
        $this->redis->expireAt($receptionIdRedis, $redisExpireTime);
        Log::info('execute sql: ' . json_encode(DB::getQueryLog(), JSON_UNESCAPED_UNICODE));
        DB::commit();
        Log::info('End success');
    }
}
