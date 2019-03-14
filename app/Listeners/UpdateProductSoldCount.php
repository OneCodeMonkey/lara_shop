<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\OrderItem;

// implements ShouldQueue 代表实现的是异步监听器
class UpdateProductSoldCount implements ShouldQueue
{
    // Laravel会默认执行监听器的handle()方法，触发的事件会作为handle()方法的参数
    public function handle (OrderPaid $event) {
        // 从事件对象中取出对应订单
        $order = $event->getOrder();
        // 预加载商品数据
        $order->load('items.product');
        // 循环遍历订单中的商品
        foreach ($order->items as $item) {
            $product = $item->product;
            // 计算对应商品的销量
            $soldCount = OrderItem::query()
                ->where('product_id', $product->id)
                ->whereHas('order', function ($query) {
                    $query->whereNotNull('paid_at'); // 关联的订单状态是否已支付
                })->sum('amount');
            // 更新商品销量
            $product->update([
                'sold_count' => $soldCount,
            ]);
        }
    }
}