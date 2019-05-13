<?php

declare(strict_types=1);

namespace Keboola\GoodDataWriter;

use Keboola\Component\Config\BaseConfig;

class Config extends BaseConfig
{
    public function getUserLogin(): string
    {
        return $this->getValue(['parameters', 'user', 'login']);
    }

    public function getUserPassword(): string
    {
        return $this->getValue(['parameters', 'user', '#password']);
    }

    public function getProjectPid(): string
    {
        return $this->getValue(['parameters', 'project', 'pid']);
    }

    public function getProjectBackendUrl(): string
    {
        return $this->getValue(['parameters', 'project', 'backendUrl'], '');
    }

    public function getTables(): array
    {
        return $this->getValue(['parameters', 'tables']);
    }

    public function getDimensions(): array
    {
        return $this->getValue(['parameters', 'dimensions'], []);
    }

    public function getLoadOnly(): bool
    {
        return $this->getValue(['parameters', 'loadOnly'], false);
    }

    public function getMultiLoad(): bool
    {
        return $this->getValue(['parameters', 'multiLoad'], false);
    }

    public function getBucket(): string
    {
        return $this->getValue(['parameters', 'bucket']);
    }
}
