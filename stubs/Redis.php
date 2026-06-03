<?php

/**
 * Minimal Redis stubs for static analysis and unit testing in environments
 * where ext-redis is not installed.
 *
 * These declarations are only loaded when the \Redis class does not already
 * exist (i.e. when ext-redis is absent). In production the extension provides
 * the real class; here we just need enough surface for PHPStan L10 and
 * PHPUnit's createMock(\Redis::class) to work.
 */

declare(strict_types=1);

if (!class_exists(\Redis::class)) {
    class Redis
    {
        /**
         * @param array<string, string> $fields
         * @return string|false
         */
        public function xAdd(string $key, string $id, array $fields): string|false
        {
            return false;
        }

        /**
         * @param string                    $operation  CREATE | SETID | DESTROY | DELCONSUMER
         * @param string                    $key
         * @param string                    $group
         * @param string|int                $msgId
         * @param bool                      $mkStream
         * @return bool
         */
        public function xGroup(
            string $operation,
            string $key,
            string $group,
            string|int $msgId = '$',
            bool $mkStream = false,
        ): bool {
            return false;
        }

        /**
         * @param string                    $group
         * @param string                    $consumer
         * @param array<string, string>     $streams    keys = stream name, values = last-id
         * @param int                       $count
         * @param int|null                  $blockMs
         * @return array<string, array<string, array<string, string>>>|false
         */
        public function xReadGroup(
            string $group,
            string $consumer,
            array $streams,
            int $count = 1,
            ?int $blockMs = null,
        ): array|false {
            return false;
        }

        /**
         * @param string        $key
         * @param string        $group
         * @param array<string> $ids
         * @return int|false
         */
        public function xAck(string $key, string $group, array $ids): int|false
        {
            return false;
        }

        /**
         * Summary form (2 args): returns array{0: int, 1: string, 2: string, 3: int}|false
         * Detail form (5 args): returns array<int, array{0: string, 1: string, 2: int, 3: int}>|false
         *
         * @param string      $key
         * @param string      $group
         * @param string|null $start
         * @param string|null $end
         * @param int|null    $count
         * @return array<int|string, mixed>|false
         */
        public function xPending(
            string $key,
            string $group,
            ?string $start = null,
            ?string $end = null,
            ?int $count = null,
        ): array|false {
            return false;
        }

        /**
         * @param string        $key
         * @param string        $group
         * @param string        $consumer
         * @param int           $minIdleMs
         * @param array<string> $ids
         * @return array<string, array<string, string>>|false
         */
        public function xClaim(
            string $key,
            string $group,
            string $consumer,
            int $minIdleMs,
            array $ids,
        ): array|false {
            return false;
        }
    }
}

if (!class_exists(\RedisException::class)) {
    class RedisException extends \RuntimeException
    {
    }
}
