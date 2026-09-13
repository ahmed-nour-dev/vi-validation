<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: file, image, mimes, mimetypes, extensions, min_file_size, max_file_size,
 * dimensions.
 *
 * UploadedFile::fake() returns Illuminate\Http\Testing\File, which extends
 * Illuminate\Http\UploadedFile -> Symfony\Component\HttpFoundation\File\File -> SplFileInfo.
 * That satisfies both Laravel's isValidFileInstance() (needs a Symfony File) and
 * vi/validation's FileRule/ImageRule (needs an SplFileInfo), so the same object is valid
 * input to both sides.
 */
#[Group('laravel')]
class FileParityTest extends ParityTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * UploadedFile::fake()->create() writes arbitrary padding bytes with no real magic
     * number, so content-based MIME sniffing (mime_content_type(), Symfony's real
     * MimeTypes guesser) can't identify it as the claimed type - only Laravel's
     * Illuminate\Http\Testing\File overrides getMimeType()/guessExtension() to trust the
     * declared type without inspecting content. Real content is needed for a genuine
     * content-sniffing comparison between Laravel and vi/validation.
     */
    private function realUploadedFile(string $originalName, string $contents, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vi-validation-parity-file-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $originalName, $mimeType, null, true);
    }

    public function testFilePasses(): void
    {
        $upload = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');
        $this->assertParity(['doc' => 'file'], ['doc' => $upload]);
    }

    public function testFileFailsWithPlainString(): void
    {
        $this->assertParity(['doc' => 'file'], ['doc' => 'not-a-file']);
    }

    public function testImagePasses(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $this->assertParity(['photo' => 'image'], ['photo' => $upload]);
    }

    public function testImageFailsForNonImageFile(): void
    {
        $upload = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');
        $this->assertParity(['photo' => 'image'], ['photo' => $upload]);
    }

    public function testMimesPasses(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< >>\nendobj\n", 'application/pdf');
        $this->assertParity(['doc' => 'mimes:pdf,doc'], ['doc' => $upload]);
    }

    public function testMimesFails(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 10, 10);
        $this->assertParity(['doc' => 'mimes:pdf,doc'], ['doc' => $upload]);
    }

    public function testMimetypesPasses(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< >>\nendobj\n", 'application/pdf');
        $this->assertParity(['doc' => 'mimetypes:application/pdf'], ['doc' => $upload]);
    }

    public function testMimetypesFails(): void
    {
        $upload = UploadedFile::fake()->image('photo.png', 10, 10);
        $this->assertParity(['doc' => 'mimetypes:application/pdf'], ['doc' => $upload]);
    }

    public function testExtensionsPasses(): void
    {
        // Laravel's 'extensions' rule was added in illuminate/validation v10.34.0; it
        // doesn't exist as a recognized rule name on earlier v10 releases.
        $this->skipUnlessLaravelValidationAtLeast('10.34.0');

        $upload = UploadedFile::fake()->create('report.csv', 5, 'text/csv');
        $this->assertParity(['report' => 'extensions:csv,txt'], ['report' => $upload]);
    }

    public function testExtensionsFails(): void
    {
        $this->skipUnlessLaravelValidationAtLeast('10.34.0');

        $upload = UploadedFile::fake()->create('report.pdf', 5, 'application/pdf');
        $this->assertParity(['report' => 'extensions:csv,txt'], ['report' => $upload]);
    }

    /**
     * Laravel overloads 'min:N'/'max:N' to mean kilobytes for a File value; vi/validation
     * gives that a distinct rule name (see compatibility-matrix.json: min_file_size).
     *
     * UploadedFile::fake()->create($name, $kilobytes) reports a fake size without writing
     * real bytes to disk (filesize() on the real path is 0) - fine for Laravel, which reads
     * the object's own (faked) getSize(), but vi/validation's *FileSizeRule reads the real
     * file's filesize() on disk, so these need genuinely-sized fixtures via realUploadedFile().
     */
    public function testMinFileSizePasses(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', str_repeat('a', 50 * 1024), 'application/pdf');
        $this->assertEquivalentParity(
            ['doc' => 'file|min:10'],
            ['doc' => 'file|min_file_size:10'],
            ['doc' => $upload]
        );
    }

    public function testMinFileSizeFails(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', str_repeat('a', 5 * 1024), 'application/pdf');
        $this->assertEquivalentParity(
            ['doc' => 'file|min:10'],
            ['doc' => 'file|min_file_size:10'],
            ['doc' => $upload]
        );
    }

    public function testMaxFileSizePasses(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', str_repeat('a', 5 * 1024), 'application/pdf');
        $this->assertEquivalentParity(
            ['doc' => 'file|max:10'],
            ['doc' => 'file|max_file_size:10'],
            ['doc' => $upload]
        );
    }

    public function testMaxFileSizeFails(): void
    {
        $upload = $this->realUploadedFile('doc.pdf', str_repeat('a', 50 * 1024), 'application/pdf');
        $this->assertEquivalentParity(
            ['doc' => 'file|max:10'],
            ['doc' => 'file|max_file_size:10'],
            ['doc' => $upload]
        );
    }

    public function testDimensionsPasses(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 200, 100);
        $this->assertParity(
            ['photo' => 'dimensions:min_width=100,min_height=50,max_width=300,max_height=300'],
            ['photo' => $upload]
        );
    }

    public function testDimensionsFails(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 50, 50);
        $this->assertParity(
            ['photo' => 'dimensions:min_width=100,min_height=100'],
            ['photo' => $upload]
        );
    }

    public function testDimensionsRatioPasses(): void
    {
        $upload = UploadedFile::fake()->image('photo.jpg', 300, 200);
        $this->assertParity(['photo' => 'dimensions:ratio=3/2'], ['photo' => $upload]);
    }
}
