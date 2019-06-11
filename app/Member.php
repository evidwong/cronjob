<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $table = 'member';

    public function wechat()
    {
        return $this->hasOne('App\Memberwechat', 'uid', 'uid')->where('member_wechat.status', '=', 1);//->whereRaw('member.cid=member_wechat.cid');
    }
}
