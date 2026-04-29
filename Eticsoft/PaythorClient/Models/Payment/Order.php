<?php

namespace Eticsoft\PaythorClient\Models\Payment;

class Order
{
    private Cart $cart;
    private Shipping $shipping;
    private Invoice $invoice;
 
    public function setCart(Cart $cart): self
    {
        $this->cart = $cart;
        return $this;
    }

    public function setShipping(Shipping $shipping): self
    {
        $this->shipping = $shipping;
        return $this;
    }

    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;
        return $this;
    }
    
    public function toArray(): array
    {
        return [
            'cart' => $this->cart->toArray(),
            'shipping' => $this->shipping->toArray(),
            'invoice' => $this->invoice->toArray()
        ];
    }
}