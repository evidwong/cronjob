<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class Remind extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remind:base';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '提醒基础类';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $redis = null;
    protected $confRedis = null;
    protected $wechatTemplateMsg = [];
    protected $smsRecords = [];
    protected $smsToSends = [];

    public function __construct()
    {
        parent::__construct();
        $this->redis = Redis::connection('wechatMessage');
        $this->confRedis = Redis::connection('companyInfo');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
    }
    protected function pushWechat()
    {
        // $this->wechatTemplateMsg[] = ['msg' => $msg, 'cid' => $row['cid'], 'id' => $row['id']];
        if ($this->wechatTemplateMsg) {
            $flag = true;
            array_walk($this->wechatTemplateMsg, function ($row, $index) use (&$flag) {
                $wechat = new Wechat($row['cid']);
                $result = $this->db->table('c_awoke')->where('id', $row['id'])->update(['PlanVisitDate' => date('Y-m-d')]);
                Log::info('update c_awoke sql: ' . json_encode($this->db->getQueryLog(), JSON_UNESCAPED_UNICODE));
                Log::info('update c_awoke data: PlanVisitDate=>' . date('Y-m-d'));
                if (!$result) {
                    return false;
                }
                $result = $wechat->sendTemplateMessage($row['msg']);
                Log::info('send wechat tplmsg: ' . $result);
                if (!$result) {
                    $flag = false;
                    $this->redis->lPush('');
                    return false;
                }
            });
        }
    }
    protected function pushSms($temps = [], $time)
    {
        if (!$temps) return false;
        $exportSmsRecords = [];
        array_walk($temps, function ($row, $index) use (&$exportSmsRecords) {
            foreach ($row as $key => $v) {
                $exportSmsRecords[] = $v;
                if (($key + 1) % 1000 === 0 && $key > 0) {
                    // 超过1000条
                    $this->smsToSends[$index][] = $exportSmsRecords;
                    $exportSmsRecords = [];
                }
            }
            if ($exportSmsRecords) {
                $this->smsToSends[$index][] = $exportSmsRecords;
                $exportSmsRecords = [];
            }
        });
        array_walk($this->smsToSends, function ($rows, $cid) use ($time) {
            # 梦网短信
            $_conf = $this->confRedis->hGetAll('wechat_config:' . $cid);
            if (!$_conf || !$_conf['sms_account'] || !$_conf['sms_passcode'] || !$_conf['sms_ip']) {
                Log::info('Get sms config from redis fail: ' . $cid);
                return false;
            }
            $smsApi = new Mwsms($_conf);
            foreach ($rows as $row) {
                Log::info('send content: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                $smsResult = $smsApi->sendDiff(implode(',', $row));
                Log::info('sms send result: ' . $smsResult);
                if ($smsResult) {
                    $result = $this->db->table('r_smsrecord')->where('cid', $cid)->where('addtime', $time)->orderBy('id')->limit(count($row))->update(['send_time' => time(), 'status' => 1]);
                    Log::info('sms send sql: ' . $result);
                }
            }
        });
    }
    protected function cronConf($cid, $type = null)
    {
        if (!$cid) return false;
        $rows = [];
        if ($this->confRedis) {
            if (!$type) {
                $crons = $this->confRedis->hGetAll('cron:config:' . $cid);
                foreach ($crons as &$cron) {
                    $cron = json_decode($cron, true);
                }
            } else {
                $crons = $this->confRedis->hGet('cron:config:' . $cid, $type);
                $crons = json_decode($crons, true);
            }
            if ($crons) return $crons;
            $rows = DB::table('cron')->where('cid', $cid)->get()->toArray();
            $crons = [];
            array_map(function ($row) use (&$crons, $type, $cid) {
                $row = get_object_vars($row);
                if ($type) {
                    if ($row['type'] == $type) $crons = $row;
                } else {
                    $crons[$row['type']] = $row;
                }
                $this->confRedis->hSet('cron:config:' . $cid, $row['type'], json_encode($row, JSON_UNESCAPED_UNICODE));
            }, $rows);
            return $crons;
        }

        if (!$type) {
            $crons = DB::table('cron')->where('cid', $cid)->get()->toArray();
            array(function ($cron) use (&$rows) {
                $rows[$cron['type']] = get_object_vars($cron);
            }, $crons);
        } else {
            $rows = M('cron')->where(['cid' => $cid, 'type' => $type])->find();
        }
        return $rows;
    }
    protected function checkCondition($cron, $day)
    {
        // dd(isset($cron['expire_step']));
        $step=[];
        $step = isset($cron['expire_step'])?explode(',', $cron['expire_step']):[];
        if (!$cron || ($cron['start_time'] && strtotime($cron['start_time']) > time()) || ($cron['end_time'] && strtotime($cron['end_time']) < time()) || $cron['status'] <= 0 || ($step && !in_array($day, $step)) || ($cron['start_at'] && date('H:i:s') < $cron['start_at']) || ($cron['end_at'] && date('H:i:s') > $cron['end_at'])) {
            // 获取不到“证件提醒”的推送设置；开始、结束时间不符合设置要求；已禁用；日期时间不符合推送设置要求；当前不符合推送时间设置要求
            return false;
        }
    }
}
