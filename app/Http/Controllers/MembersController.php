<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Member;

class MembersController extends Controller
{
    public function index()
    {
        $m = Member::where('phone', '15019427980')->with('wechat')->where('cid',1)->first();
        dd($m->wechat->openid);
    }
}
