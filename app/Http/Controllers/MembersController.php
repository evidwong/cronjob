<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Member;
use Illuminate\Support\Facades\DB;

class MembersController extends Controller
{
    public function index()
    {
        DB::connection()->enableQueryLog();
        $m = Member::where('phone', '15019427980')->with('wechat')->where('cid', 1)->first();
        var_dump($m->wechat->openid);
        $logs = DB::getQueryLog();
        dd($logs);
    }
}
