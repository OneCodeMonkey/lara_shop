<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidRequestException;
use App\Models\OrderItem;
use App\Models\Product;
use Illumunate\Http\Request;

class ProductsController extends Controller
{
    public function index (Request $request) {
        $builder = Product::query()->where('on_sale', true);
        // 判断是否有提交search参数，如果有，就赋给$search
        // $search 用来匹配模糊搜索商品
        if ($search = $request->input('search', '')) {
            $like = '%' . $search . '%';
            // 模糊搜索商品标题，商品详情，SKU标题，SKU描述
            $builder->where(function ($query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('skus', function ($query) use ($like) {
                        $query->where('title', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    });
            });
        }
        // 是否提交order参数，如果有就赋给$order
        // order参数用来控制商品的排序规则
        if ($order = $request->input('order', '')) {
            // 是否以 _asc 或 _desc 结尾
            if (preg_match('/^(.+)_(asc|desc)$/', $order, $m)) {
                // 如果字符串开头是这三个字符串之一，说明是个合法的排序值
                if (in_array($m[1], ['price', 'sold_count', 'rating'])) {
                    // 根据传入的排序值，构造排序参数
                    $builder->orderBy($m[1], $m[2]);
                }
            }
        }
        $products = $builder->paginate(16);
        return view('products.index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'order' => $order,
            ],
        ]);
    }

    public function show (Product $product, Request $request) {
        // 判断商品是否已上架，如果没上架则抛异常
        if (!$product->on_sale) {
            throw new InvalidRequestException('该商品未上架');
        }
        $favored = false;
        // 用户未登录时返回的是null，已登录时返回的是对应的用户对象
        if ($user = $request->user()) {
            // 从当前用户已收藏的商品中，搜索id为当前商品id的商品
            // boolval()函数用户将值转为bool，类似的有intval()，floatval()
            $favored = boolval($user->favoriteProducts()->find($product->id));
        }
        $reviews = OrderItem::query()
            ->with(['order.user', 'productSku'])  // 预加载关联关系
            ->where('product_id', $product->id)
            ->whereNotNull('reviewed_at')   // 筛选出已评价的
            ->orderBy('reviewed_at', 'desc')  // 按评价时间倒序排列
            ->limit(10)
            ->get();
        return view('products.show', [
            'product' => $product,
            'favored' => $favored,
            'reviews' => $reviews,
        ]);
    }

    public function favor (Product $product, Request $request) {
        $user = $request->user();
        if ($user->favoriteProducts()->find($product->id)) {
            return [];
        }
        $user->favoriteProducts()->attach($product);
        return [];
    }

    public function disfavor(Product $product, Request $request) {
        $user = $request->user();
        $user->favoriteProducts()->detach($product);
        return [];
    }
    public function favorites (Request $request) {
        $products = $request->user()->favoriteProducts()->paginate(16);
        return view('products.favorites', ['products' => $products]);
    }
}