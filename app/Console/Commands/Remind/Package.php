<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;

class Package extends Command
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
        //
    }
}
