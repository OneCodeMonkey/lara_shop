<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $fillable = [
        'title', 'description', 'image', 'on_sale', 'rating', 'sold_count', 'review_count', 'price',
    ];

    protected $casts = [
        'on_sale' => 'boolean', // on_sale 字段的类型为bool
    ];

    public function skus () {
        return $this->hasMany(ProductSku::class);
    }

    public function getImageUrlAttribute () {
        // 若image字段本身就是完整的url，则直接返回
        if (Str::startsWith($this->attributes['image'], ['http://', 'https://'])) {
            return $this->attributes('image');
        }
        return \Storage::disk('public')->url($this->attributes['image']);
    }
}