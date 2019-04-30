<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;

class ServiceItem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expire:service_item';

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
    protected $jobData=[];
    protected $db=null;
    public function __construct()
    {
        parent::__construct();
        $this->db=new DB();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        DB::enableQueryLog();
        $this->db->table('c_awoke')->where()->get();
    }
}
