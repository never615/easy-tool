<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\AppSecretSeederMaker;

/**
 * 开放平台接口权限生成
 *
 * Class AppSecretPermissionsSeeder
 *
 * @package Mallto\Tool\Seeder\Permission
 */
class AppSecretPermissionsSeeder extends Seeder
{

    use AppSecretSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        $this->createPermissions('批量会员查询', 'tp_member.index');
        $this->createPermissions('会员创建/注册', 'tp_member.store');
        $this->createPermissions('查询会员信息', 'tp_member_info.index');
        $this->createPermissions('查询积分抵现比率', 'tp_point_as_money.index');
        $this->createPermissions('更新会员信息', 'tp_member.update');
        $this->createPermissions('会员创建/注册,带短信校验', 'tp_verification_member.store');
        $this->createPermissions('扣减积分', 'tp_member_point.store');
        $this->createPermissions('订单上报和订单退积分', 'tp_member_order.store');
        $this->createPermissions('查询积分订单是否存在', 'tp_member_order_exists.store');
        $this->createPermissions('pos根据核销码核销卡券', 'tp_pos_verification.store');
        $this->createPermissions('普通核销', 'tp_verification.store');
        $this->createPermissions('用户卡券列表', 'tp_member_coupon.index');
        $this->createPermissions('用户卡券详情', 'tp_member_coupon.show');
        $this->createPermissions('派发卡券', 'tp_coupon_distribution.store');
        $this->createPermissions('会员上传小票记录', 'tp_member_ticket.index');
        $this->createPermissions('会员消费记录', 'tp_member_order.index');
        $this->createPermissions('线上订单上报', 'tp_member_online_order.store');
        $this->createPermissions('线上订单更新', 'tp_member_online_order.update');
        $this->createPermissions('停车结果通知(使用墨兔签名及字段定义)', 'tp_park_pay_result2.store');
        $this->createPermissions('锁定积分操作', 'tp_member_lock_point.store');
        $this->createPermissions('解锁积分操作', 'tp_member_unlock_point.store');
        $this->createPermissions('根据订单信息查询会员积分是否满足', 'tp_member_order_return_point_check.store');
        $this->createPermissions('代客泊车支付', 'tp_vale_parking.index');
    }
}
