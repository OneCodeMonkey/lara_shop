<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Order;

// 代表这个类需要被放到队列中执行，不是触发是立即执行
class CloseOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $order;

    public function __construct (Order $order, $delay) {
        $this->order = $order;
        // 设置延迟时间，delay()方法的参数单位为秒
        $this->delay($delay);
    }

    // 定义这个任务类具体的执行逻辑
    // 当队列处理器从队列中取出任务时，会调用handle()方法
    public function handler () {
        // 判断对应订单是否已被支付
        // 如果已被支付，则不需要关闭订单，直接退出
        if ($this->order->paid_at) {
            return ;
        }
        // 用事务执行SQL
        \DB::transaction(function () {
            // 将订单的 closed 字段标记为true，即关闭订单
            $this->order->update(['closed' => true]);
            // 循环遍历订单中的商品SKU，将订单中的数量加回 SKU 的库存
            foreach ($this->order->items as $item) {
                $item->productSku->addStock($item->amount);
            }
            if ($this->order->couponCode) {
                $this->order->couponCode->changeUsed(false);
            }
        });
    }
}