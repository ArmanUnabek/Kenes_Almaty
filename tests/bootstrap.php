<?php

require_once __DIR__ . '/../config.php';

if (!function_exists('canAccessRegion')) {
    function canAccessRegion($regionId): bool
    {
        return (int)$regionId === 1;
    }
}
