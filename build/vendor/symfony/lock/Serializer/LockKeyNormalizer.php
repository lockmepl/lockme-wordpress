<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace LockmeDep\Symfony\Component\Lock\Serializer;

use LockmeDep\Symfony\Component\Lock\Key;
use LockmeDep\Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use LockmeDep\Symfony\Component\Serializer\Normalizer\NormalizerInterface;
/**
 * Normalize {@see Key} instance to transfer an acquired lock between processes.
 *
 * @author Valtteri R <valtzu@gmail.com>
 */
final class LockKeyNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function getSupportedTypes(?string $format): array
    {
        return [Key::class => \true];
    }
    /**
     * @param Key $data
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return $data->__serialize();
    }
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Key;
    }
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Key
    {
        $key = (new \ReflectionClass(Key::class))->newInstanceWithoutConstructor();
        $key->__unserialize($data);
        return $key;
    }
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Key::class === $type;
    }
}
