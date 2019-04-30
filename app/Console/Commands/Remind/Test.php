<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Libs\Mwsms;
use Illuminate\Support\Facades\DB;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description---test';

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
        echo date('Y-m-d H:i:s');
        file_put_contents('./logs.log',date('Y-m-d H:i:s')."\n",FILE_APPEND);
        $confRedis = Redis::connection('companyInfo');
        $_smsconf = $confRedis->hGetAll('wechat_config:1');
        $smsApi = new Mwsms($_smsconf);
        $smsResult = $smsApi->send('15019427980', '尊敬的XXX，您的车辆：XXXXXX  年审将于XXXX-XX-XX到期，如需办理请联系：XXXX', 1, false);
        var_dump($smsResult);
        $data['sms_num'] = 1;
        # 发送短信
        $data['registerNo'] = 1;
        $data['customerName'] = 1;
        $data['status'] = 0;
        $data['pid'] = 0;
        $data['type'] = 1;
        $data['content'] = 1;
        $data['phone'] = 1;
        $data['cid'] =1;
        $data['addtime'] = time();
        $data['store_id'] = 0;
        $item[]=$data;
        $item[]=$data;
        $recid = DB::table('r_smsrecord')->insert($item);
        dd($recid);
    }
}
