<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

/** Throw after the actual autocommitted CAS, not before a simulated mutation. */
trait RefreshLostAcknowledgement
{
    public object $fault;

    public function affectingStatement($query, $bindings = [])
    {
        $result = parent::affectingStatement($query, $bindings);
        if ($this->fault->armed && $result === 1) {
            $this->fault->armed = false;
            $this->fault->unavailable = $this->fault->outageAfterWrite ?? false;
            throw new \RuntimeException('Lost acknowledgement');
        }

        return $result;
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if ($this->fault->unavailable ?? false) {
            throw new \RuntimeException('Storage unavailable');
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
}
