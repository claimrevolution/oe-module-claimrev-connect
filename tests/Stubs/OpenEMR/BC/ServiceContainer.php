<?php

declare(strict_types=1);

namespace OpenEMR\BC;

class ServiceContainer
{
    public static function getLogger(): \Psr\Log\LoggerInterface
    {
        return new \ClaimRevStubLogger();
    }

    public static function getCrypto(): \OpenEMR\Common\Crypto\CryptoGen
    {
        return new \OpenEMR\Common\Crypto\CryptoGen();
    }
}
