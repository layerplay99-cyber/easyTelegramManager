<?php

namespace Modules\Telegram\Interface;

interface RealManFeature
{
    public function handle(?string $type, array $params);
}
