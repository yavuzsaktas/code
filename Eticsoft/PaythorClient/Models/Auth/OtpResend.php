<?php

namespace Eticsoft\PaythorClient\Models\Auth; 

class OtpResend  
{
    /**
     * The target email or phone number to send the OTP to.
     *
     * @var string
     */
    protected $target;

    /**
     * The channel to use for sending the OTP (email or sms).
     *
     * @var string
     */
    protected $channel;

    /**
     * Set the target email or phone number.
     *
     * @param string $target
     * @return self
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }

    /**
     * Set the channel.
     *
     * @param string $channel
     * @return self
     */
    public function setChannel(string $channel): self
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * Convert the model to an array for API requests   
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'channel' => $this->channel
        ];
    }
}
