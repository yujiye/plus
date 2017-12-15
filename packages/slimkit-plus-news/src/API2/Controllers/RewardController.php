<?php

namespace Zhiyi\Component\ZhiyiPlus\PlusComponentNews\API2\Controllers;

use Illuminate\Http\Request;
use Zhiyi\Plus\Models\WalletCharge;
use Zhiyi\Component\ZhiyiPlus\PlusComponentNews\Models\News;

class RewardController extends Controller
{
    /**
     * 打赏一条资讯.
     *
     * @author bs<414606094@qq.com>
     * @param  Request      $request
     * @param  News         $news
     * @param  WalletCharge $charge
     * @return mix
     */
    public function reward(Request $request, News $news, WalletCharge $charge)
    {
        $amount = $request->input('amount');
        if (! $amount || $amount < 0) {
            return response()->json([
                'amount' => ['请输入正确的打赏金额'],
            ], 422);
        }
        $user = $request->user();
        $user->load('wallet');
        $news->load('user');
        $current_user = $news->user;

        if (! $user->wallet || $user->wallet->balance < $amount) {
            return response()->json([
                'message' => ['余额不足'],
            ], 403);
        }

        $user->getConnection()->transaction(function () use ($user, $news, $charge, $current_user, $amount) {
            // 扣除操作用户余额
            $user->wallet()->decrement('balance', $amount);

            // 扣费记录
            $userCharge = clone $charge;
            $userCharge->channel = 'user';
            $userCharge->account = $current_user->id;
            $userCharge->subject = '资讯打赏';
            $userCharge->action = 0;
            $userCharge->amount = $amount;
            $userCharge->body = sprintf('打赏资讯《%s》', $news->title);
            $userCharge->status = 1;
            $user->walletCharges()->save($userCharge);

            // 添加打赏通知
            $user->sendNotifyMessage('news:reward', sprintf('你对资讯《%s》进行%s元打赏', $news->title, $amount / 100), [
                    'news' => $news,
                    'user' => $current_user,
                ]);

            if ($current_user->wallet) {
                // 增加对应用户余额
                $current_user->wallet()->increment('balance', $amount);

                $charge->user_id = $current_user->id;
                $charge->channel = 'user';
                $charge->account = $user->id;
                $charge->subject = '资讯被打赏';
                $charge->action = 1;
                $charge->amount = $amount;
                $charge->body = sprintf('资讯《%s》被打赏', $news->title);
                $charge->status = 1;
                $charge->save();

                // 添加被打赏通知
                $current_user->sendNotifyMessage('news:reward', sprintf('你的资讯《%s》被%s打赏%s元', $news->title, $user->name, $amount / 100), [
                    'news' => $news,
                    'user' => $user,
                ]);
            }

            // 打赏记录
            $news->reward($user, $amount);
        });

        return response()->json([
            'message' => ['打赏成功'],
        ], 201);
    }

    /**
     * 一条资讯的打赏列表.
     *
     * @author bs<414606094@qq.com>
     * @param  Request $request
     * @param  News    $news
     * @return mix
     */
    public function index(Request $request, News $news)
    {
        $limit = max(1, min(30, $request->query('limit', 20)));
        $since = $request->query('since', 0);
        $order = in_array($order = $request->query('order', 'desc'), ['asc', 'desc']) ? $order : 'desc';
        $order_type = in_array($order_type = $request->query('order_type'), ['amount', 'date']) ? $order_type : 'date';
        $fieldMap = [
            'date' => 'id',
            'amount' => 'amount',
        ];
        $rewardables = $news->rewards()
            ->with('user')
            ->when($since, function ($query) use ($since, $order, $order_type, $fieldMap) {
                return $query->where($fieldMap[$order_type], $order === 'asc' ? '>' : '<', $since);
            })
            ->limit($limit)
            ->orderBy($fieldMap[$order_type], $order)
            ->get();

        return response()->json($rewardables, 200);
    }

    /**
     * 查看一条资讯的打赏统计
     *
     * @author bs<414606094@qq.com>
     * @param  News   $news
     * @return array
     */
    public function sum(News $news)
    {
        return response()->json($news->rewardCount(), 200);
    }
}