<?php

return [
    // Empty by default for local/direct HTTP. Set to * only when all traffic
    // reaches this service through the trusted Render ingress, or supply CIDRs.
    'proxies' => env('TRUSTED_PROXIES'),
];
