<?php

namespace App\Console\Commands\Remind;

use Illuminate\Console\Command;

class Car extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'car:info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '车辆信息';

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
        // $rows = DB()->table('g_vehicle')->where([''])->get()->toArray();
        $rows=[];
        if (!$rows) return;
        array_walk($rows, function ($row, $index) {
            // 
        });
    }
}
