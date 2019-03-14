<?php

namespace App\Http\Controllers;

use App\Events\OrderReviewed;
use App\Exceptions\CouponCodeUnavailableException;
use App\Exceptions\InvalidRequestException;
use App\Http\Requests\ApplyRefundRequest;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\SendReviewRequest;
use App\Models\CouponCode;
use App\Models\UserAddress;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\OrderService;

class OrderController extends Controller
{
    public function store (OrderRequest $request, OrderService $orderService) {
        $user = $request->user();
        $address = UserAddress::find($request->input('address_id'));
        $coupon = null;
        // 如果用户提交了优惠码
        if ($code = $request->input('coupon_code')) {
            $coupon = CouponCode::where('code', $code)->first();
            if (!$coupon) {
                throw new CouponCodeUnavailableException('优惠码不存在');
            }
        }
        // 参数中加入$coupon变量
        return $orderService->store($user, $address, $request->input('remark'), $request->input('items'), $coupon);
    }

    public function index (Request $request) {
        $orders = Order::query()
            ->with(['items.product', 'items.productSku'])   // 使用with方法预加载，避免N+1问题
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate();
        return view('orders.index', ['orders' => $orders]);
    }

    public function show (Order $order, Request $request) {
        $this->authorize('own', $order);
        return view('orders.show', ['order' => $order->load(['items.productSku', 'items.product'])]);
    }

    public function received (Order $order, Request $request) {
        // 校验权限
        $this->authorize('own', $order);
        // 判断订单的发货状态是否是已发货
        if ($order->ship_status !== Order::SHIP_STATUS_DELIVEDED) {
            throw new InvalidRequestException('发货状态不正确');
        }
        // 更新发货状态为已收货
        $order->update(['ship_status' => Order::SHIP_STATUS_RECEIVED]);
        // 返回订单信息
        return $order;
    }

    public function review (Order $order) {
        // 校验权限
        $this->authorize('own', $order);
        // 判断是否已支付
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未支付，还不能评价');
        }
        // load()方法加载关联数据，避免N+1性能问题
        return view('orders.review', ['order' => $order->load(['items.productSku', 'items.product'])]);
    }

    public function sendReview (Order $order, SendReviewRequest $request) {
        $this->authorize('own', $order);
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未支付');
        }
        // 判断是否已评价
        if ($order->reviewed) {
            throw new InvalidRequestException('该订单已评价，不能重复评价');
        }
        $reviews = $request->input('review');
        // 开启事务
        \DB::transaction(function () use ($reviews, $order) {
            // 遍历用户提交数据
            foreach ($reviews as $review) {
                $orderItem = $order->items()->find($review['id']);
                // 保存评分和评价内容
                $orderItem->update([
                    'rating' => $review['rating'],
                    'review' => $review['review'],
                    'reviewed_at' => Carbon::now(),
                ]);
            }
            // 将该订单标记为已评价
            $order->update(['reviewed' => true]);
            event(new OrderReviewed($order));
        });
        return redirect()->back();
    }

    public function applyRefund (Order $order, ApplyRefundRequest $request) {
        // 校验订单是否属于当前用户
        $this->authorize('own', $order);
        // 判断订单是否已付款
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未支付，不能退款');
        }
        // 判断订单退款状态是否正确
        if ($order->refund_status !== Order::REFUND_STATUS_PENDING) {
            throw new InvalidRequestException('该订单已申请过退款，请勿重复申请');
        }
        // 将用户输入的退款理由放入订单的 extra 字段
        $extra = $order->extra ?: [];
        $extra['refund_reason'] = $request->input('reason');
        // 将订单退款状态改为已申请退款
        $order->update([
            'refund_status' => Order::REFUND_STATUS_APPLIED,
            'extra' => $extra,
        ]);
        return $order;
    }
}