<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

/** Fault after a real autocommitted write, with a shared control surviving connection cloning. */
trait ManagedWriteFault
{
    public object $fault;

    public function affectingStatement($query, $bindings = [])
    {
        $result = parent::affectingStatement($query, $bindings);
        if ($result === 1 && ++$this->fault->writes === $this->fault->target) {
            $this->fault->unavailable = $this->fault->outage;
            throw new \RuntimeException('Synthetic lost acknowledgement');
        }

        return $result;
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if ($this->fault->unavailable) {
            throw new \RuntimeException('Synthetic read outage');
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
}
