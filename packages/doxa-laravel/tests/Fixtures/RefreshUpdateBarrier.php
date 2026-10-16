<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

/** Test-only synchronization after validation/SQL compilation, before the real CAS. */
trait RefreshUpdateBarrier
{
    /** @var object{before: \Closure, reached: bool, affected: ?int} Shared across the store's connection clone. */
    public object $barrier;

    public function affectingStatement($query, $bindings = [])
    {
        if (! $this->barrier->reached) {
            $this->barrier->reached = true;
            ($this->barrier->before)();
        }
        $affected = parent::affectingStatement($query, $bindings);
        $this->barrier->affected = $affected;

        return $affected;
    }
}
