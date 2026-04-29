<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Cart
{
    private array $items = [];

    /**
     * Add a cart item
     * 
     * @param string $id Item ID
     * @param string $name Item name
     * @param string $type Item type (product/discount/shipping/tax)
     * @param string $price Item price
     * @param int $quantity Item quantity
     * @return $this
     */
    public function addItem(string $id, string $name, string $type, string $price, int $quantity): self
    {
        $this->items[] = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'price' => $price,
            'quantity' => $quantity
        ];
        return $this;
    }

    /**
     * Get all cart items
     * 
     * @return array
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Get total price of cart items
     * 
     * @return float
     */
    public function getTotalPrice(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += (float)$item['price'] * $item['quantity'];
        }
        return $total;
    }

    /**
     * Convert cart to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return $this->items;
    }
} 