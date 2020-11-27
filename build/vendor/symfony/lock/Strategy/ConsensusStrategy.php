<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock\Strategy;

/**
 * ConsensusStrategy is a StrategyInterface implementation where strictly more than 50% items should be successful.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class ConsensusStrategy implements \LockmeDep\Symfony\Component\Lock\Strategy\StrategyInterface
{
    /**
     * {@inheritdoc}
     */
    public function isMet(int $numberOfSuccess, int $numberOfItems)
    {
        return $numberOfSuccess > $numberOfItems / 2;
    }
    /**
     * {@inheritdoc}
     */
    public function canBeMet(int $numberOfFailure, int $numberOfItems)
    {
        return $numberOfFailure < $numberOfItems / 2;
    }
}
