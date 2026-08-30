<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\GitBlobSha;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The expected shas here are not this implementation's own output frozen into an assertion —
 * they were produced by `git hash-object`. That is the whole point of the class: the value has
 * to match what git (and therefore GitHub's tree API) computes, or a rebase check comparing a
 * stored `base_sha` against a GitHub blob sha would report every file as moved.
 */
#[CoversClass(GitBlobSha::class)]
final class GitBlobShaTest extends TestCase
{
    /** `printf '' | git hash-object --stdin` */
    private const EMPTY_BLOB = 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391';

    /** `printf 'hello\n' | git hash-object --stdin` */
    private const HELLO_BLOB = 'ce013625030ba8dba906f756967f9e9ca394464a';

    /** `printf '{"litcal":[]}\n' | git hash-object --stdin` */
    private const LITCAL_BLOB = '3bdbc3dff54ef565e0d675eb5bfa561c32a7238d';

    /** `printf 'Ø†è\n' | git hash-object --stdin` — 7 BYTES, not 4 characters. */
    private const MULTIBYTE_BLOB = 'efee047c3222b905519f04758d87ffbace10676c';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/litcal-blobsha-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }
    }

    public function testEmptyContentHashesToGitsWellKnownEmptyBlob(): void
    {
        self::assertSame(self::EMPTY_BLOB, GitBlobSha::ofContent(''));
    }

    public function testContentHashesToTheSameShaGitHashObjectProduces(): void
    {
        self::assertSame(self::HELLO_BLOB, GitBlobSha::ofContent("hello\n"));
        self::assertSame(self::LITCAL_BLOB, GitBlobSha::ofContent("{\"litcal\":[]}\n"));
    }

    /**
     * git prefixes the object with its BYTE length. Using a character count instead would
     * produce a sha that matches nothing GitHub ever returns, and only for non-ASCII files —
     * which is most of `jsondata/sourcedata`.
     */
    public function testLengthInTheHeaderIsCountedInBytesNotCharacters(): void
    {
        self::assertSame(self::MULTIBYTE_BLOB, GitBlobSha::ofContent("Ø†è\n"));
    }

    public function testOfFileHashesTheFileContents(): void
    {
        $path = $this->tmpDir . '/US.json';
        file_put_contents($path, "{\"litcal\":[]}\n");

        self::assertSame(self::LITCAL_BLOB, GitBlobSha::ofFile($path));
    }

    /**
     * Null is a value here, not a failure: it is what a change request that CREATES a file
     * records, and what a file existing only as queued work records too.
     */
    public function testOfFileIsNullWhenThereIsNoFile(): void
    {
        self::assertNull(GitBlobSha::ofFile($this->tmpDir . '/absent.json'));
    }

    public function testOfFileIsNullForADirectory(): void
    {
        self::assertNull(GitBlobSha::ofFile($this->tmpDir));
    }
}
