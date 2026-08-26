<?php

declare(strict_types=1);

namespace OpenEMR\BC;

class ServiceContainer
{
    public static function getLogger(): \Psr\Log\LoggerInterface
    {
        return new \ClaimRevStubLogger();
    }
}
