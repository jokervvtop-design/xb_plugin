<?php

namespace Plugin\TgAiAssistant\Services;

use App\Models\User;
use App\Utils\Helper;

class UserContextBuilder
{
    public function build(?User $user): string
    {
        if (!$user) {
            return '【用户状态】未绑定 XBoard 账号';
        }

        $user->loadMissing('plan');
        $used = Helper::transferToGB($user->u + $user->d);
        $total = Helper::transferToGB($user->transfer_enable);
        $remaining = max(0, $total - $used);
        $expiredAt = $user->expired_at
            ? date('Y-m-d H:i:s', $user->expired_at)
            : '长期有效';

        $lines = [
            '【用户账户信息】',
            '邮箱：' . $user->email,
            '套餐：' . ($user->plan->name ?? '未订购'),
            sprintf('流量：剩余 %.2fG / 总计 %.2fG', $remaining, $total),
            '到期：' . $expiredAt,
        ];

        return implode("\n", $lines);
    }
}
