<?php

namespace Packages\Core\Support;

use Packages\Commander\Models\CommanderInstanceModel;

class CoreActiveInstanceSupport
{
    private ?CommanderInstanceModel $instance = null;

    public function set(?CommanderInstanceModel $instance): void
    {
        $this->instance = $instance;
    }

    public function forget(): void
    {
        $this->instance = null;
    }

    public function current(): ?CommanderInstanceModel
    {
        return $this->instance;
    }

    public function id(): ?int
    {
        return $this->instance?->getKey();
    }
}
