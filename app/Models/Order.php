<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', // UUID for public facing order ID
        'user_id',
        'product_id',
        'quantity',
        'total_price',
        'status', // e.g., 'pending', 'completed', 'failed'
        'redis_deduction_id', // Add this column to store the Redis deduction ID
        'failed_reason', // Add this column to store failure reasons
    ];

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'integer'; // Keep as integer for the auto-incrementing primary key

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true; // Primary key is auto-incrementing

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
