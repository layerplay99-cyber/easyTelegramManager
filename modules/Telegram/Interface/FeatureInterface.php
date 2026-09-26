<?php

namespace Modules\Telegram\Interface;

interface FeatureInterface
{
    // Handle an incoming update
    public function handle(array $update): void;
}
