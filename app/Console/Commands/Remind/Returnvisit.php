<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;
use App\Models\Awoke;
class Returnvisit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expire:returnvisit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        // 年审、证件、保险到期 --> c_awoke
        // 商机 --> c_awoke 服务到期
        // 工单回访 --> return_vist_plan
        // 套餐 --> c_membersetsalesm
        // 卡券 --> coupon_card

        $awokes=Awoke::whereRaw("TIMESTAMPDIFF(DAY,'".date('Y-m-d H:i:s')."',BookingDate)=30")->get();
        var_dump($awokes);
    }
}
